# Checkout System

A simplified e-commerce checkout system built with Laravel 13 / PHP 8.2+. Handles order creation, payment processing via a mocked external gateway, stock management, and post-payment processing.

## Stack

- **Runtime:** PHP 8.5, Laravel 13
- **Database:** SQLite (dev/test), MySQL-compatible
- **Queue:** sync (dev/test), database/Redis (production)
- **Container:** Docker + Docker Compose

## Quick start

```bash
docker compose up --build
```

- API: `http://localhost:8000`
- Tests: `docker compose exec app php artisan test`
- Tinker: `docker compose exec app php artisan tinker`

## API endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/checkout` | Create an order and initiate payment |
| `GET` | `/api/orders/{id}` | Retrieve order details with items and payments |
| `POST` | `/api/payments/callback` | Payment provider webhook |

### POST /api/checkout

```json
{
  "items": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 5, "quantity": 1 }
  ],
  "discount_percent": 10
}
```

### POST /api/payments/callback

```json
{
  "provider_reference": "PAY-XXXX",
  "status": "success"
}
```

## Architecture

```
app/
  Contracts/           WarehouseClientInterface
  Events/              OrderPaid
  Exceptions/          InsufficientStockException
  Http/Controllers/    CheckoutController, PaymentCallbackController
  Jobs/                RecordPaymentAudit
  Listeners/           SendOrderConfirmationEmail, NotifyWarehouse,
                       AwardLoyaltyPoints
  Mail/                OrderConfirmationMail
  Models/              Order, OrderItem, Payment, Product, LoyaltyPoint, AuditLog
  Providers/           AppServiceProvider (event/listener wiring, DI bindings)
  Services/            CheckoutService, PaymentService, StockService, WarehouseClient
```

**Core flow:**
1. `POST /api/checkout` → `CheckoutService` reserves stock, creates order + items, initiates payment (outside DB transaction).
2. `POST /api/payments/callback` → `PaymentService` updates payment status; `CheckoutService::handlePaymentSuccess` marks order paid and fires `OrderPaid`.
3. `OrderPaid` → four queued listeners run asynchronously: email, warehouse notification (with retry), loyalty points, audit log.

## Post-payment processing (Part 2)

After a payment succeeds the following actions run asynchronously via queued listeners:

| Action | Class | Retry |
|--------|-------|-------|
| Send confirmation email | `SendOrderConfirmationEmail` | 3× (default) |
| Notify warehouse | `NotifyWarehouse` | 5×, exponential backoff |
| Award loyalty points | `AwardLoyaltyPoints` | 3× (default), idempotent |
| Write audit log | `RecordPaymentAudit` (job) | 3× (default) |

Failures in any listener do not affect the order's paid status. Warehouse failures after all retries are exhausted are recorded in `audit_logs` with event type `warehouse.failed`.

## Running tests

```bash
docker compose exec app php artisan test
```

23 tests, 49 assertions. All tests use an in-memory SQLite database (`RefreshDatabase`) and `Http::fake()` to intercept external HTTP calls — no real network access required.

## Notes

See [`docs/NOTES.md`](docs/NOTES.md) for a detailed write-up of the bugs found, the architectural decisions for Part 2, trade-offs, and what would be done differently with more time.
