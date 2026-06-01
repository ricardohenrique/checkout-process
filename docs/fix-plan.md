# Fix Plan — Pre-Delivery Review

## Verdict per claim

| # | Claim | Verdict | Reasoning |
|---|-------|---------|-----------|
| 1 | Raw `\RuntimeException` in `PaymentService` and `WarehouseClient` | **Fix** | Direct CLAUDE.md violation. Named exceptions are required at every infrastructure boundary. |
| 2 | `<500ms` webhook latency not met under `sync` driver | **Document only** | Architecture is correct — all listeners implement `ShouldQueue`. The `sync` driver is an exercise trade-off already disclosed in NOTES.md. No code change needed; clarify the limitation in NOTES. |
| 3 | Failure isolation weaker under `sync` driver | **Dismiss** | The existing test (`test_webhook_returns_200_even_when_post_payment_listener_fails`) proves the sync driver isolates each queued listener independently. Listeners failing in one does not block siblings — empirically confirmed. |
| 4 | `NotifyWarehouse` uses `app()` instead of constructor injection | **Fix** | Minor but real DIP violation. `WarehouseClient` has no state so it serializes cleanly — no reason not to inject it. |
| 5 | `AppServiceProvider` has an inline closure listener for the audit job | **Fix** | Inconsistency with the other three proper listener classes. Easy to extract and makes the event map uniform and discoverable. |
| 6 | `CheckoutService` catches `\Exception` in payment failure path | **Fix** | Once `PaymentGatewayException` exists (claim 1), the catch clause must be narrowed so unrelated bugs don't accidentally trigger stock release. Depends on claim 1. |
| 7 | No test for unknown `provider_reference` webhook | **Fix** | Realistic edge case (stale or forged webhook). `firstOrFail` throws `ModelNotFoundException` — it's silently swallowed and returns 200. That's correct behavior, but it needs a test to document and protect it. |
| 8 | No test that email failure doesn't block loyalty points | **Fix** | The spec explicitly states "email and loyalty points should not block each other." Warehouse isolation is tested; email isolation is not. Spec gap. |
| 9 | No test that warehouse is NOT notified on payment failure | **Fix** | Low-cost test, high documentation value. Design guarantees it (`OrderPaid` only fires on success), but the intent should be expressed as a test. |
| 10 | N+1 query — `Product::find` inside loop in `CheckoutService` | **Fix** | `StockService::reserve` already fetched the same rows with `lockForUpdate`. Loading products a second time per item is wasteful. Batch-load once after reservation. |
| 11 | Dead `$metadata` parameter in `PaymentService::processCallback` | **Fix** | Accepted by the method and forwarded from the controller but never stored or used. Remove it from both the service and the controller call-site. |

---

## Tasks

### 1 — Named domain exceptions
**Files:** `app/Exceptions/`, `app/Services/PaymentService.php`, `app/Services/WarehouseClient.php`

- Create `app/Exceptions/PaymentGatewayException.php`
- Create `app/Exceptions/WarehouseUnavailableException.php`
- Replace `\RuntimeException` at `PaymentService.php:36` with `PaymentGatewayException`
- Replace `\RuntimeException` at `WarehouseClient.php:27` with `WarehouseUnavailableException`

### 2 — Narrow catch clause in `CheckoutService`
**Files:** `app/Services/CheckoutService.php`

- After task 1, update `catch (\Exception $e)` at line 66 to `catch (PaymentGatewayException $e)`

### 3 — Constructor injection in `NotifyWarehouse`
**Files:** `app/Listeners/NotifyWarehouse.php`

- Add `public function __construct(private WarehouseClientInterface $client) {}` 
- Replace `app(WarehouseClientInterface::class)->notify(...)` with `$this->client->notify(...)`

### 4 — Extract inline closure to a proper listener
**Files:** `app/Providers/AppServiceProvider.php`, new `app/Listeners/RecordPaymentAuditListener.php`

- Create `RecordPaymentAuditListener` that dispatches `RecordPaymentAudit` for `payment.success`
- Replace the closure in `AppServiceProvider::boot` with `Event::listen(OrderPaid::class, RecordPaymentAuditListener::class)`

### 5 — Eliminate N+1 in `CheckoutService::createOrder`
**Files:** `app/Services/CheckoutService.php`

- Before the `foreach` loop, batch-load the products: `$products = Product::whereIn('id', array_column($cartItems, 'product_id'))->get()->keyBy('id')`
- Replace `Product::find($item['product_id'])` in the loop with `$products[$item['product_id']]`

### 6 — Remove dead `$metadata` parameter
**Files:** `app/Services/PaymentService.php`, `app/Http/Controllers/PaymentCallbackController.php`

- Remove `array $metadata = []` from `PaymentService::processCallback`
- Remove the `metadata` argument from the call-site in `PaymentCallbackController::handle`

### 7 — Missing tests
**Files:** `tests/Feature/PostPaymentTest.php`

Add three tests:

- **Unknown `provider_reference`** — POST callback with a non-existent reference, assert 200 and no DB side-effects
- **Email failure isolation** — mock `Mail` to throw, POST success callback, assert loyalty points are still awarded and order is paid
- **Warehouse not notified on failure** — POST failed callback, assert `Http::recorded()` has no warehouse call

### 8 — Document sync/latency trade-off
**Files:** `docs/NOTES.md`

- Add a note clarifying that the `<500ms` guarantee requires an async queue driver + worker; under the shipped `QUEUE_CONNECTION=sync` the warehouse HTTP call runs inline
