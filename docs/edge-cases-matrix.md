# BOG redirect-mode edge cases — matrix

**Module:** `Shubo_BogPayment`
**Session:** 8 (2026-04-25) Priority 2.1
**Status:** 7/8 verified by code reading; 1 fix shipped (cart-edit-mid-flow).

Mirrors `app/code/Shubo/TbcPayment/docs/edge-cases-matrix.md`. Each case
notes the responsible code path, current behaviour, and test coverage.

---

## Matrix

| # | Case | Responsible code path | Behaviour | Test coverage |
|---|---|---|---|---|
| 1 | **Cancel on BOG page** | `Controller/Payment/AbortRedirect.php` + reconciler `handleRejectedOrCancelled` | User-initiated abort emits `rejected`/`expired` to BOG → reconciler cancels uncaptured order; AbortRedirect provides immediate UX feedback. | `AbortRedirectTest` (existing) + `LIFECYCLE-BOG-02-REST` (declined-rest.spec.ts) |
| 2 | **Card declined** | Callback `validation['valid']=false` → `VALIDATION_FAILED` (400) → reconciler `handleRejectedOrCancelled` cancels order on next tick | Order moves to canceled with audit comment. No double-charge possible. | `CallbackTest::testValidationFailedRouteReturns400` (existing) + `LIFECYCLE-BOG-02-REST` |
| 3 | **Browser closed mid-3DS** | Customer never returns; no callback received within `payment_lifetime`; `Cron/PendingOrderReconciler::execute` polls `StatusClient` after 15min and routes `expired` → `handleExpired` → cancel | Order canceled + history comment "Payment session expired at BOG". No customer-facing error (they never came back). | `PendingOrderReconcilerTest::testExpiredStatusCancelsOrder` (existing) + `LIFECYCLE-BOG-03-REST` (abandoned-rest.spec.ts) |
| 4 | **Network timeout on BOG API** | `Initiate.php:200-208` catches `LocalizedException` → friendly "An error occurred while initiating payment. Please try again." Customer sees a clean error, no quote dirtying. Curl timeout = 60s in `CreatePaymentClient`. | Verified by reading. No order is created. Customer can retry (next attempt either probes the stale id via BUG-BOG-13b or starts fresh). | `InitiateTest::testHandlesApiTimeout` (existing) |
| 5 | **Double-clicked Place Order** | `Initiate::probeExistingBogOrder` (BUG-BOG-13b) — when `bog_order_id` is already on the quote, status-API probe returns: terminal-success → redirect to /success ("already paid"); pending → "still processing" message; terminal-failure → clear stale id, proceed with fresh init | First click takes effect; subsequent clicks are idempotent. Race window between probe and order ID storage closes via the per-quote single-active flow + reconciler safety net. | `InitiateTest::testProbeExistingBogOrderShortCircuits*` (existing — 5 tests covering each branch) |
| 6 | **Order amount changes mid-flow** | `Callback::amountMismatch` — Session 8 P2.1 fix. Compares `body.purchase_units.total_amount` (when present) against `order->getGrandTotal()`. >1 tetri diff → log critical, return `AMOUNT_MISMATCH` (HTTP 400). | **NEW**: previously the cart could be edited in another tab between init and capture, resulting in a wrong-amount capture without anyone noticing. Now the callback rejects and the reconciler's polling never auto-captures (status API does not carry purchase_units, so the discrepancy stays at the callback layer for now — see Out-of-scope). | `CallbackTest::testAmountMismatchAbortsWithHttp400` (new) + `testAmountWithin1TetriToleranceProcessesNormally` (new — false-positive defence) + `testMissingAmountProcessesNormally` (new — defensive null-check) |
| 7 | **Concurrent Callback / ReturnAction / Cron race** | All three handlers serialize on `PaymentLock::withLock($bogOrderId)` (BUG-BOG-6). Each re-reads order state inside the lock; `STATE_PROCESSING` short-circuits to `ALREADY_PROCESSED` / no-op. | No double-invoice, no double-commission, no double-order-email. | `CallbackTest::testConcurrentCallbackShortCircuitsWhenOrderAlreadyProcessing` (existing) + `PaymentLockTest` (existing) + `ReturnActionTest::testConcurrentReturnFromBackground` (existing) |
| 8 | **Stale RSA public key (rotation gap)** | `CallbackValidator::verifySignature` returns false on key mismatch → `validateViaStatusApi` fallback runs. `validateViaStatusApi` re-validates by querying BOG `/receipt/{order_id}` → if BOG says `completed`/`captured`, callback proceeds. If it can't reach BOG → `LocalizedException` raised, logged, callback returns `ERROR` (HTTP 500). | Fail-closed on signature; fail-degraded to status-API. Operationally safe through key rotation: even with the wrong public key live, payments continue being processed via the status-API path. | `CallbackValidatorTest::testStatusApiFallbackHandlesNestedBodyShape` (existing) + lifecycle declined spec without `Callback-Signature` (covered by helper omission) |

---

## Out of scope (deferred)

- **Reconciler amount-mismatch guard.** The status-API `/receipt/{order_id}` response shape does NOT include `purchase_units.total_amount` for every status type — verifying this in dev requires real BOG sandbox traffic. For now the amount-mismatch guard lives only in the callback path. If a customer ever bypasses the callback (e.g. skips the return URL and the callback fails to deliver), the reconciler will capture without amount validation. Risk window: low (callbacks are reliable, retries are aggressive). Tracked here so a future `/audit` knows to revisit.
- **Russian locale for AMOUNT_MISMATCH user-facing comment.** Currently the order history comment is logged but not customer-shown (callback is server-to-server). When/if we surface this in admin, route the comment through `UserFacingErrorMapper` (currently `400` → "Payment data is invalid") and add a `ru_RU` row. Mirrors TBC's same deferral.
- **Mid-3DS network failure in customer's browser.** The customer may see a partial 3DS page and close the tab. From our side, this is identical to case #3 (browser closed). The reconciler still cleans up via the BOG status API after 15 min.

---

## Test coverage map

```
Case 1  → AbortRedirectTest (existing)         + LIFECYCLE-BOG-02-REST (new spec)
Case 2  → CallbackTest existing                 + LIFECYCLE-BOG-02-REST (new spec)
Case 3  → PendingOrderReconcilerTest existing  + LIFECYCLE-BOG-03-REST (new spec)
Case 4  → InitiateTest existing
Case 5  → InitiateTest existing (5 sub-cases)
Case 6  → CallbackTest 3 NEW tests + Callback.php amountMismatch() new fn
Case 7  → CallbackTest, ReturnActionTest, PaymentLockTest existing
Case 8  → CallbackValidatorTest existing
```

End of edge-cases-matrix.
