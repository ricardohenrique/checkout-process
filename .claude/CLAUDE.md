# Project

Laravel 11+/12+/13+, PHP 8.2+, MySQL/SQLite

E-commerce checkout system: order creation, payment processing (mocked gateway), stock management, and order confirmation emails.

## Structure

```
app/
  Exceptions/          # Domain exceptions (InsufficientStockException)
  Http/Controllers/    # CheckoutController, PaymentCallbackController
  Mail/                # OrderConfirmationMail
  Models/              # Order, OrderItem, Payment, Product, User
  Providers/           # AppServiceProvider
  Services/            # CheckoutService, PaymentService, StockService
database/
  migrations/          # products, orders, order_items, payments
  seeders/             # ProductSeeder
routes/
  api.php              # POST /api/checkout, GET /api/orders/{id}, POST /api/payments/callback
tests/
  Feature/             # CheckoutTest (integration tests with RefreshDatabase)
```

## Key domain concepts

- **CheckoutService** — orchestrates order creation, stock reservation, and payment initiation inside a DB transaction
- **PaymentService** — calls external payment gateway to create sessions; processes webhook callbacks
- **StockService** — reserves and releases stock; throws `InsufficientStockException` on shortfall
- **Order statuses**: `pending` → `paid` | `failed` | `cancelled`
- **Payment statuses**: `initiated` → `success` | `failed`

## Build & run

```bash
docker compose up --build
```

API available at `http://localhost:8000`. Live-mounted: `app/`, `database/`, `routes/`, `tests/`, `resources/`.

## Tests

```bash
docker compose exec app php artisan test
```

Tests use `RefreshDatabase` + `Http::fake()` to mock the payment gateway.

## Conventions

- Follow SOLID principles. Prefer small, focused classes with a single responsibility.
- Depend on abstractions, not implementations. Use interfaces at architectural boundaries.
- Keep business logic independent of framework, database, and infrastructure concerns.
- Write code for readability first.
- Keep methods small and expressive; favor clarity over cleverness.
- DRY, but do not abstract prematurely.
- Use named domain exceptions (never raw `\Exception`) for business rule violations.
- All payment and stock mutations that must be atomic go inside a `DB::transaction`.
