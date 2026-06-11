# Interview Preparation — Checkout Process Deep Dive

This document prepares you for a technical deep-dive on the checkout-process assessment. It covers the four specific topics flagged in the feedback plus the broader questions a senior engineer is likely to probe.

---

## The Four Flagged Topics

### 1. "The PaymentCallbackController still returns 200 when processing fails. You flagged this in your review doc but didn't fix it. Walk me through your reasoning."

This is a deliberate design decision, not an oversight. Returning 200 unconditionally is the correct contract for a payment webhook endpoint.

**The core problem with returning 4xx/5xx to a payment provider:**

Payment providers retry webhooks on non-200 responses. If your server has a transient bug — say, a DB deadlock or a momentary timeout — the provider will keep retrying. That is exactly what you want for a transient error. But the retry behavior is controlled by the *provider*, not by your HTTP status code alone. If you return a 500 for a permanent condition (e.g., `provider_reference` not found because it belongs to a different system), the provider will keep retrying forever, flooding your endpoint with useless requests.

More critically: returning 4xx/5xx does not give the provider actionable information. They cannot distinguish "your server is down" from "this reference is invalid" from "we already processed this." The semantic value of the status code is effectively zero from the provider's perspective — they only use it to decide whether to retry.

**What the code actually does:**

```
catch (\Exception $e) {
    Log::error('Payment callback processing failed', [...]);
    return response()->json(['status' => 'ok']);
}
```

The failure is logged with the `provider_reference` and the exception message. The order state is preserved — if `processCallback` failed before updating the payment status, the payment remains in `initiated` and can be reconciled. If the failure happened after the DB write but before `handlePaymentSuccess`, a retry from the provider will hit the idempotency guard and no-op cleanly.

**What I would add with more time:**

A reconciliation job that polls for orders stuck in `pending`/`initiated` beyond a configurable TTL and queries the gateway's status API. That is the real safety net — not an HTTP 500 that triggers a provider retry with no coordination on your side.

**The honest trade-off to acknowledge:**

The current code swallows all exceptions equally. A more nuanced implementation would distinguish between permanent errors (unknown `provider_reference` → log and 200, never retry) and transient errors (DB connection lost → consider 503 to trigger a retry). That distinction requires knowing the provider's retry semantics, which are not in scope for this assessment.

---

### 2. "Why did you choose lockForUpdate over an atomic decrement for the stock reservation?"

Both approaches prevent overselling. The choice is about what you need *in addition* to preventing the race condition.

**What `lockForUpdate` does:**

```php
$product = Product::lockForUpdate()->findOrFail($item['product_id']);
if ($product->stock_quantity < $item['quantity']) {
    throw new InsufficientStockException(...);
}
$product->stock_quantity -= $item['quantity'];
$product->save();
```

It issues `SELECT ... FOR UPDATE`, which acquires a row-level exclusive lock for the duration of the enclosing `DB::transaction`. Any concurrent transaction trying to lock the same row blocks until this one commits or rolls back. This serialises concurrent reservations for the same product.

**What an atomic decrement does:**

```sql
UPDATE products
SET stock_quantity = stock_quantity - ?
WHERE id = ? AND stock_quantity >= ?
```

This is a single atomic write. No read-check-write race condition. The affected rows count tells you whether the condition passed (1 row updated) or failed (0 rows). It allows higher concurrency because no lock is held between the read and the write.

**Why `lockForUpdate` was the right choice here:**

The reservation happens inside a `DB::transaction` that also creates the `Order` and `OrderItem` records. These three writes must be atomic — if the `Order::create` fails, the stock must be released. Eloquent's `increment` (atomic decrement) operates outside Laravel's transaction awareness when called standalone. By using `lockForUpdate` inside the transaction, the lock is held until the entire transaction (stock decrement + order creation + order items) commits or rolls back, guaranteeing no partial state.

An atomic decrement would work for stock isolation in isolation, but you would still need a mechanism to roll back the stock if the subsequent order creation fails. That typically means catching exceptions and issuing a compensating `stock_quantity + ?` update — which is more complex and introduces its own failure modes (what if the compensating update fails?).

**When you would prefer the atomic decrement:**

For a high-throughput product (flash sale, limited-edition drop) where hundreds of concurrent requests are expected, `lockForUpdate` serialises all of them through a single queue. The atomic decrement approach allows them to race and resolve without a lock, then fail gracefully if stock ran out. In my NOTES.md I flagged this as a future improvement for exactly that scenario.

**The honest limitation of the current approach:**

Multi-item carts with `lockForUpdate` are susceptible to deadlocks if two concurrent requests lock items in different orders. A production system should sort items by `product_id` before iterating to guarantee a consistent lock acquisition order.

---

### 3. "Walk me through what happens if the AwardLoyaltyPoints listener fails after the email was already sent."

This is a question about partial failure in an event-driven system with independent queued listeners.

**The architecture:**

`OrderPaid` dispatches four independent queued listeners. Each runs as its own queue job — they do not share a transaction, a process, or a failure domain. A failure in one has no effect on the others.

**The specific scenario:**

1. `SendOrderConfirmationEmail` runs and succeeds — email is in the mail provider's queue.
2. `AwardLoyaltyPoints` runs and fails — say, the database connection drops mid-write.
3. The queue driver marks the `AwardLoyaltyPoints` job as failed.
4. `NotifyWarehouse` and `RecordPaymentAuditListener` run independently — unaffected.

**What Laravel does on failure:**

Because `AwardLoyaltyPoints` implements `ShouldQueue` and uses `InteractsWithQueue`, Laravel will retry the job according to the `$tries` property (default: 1 unless configured). If retries are exhausted, the job is written to the `failed_jobs` table. The order remains `paid`. The email is already delivered. The loyalty points are not awarded.

**Is this a problem?**

From a business perspective: the customer paid but did not receive their points. This is a user-trust issue but not a data-consistency issue — the order is correct, the payment is recorded, the email was sent.

**How the idempotency guard helps:**

The `loyalty_points.order_id` column has a `UNIQUE` constraint. If the job is retried (either by the queue or manually via `artisan queue:retry`), the `LoyaltyPoint::create` call will hit the constraint if points were already awarded in a partial run. The `UniqueConstraintViolationException` is caught and swallowed, so the retry succeeds cleanly without double-awarding.

**What a production system would add:**

1. A `$tries` value greater than 1 on `AwardLoyaltyPoints` (same pattern as `NotifyWarehouse`).
2. A `failed()` method that writes to `audit_logs` with `event = loyalty.failed` so operations can query failed loyalty awards and reprocess them.
3. An alerting rule on `failed_jobs` where `payload` contains `AwardLoyaltyPoints`.

**The key point to land:**

Independent queued listeners give you failure isolation — a broken mail provider cannot block loyalty points, and a broken loyalty calculation cannot block the warehouse. The cost is that partial failure is possible. You mitigate it with retries, idempotency, and observability — not by coupling the listeners back together.

---

### 4. "You mentioned brick/money in your notes. What specific problems would that solve beyond round()?"

`round()` fixes the immediate bug (mismatch between the gateway amount and the stored total), but it does not fix the underlying problem: floating-point arithmetic is not safe for money.

**The floating-point problem:**

PHP floats are IEEE 754 double-precision. They cannot represent most decimal fractions exactly.

```php
var_dump(0.1 + 0.2);  // float(0.30000000000000002)
var_dump(0.1 + 0.2 == 0.3);  // bool(false)
```

For most individual calculations, the error is small enough that `round()` corrects it. But errors accumulate. In a system that applies discounts, splits amounts across multiple payment attempts, calculates taxes on line items, and aggregates totals for reporting, each `round()` introduces a rounding difference. Those differences add up.

**What `brick/money` provides:**

`brick/money` uses `brick/math` under the hood, which does arbitrary-precision decimal arithmetic via PHP's `BCMath` extension. No floating-point representation at any step.

```php
use Brick\Money\Money;
use Brick\Math\RoundingMode;

$price    = Money::of('29.99', 'EUR');
$discount = $price->multipliedBy('0.85', RoundingMode::HALF_UP);
// $discount is exactly EUR 25.49 — no floating-point involved
```

Specific problems it solves that `round()` does not:

**1. Rounding mode correctness.**
`round()` uses PHP's default rounding (HALF_AWAY_FROM_ZERO). Financial systems often require HALF_EVEN (banker's rounding) to avoid systematic bias when aggregating many rounded values. `brick/money` lets you specify the rounding mode explicitly per operation.

**2. Currency mismatch prevention.**
If you accidentally add a price in EUR to an amount in USD, `brick/money` throws a `CurrencyMismatchException` at runtime. Raw floats have no such guard.

**3. Allocation without remainder loss.**
Splitting an amount across multiple recipients (e.g., tax split, installments) with floats loses or gains pennies. `brick/money` has `allocate()` which distributes the remainder deterministically:

```php
$total = Money::of('10.00', 'EUR');
[$first, $second, $third] = $total->allocate([1, 1, 1]);
// EUR 3.34, EUR 3.33, EUR 3.33 — exactly EUR 10.00
```

**4. Type safety and intent.**
A `Money` object carries both the amount and the currency as an explicit domain type. You cannot accidentally pass a raw float where a money value is expected, and you cannot perform arithmetic between incompatible currencies.

**5. Persistence contract.**
`brick/money` stores amounts as integers (minor units — cents) or as `BCMath` decimal strings. Either is exact. When you store `float` in MySQL's `DECIMAL(12,2)`, MySQL does the rounding at write time — but the in-memory value before save may still carry floating-point error, which is exactly the bug that was filed.

**The migration path:**

Replace `decimal(12,2)` columns with `bigint` (store in minor units, e.g., cents) or keep `decimal` but use `brick/money` for all arithmetic and convert only at the persistence boundary. The latter is the lower-risk change because it does not require a schema migration.

---

## Other Likely Deep-Dive Questions

### "Walk me through what happens if the database goes down between the transaction commit and the PaymentService::initiate() call."

The transaction has committed. Stock is reserved. The order is `pending`. No payment record exists yet. If the process dies here, the user gets an error and no `payment_url`. The order stays in `pending` with stock reserved but no payment initiated.

Without a reconciliation mechanism, this order is stuck. A production system needs a background job that finds `pending` orders older than N minutes with no associated `payments` record and either retries initiation or cancels and releases stock.

---

### "Why is RecordPaymentAuditListener synchronous while the others are queued?"

It does not do I/O itself — it dispatches a job. The dispatch is a fast in-process operation (a DB insert into the queue table or a Redis push). The actual `AuditLog::create` happens in the `RecordPaymentAudit` job, which is queued. Making the listener synchronous means the job is guaranteed to be enqueued before the webhook returns 200. If the dispatch were queued and the process died, the audit entry would be silently lost. Making the enqueue synchronous makes the audit log robust without blocking the response on the actual DB write.

---

### "Why does handlePaymentSuccess guard against duplicate calls, but handlePaymentFailure does not?"

`handlePaymentSuccess` is idempotent by guard:

```php
if ($order->status === Order::STATUS_PAID) {
    return;
}
```

`handlePaymentFailure` does not have the equivalent guard. Calling it twice on an already-failed order is safe in the current implementation because:
- Setting `status = failed` on an already-failed order is a no-op.
- `StockService::release` increments stock — calling it twice would double-restore stock, which is a bug.

In practice, `handlePaymentFailure` is only called from two places: inside the `catch` block in `createOrder` (which only fires once) and from the callback controller (protected by `processCallback`'s idempotency guard on the `Payment` status). But the lack of an explicit guard is a latent risk — a good follow-up fix would be to add the same `STATUS_FAILED` early return.

---

### "What would you change about the testing approach?"

The test suite uses `RefreshDatabase` with SQLite in-memory, which is fast but has a subtle risk: SQLite does not enforce foreign key constraints by default, and its locking semantics are different from MySQL's. A test that relies on `lockForUpdate` working correctly is actually not testing anything in SQLite — `FOR UPDATE` is silently ignored. The concurrency tests pass because the test is sequential, not because the lock is working.

For the stock-reservation race condition specifically, a proper concurrency test requires either running two parallel processes against a real MySQL instance, or using a test that verifies the SQL emitted (via `DB::listen`) rather than the concurrent outcome.

---

### "Why not use a saga or two-phase commit for the checkout flow?"

A two-phase commit between the application DB and the payment gateway is not feasible — the gateway does not participate in distributed transactions. A saga (sequence of local transactions with compensating actions) is effectively what the current code implements:

1. Reserve stock (local transaction).
2. Initiate payment (external call).
3. On failure: release stock (compensating action).

The "saga" terminology is not used in the code, but the pattern is there. Making it more explicit — for example, with a state machine that tracks which compensating actions have run — would be valuable in a system with more steps or more failure modes.

---

### "How would you handle a partial order — e.g., 3 out of 5 items are in stock?"

The current implementation fails the entire order on any `InsufficientStockException`. This is the correct conservative default: the customer sees a clear 409 and can adjust their cart. The alternatives are:

- **Partial fulfillment**: reserve what is available, create a partial order, and back-order the rest. Requires a more complex order model with line-item statuses.
- **Pre-validation before locking**: check availability without locking, return which items are unavailable before entering the transaction. Reduces user friction but introduces a TOCTOU window (stock could change between check and lock).

For this assessment, fail-fast with a clear error is the correct choice. Partial fulfillment is a business requirement that would need explicit product decisions before implementing.

---

### "The warehouse retry has exponential backoff. Why those specific values — 10, 30, 60, 120, 300?"

The backoff is designed around a realistic warehouse API availability window. The values `[10, 30, 60, 120, 300]` seconds give:

- Attempt 1 immediately
- Attempt 2 at +10s (catches brief transient errors)
- Attempt 3 at +40s (catches short restarts)
- Attempt 4 at +100s (~1.5 min — catches rolling deploys)
- Attempt 5 at +220s (~3.5 min — catches longer outages)
- Final failure at ~5.5 minutes total

The total window is short enough to surface failures quickly in monitoring, but long enough to survive a typical deploy or transient 503 storm. If the warehouse SLA is known (e.g., "typically back within 2 minutes"), the backoff would be tuned to match.

Adding jitter (random offset on each delay) would be better in production to avoid the thundering herd problem if many orders fail simultaneously and retry at the same cadence.
