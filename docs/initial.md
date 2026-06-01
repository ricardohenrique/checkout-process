# Checkout System — Technical Assessment

**Time limit: 2 hours**
**Stack: Laravel 11+/12+/13+, PHP 8.2+, MySQL/SQLite**

---

## Overview

You're looking at a simplified checkout system for an e-commerce platform. It handles:

- Creating orders from a shopping cart
- Processing payments via an external payment gateway (mocked)
- Managing product stock
- Sending order confirmation emails

The system works, but was built under time pressure and has several issues. Your job is to **find and fix bugs**, and **implement a new feature**.

---

## Setup

### Quick start (Docker)

The only prerequisite is Docker (with Compose). Everything else — PHP, Composer, the Laravel scaffold, SQLite, migrations, the seeded products — is set up for you inside the image.

```bash
docker compose up --build
```

- API: `http://localhost:8000` (try `POST /api/checkout`, `GET /api/orders/{id}`, `POST /api/payments/callback`)
- Tests: `docker compose exec app php artisan test`
- Tinker: `docker compose exec app php artisan tinker`

Edits under `app/`, `database/migrations`, `database/seeders`, `routes/`, `tests/`, `resources/`, plus `composer.json` and `phpunit.xml`, are live-mounted — no rebuild needed for code changes. Rebuild (`docker compose up --build`) only if you change `composer.json` and need new dependencies, or if you want a fresh database.

### Without Docker

If you'd rather run natively, you'll need PHP 8.2+ and Composer. Because this repository only contains the application slice (no `artisan`, `config/`, `public/`, `storage/`), you have to overlay it on a fresh Laravel scaffold:

<details>
<summary>Native setup steps</summary>

```bash
# 1. Create a fresh Laravel 11 project in a new directory
composer create-project laravel/laravel checkout-task
cd checkout-task

# 2. Copy this repository's files on top of it
#    (overwriting matching files — app/, database/, routes/, tests/, etc.)

# 3. Install dependencies
composer install

# 4. Configure environment
cp .env.example .env
php artisan key:generate

# 5. Set up SQLite database
touch database/database.sqlite

# 6. Run migrations and seed test data
php artisan migrate
php artisan db:seed --class=ProductSeeder

# 7. Run tests to see the current state
php artisan test
```
</details>

---

## Part 1: Bug Fixes (≈ 60 minutes)

Review the codebase and identify issues that could cause problems in production. For each bug you find:

1. **Describe the problem** — what could go wrong and under what conditions
2. **Fix it** — implement the correction
3. **Add or update a test** that demonstrates the fix

Focus on the areas that matter most in a real checkout system: data consistency, correctness, and reliability.

**Hint:** There are at least 3 significant bugs

---

## Part 2: New Feature — Post-Payment Processing (≈ 60 minutes)

After a payment succeeds, the system currently just updates the order status and attempts to send an email. We need to expand this to handle several post-payment actions:

1. **Send confirmation email** to the customer (already exists, but could be improved)
2. **Notify the warehouse** — call an external warehouse API to begin fulfillment
    - Endpoint: `POST https://warehouse.example.com/api/fulfillment`
    - Body: `{"order_id": <id>, "items": [{"sku": "<sku>", "quantity": <qty>}]}`
    - This API is **slow** (2–3 second response time) and **occasionally unavailable**
3. **Update loyalty points** — award 1 point per euro spent, saved to a `loyalty_points` table
4. **Create an audit log entry** — record the payment event with timestamp and relevant details

### Requirements

- The **payment callback webhook** must respond quickly (< 500ms) — the payment provider will timeout and retry if we're slow
- If any of the post-payment actions fail, it **must not** affect the order's paid status
- The warehouse notification failure should be retried, but email and loyalty points should not block each other
- Add appropriate tests for your implementation

### What We're Looking For

- How you structure the solution architecturally
- How you handle failure scenarios
- Code quality, integrity and readability
- Test coverage for the important paths

---

## Deliverables

Please submit:

1. Your modified codebase (i.e., zip)
2. A brief `NOTES.md` file explaining:
    - What bugs you found and how you fixed them
    - Your architectural approach for Part 2 and why you chose it
    - Any trade-offs or assumptions you made
    - What you would do differently with more time

---

Good luck!
