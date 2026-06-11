# Checkout Process — Architecture & Workflow

## Overview

This is a Laravel-based e-commerce checkout API. It handles order creation, stock reservation, payment processing via a mocked external gateway, and post-payment side-effects (email confirmation, loyalty points, warehouse notification, and audit logging). All side-effects after payment are decoupled from the HTTP response via queued listeners.

---

## System Boundaries

```
Client
  │
  ├── POST /api/checkout
  ├── GET  /api/orders/{id}
  └── POST /api/payments/callback  ← called by payment provider (webhook)
         │
         └── Laravel API (this service)
                │
                ├── External: Payment Gateway (HTTP POST)
                ├── External: Warehouse API (HTTP POST, queued)
                └── Internal: Queue workers (async listeners & jobs)
```

---

## Workflow

### 1. Checkout — `POST /api/checkout`

**Entry point:** `CheckoutController@store`

```
Request arrives
  │
  ├─ Validate: items[].product_id (int, exists), items[].quantity (int, min:1)
  │             user_id (int), discount_percent (0–100)
  │
  └─ CheckoutService::createOrder(userId, cartItems, discountPercent)
         │
         ├─ DB::transaction begins
         │     │
         │     ├─ StockService::reserve(items)
         │     │     └─ For each item:
         │     │           lockForUpdate() on Product row
         │     │           if stock_quantity < requested → throw InsufficientStockException
         │     │           decrement stock_quantity and save
         │     │
         │     ├─ Order::create(user_id, status=pending, total=0, discount_percent)
         │     │
         │     ├─ For each item:
         │     │     OrderItem::create(order_id, product_id, quantity, price=product.price)
         │     │
         │     ├─ order->calculateTotal()
         │     │     subtotal = sum(item.price × item.quantity)
         │     │     total = round(subtotal × (1 - discount/100), 2)
         │     │
         │     ├─ order->total = calculated total → save
         │     │
         │     └─ DB::transaction commits
         │
         └─ PaymentService::initiate(order)   ← outside the transaction
               │
               ├─ Generate provider_reference (PAY-XXXXX)
               ├─ POST to external payment gateway /api/sessions
               │     on HTTP failure → throw PaymentGatewayException
               ├─ Payment::create(order_id, amount, status=initiated, provider_reference)
               └─ Return { payment_id, provider_reference, redirect_url }

Response:
  201 { order_id, total, status: "pending", payment_url }
  409 { error: "Insufficient stock" }          ← InsufficientStockException
  500 { error: "..." }                         ← PaymentGatewayException
         └─ CheckoutService::handlePaymentFailure(order)
                 order->status = failed → save
                 StockService::release(items) → restore stock_quantity
```

> **Why HTTP call is outside the transaction:** holding a DB transaction open during an external HTTP request would keep row locks alive for the full gateway round-trip. Placing `PaymentService::initiate` after the commit releases locks immediately.

---

### 2. Retrieve Order — `GET /api/orders/{id}`

**Entry point:** `CheckoutController@show`

```
Request arrives with order id
  │
  └─ Order::with(['items.product', 'payments'])->findOrFail(id)
       └─ Return 200 JSON with order + eager-loaded relationships
```

---

### 3. Payment Webhook — `POST /api/payments/callback`

**Entry point:** `PaymentCallbackController@handle`

This endpoint is called by the external payment provider to report payment outcome. It always returns HTTP 200, even on error, so the provider does not keep retrying on transient failures.

```
Webhook arrives { provider_reference, status }
  │
  ├─ PaymentService::processCallback(providerReference, status)
  │     Find Payment by provider_reference
  │     │
  │     ├─ If Payment not found → return null (no-op)
  │     ├─ If Payment already finalized (success|failed) → return (idempotent)
  │     └─ Update payment->status = success | failed → save
  │
  ├─ If status == "success"
  │     CheckoutService::handlePaymentSuccess(order)
  │           order->status = paid → save
  │           OrderPaid::dispatch(order)   ← triggers async listeners
  │           Log::info(...)
  │
  └─ If status == "failed"
        CheckoutService::handlePaymentFailure(order)
              order->status = failed → save
              StockService::release(items) → restore stock
              Log::info(...)

Response: always 200 { message: "ok" }
```

---

### 4. Post-Payment Side Effects (Async — Queued Listeners)

After `OrderPaid` is dispatched, four listeners run asynchronously on queue workers. The HTTP webhook response is not blocked by any of them.

```
OrderPaid event
  │
  ├─ [Queue] SendOrderConfirmationEmail
  │     Sends OrderConfirmationMail to the order's user
  │     Catches and logs mailer exceptions (does not fail the queue job)
  │
  ├─ [Queue] AwardLoyaltyPoints
  │     LoyaltyPoint::create(user_id, order_id, points = floor(order->total))
  │     Idempotent: UniqueConstraintViolationException silently swallowed
  │
  ├─ [Queue] NotifyWarehouse
  │     POST to warehouse API { order_id, items: [{ sku, quantity }] }
  │     Retries: 5 attempts, backoff: [10, 30, 60, 120, 300] seconds
  │     On all retries exhausted:
  │           RecordPaymentAudit::dispatch(event='warehouse.failed', orderId)
  │
  └─ [Sync]  RecordPaymentAuditListener
        RecordPaymentAudit::dispatch(event='payment.success', orderId, paymentId)

[Queue] RecordPaymentAudit (Job)
  AuditLog::create(event, order_id, payment_id, metadata)
```

---

## Data Model

```
users
  └── orders (user_id)
        ├── order_items (order_id) ── products (product_id)
        ├── payments (order_id)
        └── loyalty_points (order_id, unique)

audit_logs (order_id nullable, payment_id nullable)
```

### Order Status Lifecycle

```
pending
  ├── paid      (payment webhook success)
  ├── failed    (payment gateway error on checkout, or webhook failure)
  └── cancelled (reserved for future use)
```

### Payment Status Lifecycle

```
initiated
  ├── success
  └── failed
```

---

## File Reference

### HTTP Layer

| File | Layer | Purpose |
|------|-------|---------|
| `app/Http/Controllers/Controller.php` | HTTP | Base controller class; empty Laravel scaffold |
| `app/Http/Controllers/CheckoutController.php` | HTTP | Handles `POST /api/checkout` and `GET /api/orders/{id}`; validates input, delegates to `CheckoutService`, returns HTTP responses |
| `app/Http/Controllers/PaymentCallbackController.php` | HTTP | Handles `POST /api/payments/callback`; delegates to `PaymentService` and `CheckoutService`; always returns 200 |

### Service Layer

| File | Layer | Purpose |
|------|-------|---------|
| `app/Services/CheckoutService.php` | Service | Orchestrates the checkout workflow: stock reservation, order and order-item creation, total calculation, and payment initiation inside a DB transaction; also handles payment success/failure state transitions |
| `app/Services/PaymentService.php` | Service | Integrates with the external payment gateway: creates payment sessions and processes webhook callbacks; manages `Payment` record lifecycle |
| `app/Services/StockService.php` | Service | Reserves and releases product stock using pessimistic row-level locking (`lockForUpdate`) to prevent race conditions |
| `app/Services/WarehouseClient.php` | Service / Infrastructure | Implements `WarehouseClientInterface`; sends order fulfillment notifications to the external warehouse API |

### Domain Models

| File | Layer | Purpose |
|------|-------|---------|
| `app/Models/User.php` | Model | Represents a buyer; standard Laravel user with authentication fields |
| `app/Models/Order.php` | Model | Represents a customer order; holds status, total, discount; owns `calculateTotal()` logic; relationships to `OrderItem` and `Payment` |
| `app/Models/OrderItem.php` | Model | Represents a single line item on an order; captures price at time of purchase |
| `app/Models/Payment.php` | Model | Represents a payment attempt; tracks gateway provider reference and status lifecycle |
| `app/Models/Product.php` | Model | Represents a purchasable product; holds price and stock quantity |
| `app/Models/LoyaltyPoint.php` | Model | Records loyalty points awarded to a user for a paid order; unique per order to enforce idempotency |
| `app/Models/AuditLog.php` | Model | Stores audit trail entries for payment events and warehouse failures; metadata stored as JSON |

### Events, Listeners & Jobs

| File | Layer | Purpose |
|------|-------|---------|
| `app/Events/OrderPaid.php` | Event | Dispatched when an order transitions to `paid`; carries the `Order` model; decouples checkout from all post-payment side-effects |
| `app/Listeners/SendOrderConfirmationEmail.php` | Listener (Queued) | Responds to `OrderPaid`; sends a confirmation email to the buyer |
| `app/Listeners/AwardLoyaltyPoints.php` | Listener (Queued) | Responds to `OrderPaid`; writes a loyalty-points record (`floor(total)` points); idempotent via unique constraint |
| `app/Listeners/NotifyWarehouse.php` | Listener (Queued) | Responds to `OrderPaid`; notifies the warehouse API with retry logic (5 attempts, exponential backoff); records failure in audit log if all retries exhausted |
| `app/Listeners/RecordPaymentAuditListener.php` | Listener (Sync) | Responds to `OrderPaid`; immediately dispatches the `RecordPaymentAudit` job to write a `payment.success` audit entry |
| `app/Jobs/RecordPaymentAudit.php` | Job (Queued) | Writes a single `AuditLog` record; used by both the audit listener and the warehouse-failure path |

### Mail

| File | Layer | Purpose |
|------|-------|---------|
| `app/Mail/OrderConfirmationMail.php` | Mail | Mailable class; wraps the `Order` model and renders the confirmation email template |
| `resources/views/emails/order-confirmation.blade.php` | View | Blade template for the order confirmation email; displays order ID, total, and itemized product list with SKU |

### Contracts & Providers

| File | Layer | Purpose |
|------|-------|---------|
| `app/Contracts/WarehouseClientInterface.php` | Contract | Interface for the warehouse notification dependency; enables substituting a test double without changing call-sites |
| `app/Providers/AppServiceProvider.php` | Provider | Binds `WarehouseClientInterface` → `WarehouseClient` in the service container; registers all `OrderPaid` event-to-listener mappings |

### Exceptions

| File | Layer | Purpose |
|------|-------|---------|
| `app/Exceptions/InsufficientStockException.php` | Exception | Thrown by `StockService` when requested quantity exceeds available stock; caught in `CheckoutController` to return 409 |
| `app/Exceptions/PaymentGatewayException.php` | Exception | Thrown by `PaymentService` when the external gateway HTTP call fails; triggers stock release and order failure |
| `app/Exceptions/WarehouseUnavailableException.php` | Exception | Thrown by `WarehouseClient` when the warehouse HTTP call fails; caught by `NotifyWarehouse` listener's retry mechanism |

### Database

| File | Layer | Purpose |
|------|-------|---------|
| `database/migrations/2025_01_01_000001_create_products_table.php` | Migration | Creates `products` table: id, name, sku (unique), price, stock_quantity |
| `database/migrations/2025_01_01_000002_create_orders_table.php` | Migration | Creates `orders` table: id, user_id, status, total, discount_percent, notes |
| `database/migrations/2025_01_01_000003_create_order_items_table.php` | Migration | Creates `order_items` table: id, order_id (FK cascade), product_id (FK), quantity, price |
| `database/migrations/2025_01_01_000004_create_payments_table.php` | Migration | Creates `payments` table: id, order_id (FK), amount, status, provider_reference (unique), provider |
| `database/migrations/2026_06_01_000005_create_loyalty_points_table.php` | Migration | Creates `loyalty_points` table: id, user_id, order_id (unique FK), points |
| `database/migrations/2026_06_01_000006_create_audit_logs_table.php` | Migration | Creates `audit_logs` table: id, event, order_id (nullable), payment_id (nullable), metadata (JSON) |
| `database/seeders/ProductSeeder.php` | Seeder | Seeds 6 sample products (T-shirt, jeans, sneakers, coat, socks, scarf) with realistic SKUs and stock levels |

### Routes

| File | Layer | Purpose |
|------|-------|---------|
| `routes/api.php` | Route | Declares all three API endpoints: `POST /checkout`, `GET /orders/{id}`, `POST /payments/callback` |
| `routes/web.php` | Route | Empty; this is an API-only project |
| `routes/console.php` | Route | Empty; no custom Artisan commands |

### Tests

| File | Layer | Purpose |
|------|-------|---------|
| `tests/Feature/CheckoutTest.php` | Test | Integration tests for the checkout and callback endpoints: stock depletion, gateway failure, idempotent callbacks, discount calculation, order retrieval (23 tests) |
| `tests/Feature/PostPaymentTest.php` | Test | Integration tests for all post-payment side-effects: email dispatch, loyalty points, warehouse notification payload and failure path, audit log entries, async listener verification |
| `tests/TestCase.php` | Test | Base test case; extends Laravel's `TestCase` |

### Infrastructure

| File | Layer | Purpose |
|------|-------|---------|
| `Dockerfile` | Infrastructure | Builds the PHP-FPM + Artisan serve image used by Docker Compose |
| `docker-compose.yml` | Infrastructure | Single-service compose file; exposes port 8000, mounts source directories live into the container |
| `phpunit.xml` | Infrastructure | PHPUnit configuration; sets `APP_ENV=testing`, uses SQLite in-memory database for tests |
