# Post-Payment Processing Plan

## 1. Approach & Rationale

When a payment callback is confirmed, `PaymentService::processCallback()` fires an `OrderPaid` domain event on the state transition. Four queued listeners subscribe to that event and handle downstream concerns independently: sending the confirmation email, notifying the warehouse, awarding loyalty points, and writing an audit log. This approach keeps the HTTP response fast (the webhook returns immediately after persisting the state change), isolates failures (a warehouse timeout does not roll back the loyalty award), and leverages Laravel's built-in queue retry machinery so transient failures are handled without bespoke logic.

**Alternative rejected — one fat job**: Dispatching a single `ProcessPostPayment` job that runs all four steps sequentially is simpler to wire but means any single failure retries all steps, making idempotency much harder to guarantee and coupling unrelated concerns.

**Alternative rejected — inline + `afterResponse`**: Running the logic synchronously after sending the HTTP response avoids a queue worker dependency but still blocks the dyno, shares the same PHP process memory, and provides no retry mechanism for failures.

---

## 2. New File Structure

| File | Description |
|---|---|
| `app/Events/OrderPaid.php` | Domain event carrying the `Order` model; fired on payment state transition |
| `app/Listeners/SendOrderConfirmationEmail.php` | Queued listener; sends the confirmation email to the customer |
| `app/Listeners/NotifyWarehouse.php` | Queued listener; calls the warehouse HTTP API via `WarehouseClientInterface`; retries on failure |
| `app/Listeners/AwardLoyaltyPoints.php` | Queued listener; inserts a `loyalty_points` row for the order |
| `app/Listeners/RecordPaymentAudit.php` | Queued listener; inserts an `audit_logs` row for a given event type |
| `app/Contracts/WarehouseClientInterface.php` | Interface defining the warehouse notification contract |
| `app/Services/WarehouseClient.php` | Concrete implementation using `Http::` client; bound in the container |
| `app/Models/LoyaltyPoint.php` | Eloquent model for the `loyalty_points` table |
| `app/Models/AuditLog.php` | Eloquent model for the `audit_logs` table |
| `database/migrations/YYYY_MM_DD_create_loyalty_points_table.php` | Creates `loyalty_points` with a unique constraint on `order_id` |
| `database/migrations/YYYY_MM_DD_create_audit_logs_table.php` | Creates `audit_logs` with nullable indexed foreign keys and a JSON `metadata` column |

---

## 3. Modified Existing Files

### `app/Services/PaymentService.php`
Fire `OrderPaid::dispatch($order)` immediately after the order status is transitioned to `paid`. The existing early-return idempotency guard (which returns without acting when the order is already paid) means the event is fired at most once per order. No other changes required.

### `app/Http/Controllers/PaymentCallbackController.php`
Remove the inline `Mail::send(...)` call. That responsibility moves to `SendOrderConfirmationEmail`. The controller delegates to `PaymentService::processCallback()` and returns the response; it should contain no mail logic after this change.

### `app/Providers/AppServiceProvider.php`
Register the four listeners against `OrderPaid` in the `$listen` array (or equivalent `Event::listen` calls in `boot()`). Bind `WarehouseClientInterface::class` to `WarehouseClient::class` in the container so the concrete implementation is resolved via dependency injection.

---

## 4. Database Migrations

### `loyalty_points`

| Column | Type | Notes |
|---|---|---|
| `id` | `bigIncrements` | Primary key |
| `user_id` | `unsignedBigInteger` | Indexed |
| `order_id` | `foreignId` | Unique — idempotency guard; prevents double-awarding |
| `points` | `unsignedInteger` | Points awarded for the order |
| `created_at` / `updated_at` | `timestamps` | |

The unique constraint on `order_id` is defense-in-depth: even if `AwardLoyaltyPoints` were queued twice for the same order, the second insert would fail at the database level rather than silently double-award points.

### `audit_logs`

| Column | Type | Notes |
|---|---|---|
| `id` | `bigIncrements` | Primary key |
| `event` | `string` | Event type, e.g. `payment.success`, `warehouse.failed` |
| `order_id` | `unsignedBigInteger` | Nullable, indexed |
| `payment_id` | `unsignedBigInteger` | Nullable, indexed |
| `metadata` | `json` | Nullable; arbitrary context for the event |
| `created_at` / `updated_at` | `timestamps` | |

`order_id` and `payment_id` are nullable because audit events may not always have both identifiers (e.g. a pre-order failure has no order yet).

---

## 5. Class Responsibilities

### `SendOrderConfirmationEmail`

- **What it does**: Resolves the customer's email from `$event->order->user`, builds the confirmation mailable, and dispatches it via `Mail::to(...)->send(...)`.
- **Queue / retry**: Default queue, default retry count (3).
- **Must not**: Touch order state, award points, or call any external HTTP service.

### `NotifyWarehouse`

- **What it does**: Calls `WarehouseClientInterface::notify($order)` to push the order to the warehouse fulfillment system.
- **Queue / retry**: `$tries = 5`, `$backoff = [10, 30, 60, 120, 300]` (seconds), `$timeout = 10` (seconds). On final failure (after all retries exhausted), the `failed()` method dispatches `RecordPaymentAudit` with event type `warehouse.failed` and the order context in `metadata`.
- **Must not**: Modify order status, send emails, or award loyalty points.

### `AwardLoyaltyPoints`

- **What it does**: Calculates the points for the order amount and inserts a row into `loyalty_points`. The unique constraint on `order_id` ensures idempotency.
- **Queue / retry**: Default queue, default retry count. A duplicate-key exception on retry is caught and swallowed (the row already exists, the goal is achieved).
- **Must not**: Send notifications, contact external services, or alter the order record.

### `RecordPaymentAudit`

- **What it does**: Inserts a row into `audit_logs` with the provided event type, order/payment IDs, and optional metadata array.
- **Queue / retry**: Default queue, default retry count. Accepts the event type and context as constructor arguments so it can be dispatched from other listeners (e.g. `NotifyWarehouse::failed()`).
- **Must not**: Perform any side effects beyond writing the audit row.

---

## 6. Data Flow

**Synchronous (within the HTTP request):**

1. Webhook POST arrives at `PaymentCallbackController`.
2. Controller calls `PaymentService::processCallback($payload)`.
3. `PaymentService` validates the payload signature.
4. Idempotency check: if the order is already `paid`, return early — no event fired.
5. Order status is transitioned to `paid` and persisted.
6. `OrderPaid::dispatch($order)` is called — Laravel resolves the registered listeners and pushes them onto the queue.
7. `PaymentService` returns; controller returns an HTTP 200 response to the payment provider.

**Asynchronous (on queue workers):**

8. `SendOrderConfirmationEmail` dequeues, sends the customer email.
9. `AwardLoyaltyPoints` dequeues, writes the `loyalty_points` row (unique constraint guards against duplicates).
10. `RecordPaymentAudit` dequeues, writes an `audit_logs` row with event `payment.success`.
11. `NotifyWarehouse` dequeues, calls the warehouse API. On success, done. On failure, retries up to 5 times with exponential backoff. If all retries fail, `failed()` dispatches another `RecordPaymentAudit` job with event `warehouse.failed`.

Steps 8–11 are independent; their queue order relative to each other is not guaranteed and they must not depend on one another.

---

## 7. Implementation Steps

### Step 1 — Migrations

**What to build**: Create migration files for `loyalty_points` and `audit_logs` as described in Section 4.

**Acceptance criteria**: `php artisan migrate` runs without error; both tables exist with the correct columns, indexes, and constraints.

**Test requirement**: Schema assertions using `assertDatabaseHas` / `Schema::hasTable` in a migration test, or rely on subsequent model tests which will fail if the table structure is wrong.

---

### Step 2 — Models

**What to build**: `LoyaltyPoint` and `AuditLog` Eloquent models with `$fillable` properties matching their respective tables.

**Acceptance criteria**: Models can be instantiated and persisted; `LoyaltyPoint::create([...])` with a duplicate `order_id` throws a unique constraint violation.

**Test requirement**: Unit test asserting that a duplicate `order_id` on `LoyaltyPoint` raises an integrity exception.

---

### Step 3 — `WarehouseClientInterface` + `WarehouseClient`

**What to build**: Define `WarehouseClientInterface` with a single `notify(Order $order): void` method. Implement it in `WarehouseClient` using `Http::post(...)` to the configured warehouse endpoint.

**Acceptance criteria**: `WarehouseClient` is bound to `WarehouseClientInterface` in the container; `Http::fake()` can intercept calls in tests without any special setup in the class itself.

**Test requirement**: Unit test using `Http::fake()` to assert the correct URL and payload shape are sent when `notify()` is called.

---

### Step 4 — `OrderPaid` Event

**What to build**: A plain event class with a public `Order $order` constructor property.

**Acceptance criteria**: The class is instantiable; `$event->order` returns the passed model.

**Test requirement**: Covered implicitly by listener tests; no isolated test required unless the event carries computed data.

---

### Step 5 — `RecordPaymentAudit` Listener

**What to build**: Queued listener (and/or dispatchable job) accepting event type, nullable order ID, nullable payment ID, and optional metadata array. Writes to `audit_logs`.

**Acceptance criteria**: Dispatching the listener results in an `audit_logs` row with the correct `event` value and metadata.

**Test requirement**: Test with `QUEUE_CONNECTION=sync`; assert the `audit_logs` row exists after dispatch.

---

### Step 6 — `AwardLoyaltyPoints` Listener

**What to build**: Queued listener that reads points from `$event->order`, inserts into `loyalty_points`, and swallows duplicate-key exceptions.

**Acceptance criteria**: A `loyalty_points` row is created for the order; dispatching twice for the same order does not create a second row.

**Test requirement**: Two tests — one asserting the row is created on first dispatch; one asserting no exception and no duplicate row on second dispatch.

---

### Step 7 — `SendOrderConfirmationEmail` Listener

**What to build**: Queued listener that sends the confirmation mailable. Move the logic currently inlined in `PaymentCallbackController`.

**Acceptance criteria**: The mailable is sent to the correct address; no mail logic remains in the controller.

**Test requirement**: Test using `Mail::fake()`; assert `Mail::assertSent(OrderConfirmationMail::class)` with the correct recipient.

---

### Step 8 — `NotifyWarehouse` Listener

**What to build**: Queued listener with `$tries = 5`, `$backoff = [10, 30, 60, 120, 300]`, `$timeout = 10`. Calls `WarehouseClientInterface::notify($order)`. Implements `failed()` to dispatch `RecordPaymentAudit` with event `warehouse.failed`.

**Acceptance criteria**: On success, no audit record for `warehouse.failed` is created. On `failed()` invocation, an `audit_logs` row with event `warehouse.failed` is written.

**Test requirement**: Two tests — one using `Http::fake()` for a 200 response asserting the HTTP call was made; one calling `failed()` directly and asserting the audit row exists.

---

### Step 9 — Wire in `AppServiceProvider`

**What to build**: Register all four listeners against `OrderPaid` in `AppServiceProvider`. Bind `WarehouseClientInterface::class` to `WarehouseClient::class`.

**Acceptance criteria**: `Event::getListeners(OrderPaid::class)` returns all four listeners; `app(WarehouseClientInterface::class)` resolves to an instance of `WarehouseClient`.

**Test requirement**: Existing webhook test with `Event::fake()` asserts `OrderPaid` is dispatched; a container binding test asserts the interface resolves correctly.

---

### Step 10 — Update `PaymentService` and `PaymentCallbackController`

**What to build**: Fire `OrderPaid::dispatch($order)` in `PaymentService::processCallback()` on the state transition. Remove inline `Mail::send(...)` from `PaymentCallbackController`.

**Acceptance criteria**: Webhook test with `Event::fake()` asserts `OrderPaid` is dispatched exactly once for a new payment and zero times when the order is already paid. No `Mail` facade call remains in the controller.

**Test requirement**: One test asserting event dispatch on success; one asserting no dispatch on duplicate callback (idempotency); confirm existing controller tests still pass without `Mail::fake()` being required in them.

---

## 8. Testing Strategy

- **Webhook handler tests**: Use `Event::fake()` to assert `OrderPaid` is dispatched on a valid new callback. Use `Queue::fake()` (with `QUEUE_CONNECTION=sync` disabled for these specific tests) when you want to verify that listeners are queued without executing them inline.
- **Listener tests**: Run with `QUEUE_CONNECTION=sync` (already the project default) so listeners execute inline. Use `Mail::fake()` in `SendOrderConfirmationEmail` tests to assert the correct mailable is sent. Use `Http::fake()` in `NotifyWarehouse` tests to assert the warehouse endpoint is called with the correct payload.
- **Loyalty points**: Assert a `loyalty_points` row exists in the database with the correct `user_id`, `order_id`, and `points` values after `AwardLoyaltyPoints` runs.
- **Audit log**: Assert an `audit_logs` row exists with the correct `event` string after `RecordPaymentAudit` runs.
- **Idempotency**: Assert that firing `OrderPaid` twice (or calling `AwardLoyaltyPoints` twice) for the same order results in exactly one `loyalty_points` row and does not throw an unhandled exception.
- **Warehouse failure path**: Call `NotifyWarehouse::failed()` directly in a test and assert an `audit_logs` row with event `warehouse.failed` is created.
- **No log assertions**: Do not assert on log output; assert on observable state (database rows, sent mail, HTTP calls).
