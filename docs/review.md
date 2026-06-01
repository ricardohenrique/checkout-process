 ---                                                                                                                                                                                                      
Blockers (must fix)

1. Raw \RuntimeException instead of named domain exceptions — direct CLAUDE.md violation:
- app/Services/PaymentService.php:36 — throw a PaymentGatewayException
- app/Services/WarehouseClient.php:27 — throw a WarehouseUnavailableException

  ---                                                                                                                                                                                                      
Near-blockers

2. <500ms webhook latency requirement not met in shipped config. With QUEUE_CONNECTION=sync (the default), NotifyWarehouse runs the warehouse HTTP call (up to 10s timeout) inside the webhook request.  
   The architecture is right, but the deliverable as-configured violates the spec. Either ship with a real async driver + worker, or explicitly document the limitation in NOTES.md.

3. Failure isolation guarantee is weaker than claimed. With sync queue, if SendOrderConfirmationEmail throws, it may prevent sibling listeners from running. Either wrap each listener in its own        
   try/catch, or note in NOTES.md that true isolation only holds with an async queue driver.

  ---                                                                                                                                                                                                      
Convention / SOLID violations

4. NotifyWarehouse uses service location (app(WarehouseClientInterface::class)) instead of constructor injection. Minor DIP violation.

5. AppServiceProvider::boot has an inline closure listener for the audit job — inconsistent with the three proper listener classes.

6. CheckoutService::createOrder catches \Exception in the payment failure path (CheckoutService.php:66) — should catch the specific payment exception once it exists, otherwise a bug elsewhere could    
   incorrectly trigger stock release.

  ---                                                                                                                                                                                                      
Test coverage gaps

7. No test for unknown provider_reference webhook — firstOrFail throws ModelNotFoundException, gets swallowed as 200. Realistic attack/edge case, no test.

8. No test that email failure doesn't block loyalty points — the spec explicitly calls this out; warehouse-failure isolation is tested but email failure is not.

9. No test that warehouse is NOT notified on payment failure.
                                                                                                                                                                                                         
---                                                                                                                                                                                                      
Code smells

10. N+1 query in CheckoutService — Product::find() is called inside the loop at CheckoutService.php:44, after StockService::reserve already locked the same rows.

11. Dead $metadata parameter in PaymentService::processCallback — accepted, never stored or used.

  ---                                                                                                                                                                                                      
Documentation mismatches (nice-to-have)

- post-payment.md says OrderPaid fires in PaymentService; it actually fires in CheckoutService. Better design, but the doc is wrong.
- post-payment.md lists RecordPaymentAudit as a Listener; it's implemented as a Job dispatched from a closure in AppServiceProvider.

  ---                                                                                                                                                                                                      
Minor

- No index on orders.status or payments.status
- PaymentCallbackController.php:55 swallows all exceptions silently — consider writing an audit row before the 200 so failures are observable in the DB, not just logs.
- Webhook has no signature verification (already noted in NOTES.md, but worth a callout since it's security-relevant).

  ---                                                                                                                                                                                                      
Priority order before delivery: fix the two raw \RuntimeException (blocker) → document the sync/latency/isolation trade-offs clearly in NOTES.md → add the three missing tests → optionally fix the N+1  
and DI issues.                                                                                                                                                                                           
                               