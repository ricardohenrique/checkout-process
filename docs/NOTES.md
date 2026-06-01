# Notes

## Part 1 — Bug Fixes

### Bug 1: Race condition in stock reservation

**Problem.** `StockService::reserve()` did a read-check-decrement without locking the product row. Two concurrent requests could both read the same `stock_quantity`, both pass the availability check, and both write their decremented value — selling more stock than available (oversell).

**Fix.** Added `lockForUpdate()` when fetching the product row inside `reserve()`. This acquires a pessimistic write lock for the duration of the enclosing `DB::transaction` in `CheckoutService`, so concurrent reservations are serialised at the database level.

**Test.** `test_stock_reservation_depletes_to_zero_and_blocks_further_orders` — reserves the full quantity of a product, asserts stock reaches 0, then verifies a subsequent order is rejected with 409.

---

### Bug 2: External HTTP call inside a database transaction

**Problem.** `CheckoutService::createOrder()` called `PaymentService::initiate()` — which makes an HTTP request to the payment gateway with a 5-second timeout — while holding an open database transaction. This keeps the DB connection locked for the full duration of the network call, exhausting the connection pool under concurrent load. It also creates a split-brain risk: if the gateway registers the payment session but a subsequent DB failure triggers a rollback, the gateway holds an active session with no corresponding record in the database.

**Fix.** Moved `paymentService->initiate()` outside the `DB::transaction`. The transaction now covers only the pure DB mutations (stock reservation, order and order-items creation, total calculation). If `initiate()` throws after the transaction commits, `handlePaymentFailure()` is called to mark the order as failed and release the reserved stock before re-throwing.

**Test.** `test_gateway_failure_releases_stock_and_marks_order_failed` — mocks `PaymentService` to throw, then asserts stock is restored and a `failed` order record exists.

---

### Bug 3: Order total not rounded to currency precision

**Problem.** `Order::calculateTotal()` returned a raw PHP float. For a product at €29.99 with a 15% discount, the result was `25.4915`. The database column (`decimal(12,2)`) rounds to `25.49` on save, but the in-memory value remained `25.4915`. This meant the amount forwarded to the payment gateway during the same request was `25.4915`, while the persisted order total was `25.49` — a mismatch between what the gateway was asked to charge and what the order record shows.

**Fix.** Added `return round($subtotal, 2)` to `calculateTotal()`.

**Test.** `test_payment_amount_sent_to_gateway_is_rounded` — inspects `Http::recorded()` and asserts the `amount` field in the gateway request body is `25.49`, not `25.4915`.

---

### Bug 4: Payment callback is not idempotent

**Problem.** `PaymentService::processCallback()` overwrote the payment status unconditionally. Payment providers routinely retry webhooks if a response is slow. A second `success` callback would re-trigger `handlePaymentSuccess()` on an already-paid order, firing post-payment side-effects (email, loyalty points, warehouse notification) a second time. A late `failed` callback after a `success` would downgrade the order back to `failed` and release the reserved stock on a paid order.

**Fix.** Added an early return in `processCallback()` when the payment is already in a terminal state (`success` or `failed`). Combined with the idempotency guard added in `handlePaymentSuccess()` (early return when `order->status === paid`), post-payment side-effects fire at most once per order.

**Tests.**
- `test_duplicate_success_callback_is_idempotent` — sends the same success callback twice; asserts the order remains paid and loyalty points are not awarded twice.
- `test_failed_callback_after_success_does_not_downgrade_order` — sends a success then a failed callback; asserts the order stays paid and stock is not released.

---

## Part 2 — Post-Payment Processing

### Architectural approach

The payment callback must respond in under 500ms, and each post-payment action (email, warehouse notification, loyalty points, audit log) should be isolated: a failure in one must not affect the others or the order's paid status.

The solution uses a **domain event + four independent queued listeners**:

1. `CheckoutService::handlePaymentSuccess()` marks the order as `paid` and fires an `OrderPaid` event.
2. Four queued listeners subscribe to the event: `SendOrderConfirmationEmail`, `NotifyWarehouse`, `AwardLoyaltyPoints`, and a closure that dispatches `RecordPaymentAudit`.
3. The webhook returns `{"status":"ok"}` immediately after the DB write and event dispatch — all side-effects run asynchronously on queue workers.

**Why this over the alternatives:**

- *One fat job* — simpler to wire, but a warehouse 503 would retry the email alongside it, coupling unrelated failure domains.
- *Inline + `afterResponse`* — keeps the webhook fast but runs in the same PHP process with no retry mechanism and no per-action failure isolation.

### Key design decisions

**Warehouse retry.** `NotifyWarehouse` is configured with `$tries = 5` and exponential backoff (`[10, 30, 60, 120, 300]` seconds). The warehouse API is documented as slow and occasionally unavailable, so retries are essential. On final failure, `failed()` dispatches `RecordPaymentAudit` with event type `warehouse.failed` so the failure is durable and observable.

**Idempotency.** Two layers:
1. `handlePaymentSuccess()` guards against duplicate calls (early return when already paid), so `OrderPaid` fires at most once per order.
2. `loyalty_points.order_id` has a `UNIQUE` constraint as database-level defense-in-depth. `AwardLoyaltyPoints` catches `UniqueConstraintViolationException` on retry rather than letting it propagate.

**WarehouseClientInterface.** The warehouse HTTP call sits behind an interface (`WarehouseClientInterface`) so it can be swapped in tests without faking URLs. This follows the project convention of depending on abstractions at architectural boundaries.

---

## Trade-offs and assumptions

- **Queue driver.** The implementation uses `QUEUE_CONNECTION=sync` (the project default), which runs listeners inline during the request. In production this would be changed to `database` or Redis with real workers. The sync driver is fine for tests and makes the test suite deterministic.
- **Email address.** The confirmation email is sent to `user_{id}@example.com` — a placeholder from the original codebase. A real implementation would load the user record and use their actual email.
- **Loyalty points calculation.** Points are awarded as `floor(order->total)` — 1 point per whole euro spent. The spec says "1 point per euro spent" without clarifying rounding; floor was chosen to be conservative.
- **No queue worker in Docker.** The Docker setup runs only `artisan serve`. With `QUEUE_CONNECTION=sync`, jobs run in the request, so the behaviour is correct for development and testing. Adding a worker process (`artisan queue:work`) would be the next step for a production setup.
- **Audit log on checkout failure.** The audit log currently only records `payment.success` and `warehouse.failed`. A production system would also log `payment.failed` and potentially `checkout.failed` events.

---

## What I would do differently with more time

- **Proper queue infrastructure.** Add a Redis service to the Docker Compose setup and run a dedicated `artisan queue:work` container so the async behaviour is exercised during manual testing, not just tests.
- **Webhook signature verification.** The payment callback currently accepts any payload with a valid `provider_reference`. A real system must verify an HMAC signature from the provider before processing.
- **User model and real email lookup.** Replace the `user_{id}@example.com` placeholder with a proper relationship to the `users` table.
- **Money value object.** Replace raw `float` arithmetic for prices and totals with a `Money` value object (or a library like `brick/money`) to make all currency operations exact and explicit.
- **Observability.** The `audit_logs` table is a good start, but structured logging with correlation IDs (tying a checkout request to its payment callback and subsequent jobs) would make debugging production incidents much faster.
- **Optimistic vs pessimistic locking.** The current `lockForUpdate` approach works but serialises all concurrent reservations for the same product. For high-throughput products, a conditional decrement (`UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?`) would allow more concurrency without a full row lock.
