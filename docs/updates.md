# Bug Report — Part 1

---

## Bug 1 — Race condition in stock reservation (oversell)

**File:** `app/Services/StockService.php:19–33`

**What can go wrong:**
`reserve()` does a read-check-then-write with no row lock. Two requests arriving simultaneously can both read `stock_quantity = 3`, both pass the `< 2` check, and both decrement — writing `1` twice and effectively overselling. Because `stock_quantity` is an `unsignedInteger`, the DB constraint won't prevent a negative value being calculated in PHP and saved.

```php
// Both requests execute this before either saves:
if ($product->stock_quantity < $item['quantity']) { ... } // both pass
$product->stock_quantity -= $item['quantity'];            // both decrement from 3
$product->save();                                         // one writes 1, one writes 1 — not 0
```

**Fix:**
Use a pessimistic lock (`lockForUpdate`) when loading the product row. This must happen inside the enclosing `DB::transaction` in `CheckoutService`.

```php
$product = Product::lockForUpdate()->findOrFail($item['product_id']);
```

---

## Bug 2 — External HTTP call inside a database transaction

**File:** `app/Services/CheckoutService.php:59` (call to `$this->paymentService->initiate($order)`)  
**Related:** `app/Services/PaymentService.php:27` (the HTTP call)

**What can go wrong:**
The entire order creation — including a 5-second HTTP call to the payment gateway — runs inside a single `DB::transaction`. This has two consequences:

1. **Connection pool exhaustion.** The DB connection is held open for up to 5 seconds per request. Under concurrent load this exhausts the pool, causing all other requests to queue or fail.

2. **Split-brain on rollback.** If the gateway registers the session successfully but any subsequent DB operation fails and causes a rollback, the gateway has an active session (`PAY-XXXX`) that has no corresponding row in the `payments` table. There is no way to reconcile this.

**Fix:**
Move `paymentService->initiate()` outside the transaction. The transaction should only cover the DB mutations (stock decrement, order + order_items creation). The HTTP call and `Payment::create()` happen after the transaction commits.

```php
// Inside transaction: stock + order + items only
$order = DB::transaction(function () use (...) {
    $this->stockService->reserve($cartItems);
    // create order, items, calculate total…
    return $order;
});

// Outside transaction: HTTP call + payment record
$paymentResult = $this->paymentService->initiate($order);
```

---

## Bug 3 — Order total not rounded to currency precision

**File:** `app/Models/Order.php:38–48` (`calculateTotal()`)

**What can go wrong:**
`calculateTotal()` returns a raw PHP float. For a product at €29.99 with a 15% discount:

```
29.99 * (1 - 15/100) = 29.99 * 0.85 = 25.4915
```

The `orders.total` column is `decimal(12,2)`, so when saved the DB rounds to `25.49` — but the in-memory `$order->total` remains `25.4915` until the model is refreshed. The amount forwarded to the payment gateway and written into `payments.amount` (inside the same request, before any refresh) is `25.4915`, while the DB-persisted order total is `25.49`. This creates a mismatch between what the gateway was asked to charge and what the order record shows.

**Fix:**
Round the result to two decimal places before returning:

```php
return round($subtotal, 2);
```

---

## Bug 4 — Payment callback is not idempotent

**File:** `app/Services/PaymentService.php:60–68` (`processCallback()`)  
**Related:** `app/Http/Controllers/PaymentCallbackController.php:48–58`

**What can go wrong:**
Payment providers retry webhooks when a response is slow or fails. `processCallback()` has no guard on the current payment status — it overwrites unconditionally:

```php
$payment->status = $status === 'success'
    ? Payment::STATUS_SUCCESS
    : Payment::STATUS_FAILED;
$payment->save();
```

If a `success` callback is retried:
- `handlePaymentSuccess()` runs again on an already-paid order.
- Any post-payment side-effects (email, loyalty points, audit log, warehouse notification) fire a second time.
- A `failed` retry after a `success` would downgrade the order back to failed — which could trigger a stock release on a paid order.

**Fix:**
Return early if the payment is already in a terminal state:

```php
if (in_array($payment->status, [Payment::STATUS_SUCCESS, Payment::STATUS_FAILED])) {
    return $payment;
}
```
