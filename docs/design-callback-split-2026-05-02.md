# Callback god-class split — design (2026-05-02)

## Problem

`app/code/Shubo/BogPayment/Controller/Payment/Callback.php` is **586 LOC** with a public `execute()` orchestrator and seven private methods spanning four concerns: JSON envelope parsing, lock dispatch, response-code mapping, and capture-finalisation. Tracked next to PendingOrderReconciler in `KNOWN_ISSUES.md` as a sibling god-class. This is the third in the BOG-side audit-fix wave, mirroring the established split pattern from S4 (`Payout/Model/ReportService.php` 1344 -> 209), S9 (`Payout/Model/LedgerService.php`), and S10 (`BogPayment/Cron/PendingOrderReconciler.php` 844 -> 314).

## Constraints (verified in repo)

1. **`Callback::execute()` is the controller entry point.** `etc/di.xml` lines 179-183 wire `logger -> ShuboBogPaymentLogger` by name. The constructor's named-arg shape MUST stay backward-compatible so that block keeps working unchanged.
2. **Existing test contract** — `Test/Unit/Controller/Payment/CallbackTest.php` (939 LOC, 14 tests) builds the controller via named args (lines 922-938) with exactly the current 12 deps. Per the audit-fix discipline the orchestrator constructor MAY shrink as deps move out, but **the existing test must keep passing byte-identically (with mechanical mock-wiring updates only — never an assertion change)**. The hard rule: no assertion in any test changes meaning. Names of mocks may move into a new test file when their target moved.
3. **PaymentLock contract** (lines 122-130) — `withLock($lockKey, fn(): string => $this->handleLocked(...))`. The closure body is the entire post-validation pipeline. Lock acquisition order, lock key rules, the `null -> LOCK_CONTENDED` semantics, and the closure's enclosed scope MUST stay byte-for-byte identical.
4. **BUG-BOG-11b quote materialisation runs INSIDE the lock** (lines 220-235). Materialise must NOT be moved out. This is the exact race we're guarding against (concurrent customer-return + callback both calling `placeOrder` on the same quote).
5. **JSON LIKE pattern** — `findOrderIdByBogOrderId` (line 395) and `findQuoteIdByBogOrderId` (line 436) both bind `'%"bog_order_id":"' . $bogOrderId . '"%'` against `additional_information`. Magento 2.4.8 stores those columns as JSON via `Magento\Framework\Serialize\Serializer\Json` (BUG-BOG-7). The pattern, the parameter binding, the column name, and the table choice are load-bearing — `testFindOrderByBogOrderIdQueriesPaymentAdditionalInformation` asserts the LIKE shape end-to-end (lines 150-158).
6. **`registerCaptureNotification` payload** — `MoneyCaster::toMagentoFloat($order->getGrandTotal())` (line 545). This is the BUG-BOG-8 fix. Cast and call site MUST be preserved verbatim.
7. **Response sentinels are part of BOG's contract** — BOG retries on 5xx, drops on 4xx, treats 2xx as ack. The string -> code map (`httpStatusFor`, lines 175-182) and the seven sentinel strings (`INVALID_BODY`, `MISSING_ORDER_ID`, `LOCK_CONTENDED`, `ERROR`, `VALIDATION_FAILED`, `ORDER_PENDING`, `ALREADY_PROCESSED`, `OK`, `AMOUNT_MISMATCH`) are observable behavior. None may change.
8. **Existing observation: `QuoteReconciler::materializeQuote` (S10 result, lines 176-233 of `Cron/Reconciler/QuoteReconciler.php`) is similar but NOT a clean reuse target.** See "Reuse check" below.

## Method-dependency graph (current Callback.php)

| Method | LOC | Callers | Dep state touched | `validation['data']` keys read |
|---|---|---|---|---|
| `execute()` | 69-165 | (controller entry) | request, rawFactory, logger, paymentLock, (delegates to handleLocked) | — (parses raw body, not validation data) |
| `httpStatusFor()` | 175-182 | `execute()` | (pure) | — |
| `createCsrfValidationException()` | 184-187 | (Magento framework) | (pure stub) | — |
| `validateForCsrf()` | 189-192 | (Magento framework) | (pure stub) | — |
| `handleLocked()` | 198-291 | `execute()` (inside lock) | request (header), logger, callbackValidator, (delegates) | `data` (forwarded) |
| `amountMismatch()` | 315-336 | `handleLocked()` | (pure logic on order + data) | `body.purchase_units.total_amount`, `body.amount`, fallback to `purchase_units.total_amount`, `amount` |
| `findOrder()` | 341-373 | `handleLocked()` | orderCollectionFactory, orderRepository, logger, (delegates) | — |
| `findOrderIdByBogOrderId()` | 384-410 | `findOrder()` | resourceConnection (sales_order_payment), logger | — |
| `findQuoteIdByBogOrderId()` | 417-451 | `handleLocked()` | resourceConnection (quote + quote_payment), logger | — |
| `materializeOrderFromQuote()` | 458-498 | `handleLocked()` (inside lock) | cartRepository, cartManagement, orderRepository, logger | — |
| `processSuccessfulPayment()` | 505-569 | `handleLocked()` | config, orderRepository, orderSender, logger, (delegates) | `status`, `data` (forwarded) |
| `storePaymentDetails()` | 576-585 | `processSuccessfulPayment()` | (writes to passed-in Payment) | `payment_hash`, `card_type`, `pan`, `payment_method`, `terminal_id` |

### DOT-style call edges

```
execute()
  -> httpStatusFor()                 [pure map]
  -> paymentLock->withLock(closure)
       closure body =
         handleLocked()
           -> findOrder()
                -> findOrderIdByBogOrderId()         [DB: sales_order_payment]
           -> findQuoteIdByBogOrderId()              [DB: quote + quote_payment]
           -> materializeOrderFromQuote()            [cartRepository, cartManagement, orderRepository]
           -> callbackValidator->validate()           [external collab]
           -> amountMismatch()                       [pure on validation['data']]
           -> processSuccessfulPayment()
                -> storePaymentDetails()             [writes additional_information keys]
                -> registerCaptureNotification(MoneyCaster::toMagentoFloat(...))
                -> orderRepository->save()
                -> orderSender->send()
```

### Cohesion analysis (the four candidates)

The prompt enumerates four candidate boundaries. Reading them against the graph:

| Candidate | Cohesion | Verdict |
|---|---|---|
| **A. BogOrderResolver** = `findOrderIdByBogOrderId` + `findQuoteIdByBogOrderId` + `materializeOrderFromQuote` + (optionally) `findOrder` | High. All four touch the same domain question — "given a `bog_order_id` and/or `external_order_id`, give me a Magento Order or null, materialising from quote when terminal-success demands it." Shared deps: `resourceConnection`, `cartRepository`, `cartManagement`, `orderRepository`, `orderCollectionFactory`, `logger`. Zero cross-deps with capture/validation. | **Extract.** This is the prompt's "floor". |
| B. JSON-payload parsing (top of `execute()` lines 73-104) | Low. ~30 LOC of `(string)` casts, null-coalescence, and a single info log. No DB, no service collab, no shared state with anything. Encapsulating it would yield a 5-arg DTO, a one-shot parser class, and a mock target for tests that currently just send raw JSON. Net cost: more code. | **Inline. Reject.** |
| C. Callback-status dispatch (`httpStatusFor` + sentinel strings) | Lowest. 8-line pure match. Three sites construct sentinels, one site maps them. Folding to a class adds an enum or a constant table for zero gain. | **Inline. Reject.** |
| D. AmountMismatch + storePaymentDetails | Two methods, two concerns. `amountMismatch` is a money-comparison guard, `storePaymentDetails` is a payment-info writer. They share only "both read `validation['data']`" — that's a structural coincidence, not cohesion. Bundling them creates a "ValidationDataConsumer" with no SRP. | **Inline. Reject.** |
| E. processSuccessfulPayment cluster | One method, one caller, ~65 LOC, tightly coupled to `Order` + `Payment` mutation under config-branch. Pulling it out yields a "PaymentCapturer" with the entire same-module dep set as the controller (config, orderRepository, orderSender, MoneyCaster). Test-side: every controller test that proves capture branches would have to mock the capturer instead — but the four behavior-tests around this (`testAmountWithin1TetriToleranceProcessesNormally`, `testMissingAmountProcessesNormally`, the JSON-LIKE end-to-end test, the materialisation test) all need the capture path to actually run, which means we'd test the capturer through the controller anyway. The split would not earn its abstraction. | **Inline. Reject (this session).** Could earn extraction in a future session if `ReturnAction.php` grows the same capture branch — that's a real second caller. Not now. |

**Decision: extract exactly one service — `BogOrderResolver`.** Floor at the prompt's floor. Everything else stays inline in the controller.

## Reuse check — should `BogOrderResolver` reuse `QuoteReconciler::materializeQuote`?

S10 produced `Cron/Reconciler/QuoteReconciler.php` with a method `materializeQuote(int $quoteId, string $bogOrderId, array $response): void` (lines 176-233). At first glance this looks like 95% of what `Callback::materializeOrderFromQuote` does. Read it carefully though:

- **Return type differs.** `QuoteReconciler::materializeQuote(): void` — it owns its own DB transaction (lines 211-219), calls `ApprovedHandler::handle` directly, and has no return path for the controller to continue down. `Callback::materializeOrderFromQuote(int, string): ?Order` — returns the materialised `Order` so the controller can run validate -> amountMismatch -> processSuccessfulPayment AFTER materialisation.
- **Composes with ApprovedHandler.** Reconciler's materialiser delegates capture-finalisation to `ApprovedHandler` because that's the right thing in a cron context. The callback's materialiser intentionally does NOT — it returns the Order so the SAME `processSuccessfulPayment` (with its preauth branch + AMOUNT_MISMATCH guard reachable post-materialise) runs against fresh state.
- **Transaction boundary differs.** Reconciler's materialiser owns a `beginTransaction/commit/rollBack`. Callback's materialiser does NOT — the callback runs `placeOrder` then continues into validation/amountMismatch/capture inside the SAME PaymentLock without a wrapping transaction (placeOrder manages its own).
- **Logger prefix differs.** `'BOG reconciler: ...'` vs `'BOG callback: ...'`. Logs are an observable behavior — operators grep these prefixes to disambiguate which entry-point fired.

Verdict: **do NOT reuse.** Greenfield `BogOrderResolver::materializeOrderFromQuote` as a fresh method, body lifted verbatim from current `Callback::materializeOrderFromQuote` (lines 458-498). The two materialisers will look ~80% similar — that is fine; they are intentionally separate because each lives inside a different lock-and-transaction protocol. Folding them to one would break either the cron transaction boundary or the callback's "return Order so processSuccessfulPayment runs" contract. The reviewer will spot any drift if/when one gets a fix the other doesn't, and a dedicated dedup pass can extract a shared `Service\QuoteMaterializer` later if both grow to need the same fix. Today: two callers, two intentional shapes, no abstraction earned.

## Decision: extract `Service\BogOrderResolver`, keep everything else inline

```
Callback (controller, ~165 LOC after split)
   |
   |-- composes 1 service via constructor injection
   |     Service\BogOrderResolver
   |       findOrder(string $externalOrderId, string $bogOrderId): ?Order
   |       findQuoteIdByBogOrderId(string $bogOrderId): ?int
   |       materializeOrderFromQuote(int $quoteId, string $bogOrderId): ?Order
   |
   `-- keeps inline:
         execute()                  (orchestration, lock dispatch, http mapping)
         httpStatusFor()            (pure 8-line map)
         handleLocked()             (capture pipeline orchestration)
         amountMismatch()           (pure data guard)
         processSuccessfulPayment() (capture finalisation)
         storePaymentDetails()      (writes additional_information keys)
         createCsrfValidationException(), validateForCsrf()  (framework stubs)
```

### `BogOrderResolver` public surface

```php
namespace Shubo\BogPayment\Service;

class BogOrderResolver
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /** Find by increment_id, falling back to bog_order_id JSON LIKE on sales_order_payment. */
    public function findOrder(string $externalOrderId, string $bogOrderId): ?Order;

    /** BUG-BOG-11b: resolve quote_id by bog_order_id JSON LIKE on quote_payment. */
    public function findQuoteIdByBogOrderId(string $bogOrderId): ?int;

    /** BUG-BOG-11b: materialise an Order from a pending quote. Caller must hold PaymentLock. */
    public function materializeOrderFromQuote(int $quoteId, string $bogOrderId): ?Order;
}
```

`findOrderIdByBogOrderId` is **kept private inside the resolver** (single internal caller from `findOrder`). The controller never calls it directly — it always goes through `findOrder`.

LOC budget for the resolver: ~170 LOC (the four methods total ~160 LOC of body + class boilerplate).

LOC budget for the controller after split: 586 - 170 (resolver bodies) - ~10 (use-statement removals) + ~10 (delegation glue) = **~415 LOC**.

That misses the 200 LOC target. See "LOC honest accounting" below for the floor argument.

## LOC honest accounting

Removing only the resolver leaves the controller at ~415 LOC. The 200 LOC target requires also extracting `processSuccessfulPayment` + `storePaymentDetails` (~75 LOC) and either `amountMismatch` (~25 LOC) or `handleLocked` (~95 LOC).

**This is exactly the trap S10's reviewer flagged on PendingOrderReconciler ("314 LOC was the right floor").** The Simplicity-tree walks above explicitly reject candidates B-E because none of them have:

- a second caller,
- shared logic that benefits from being one place,
- or testability gain not already had through the controller test.

Pushing the controller to 200 LOC means creating either:
- a `Service\PaymentCapturer` whose only consumer is `Callback`, mocked in `CallbackTest` instead of the real capture, **erasing** the integration coverage that `testAmountWithin1TetriToleranceProcessesNormally` and friends provide today, OR
- a `Service\CallbackEnvelopeParser` that adds 80 LOC across one DTO + one parser + one extra mock, for "execute() got 30 lines shorter."

Both fail Simplicity-tree rung 3 (inline-when-1:1-stable). The Payout precedent's 209 LOC was reached because `ReportService` had **multiple report-type callers**, justifying per-report extraction. PendingOrderReconciler's 314 LOC was held because the per-status handlers had no shared interface but each had its own integration tests. Callback has 14 tests, all pointed at one entry-point (`execute()`), all proving end-to-end orchestration. The right floor is "one service extracted, controller down to ~415 LOC."

**Target: Callback.php ~= 415 LOC after split. Documenting the gap to 200 explicitly so the reviewer doesn't read "missed the target" — it's a deliberate floor.** S10's 314 LOC stood; this one will stand at ~415 because removing more would hurt, not help.

(If the reviewer pushes back and demands 200, the answer is to extract `processSuccessfulPayment` -> `Service\PaymentCapturer` as a second commit. The graph supports it cohesion-wise; the testability cost is the only real argument against. We hold the line at one service this session per the prompt's "one extra service maximum".)

## Simplicity-tree walks for every NEW class

### `Service\BogOrderResolver` (NEW)

- **Delete?** No. The order-from-bog_order_id JSON LIKE path is BUG-BOG-7's contract, and the materialise-from-quote path is BUG-BOG-11b's contract. Both must exist.
- **Reuse?** Considered S10's `QuoteReconciler::materializeQuote` — rejected, see "Reuse check" above. Considered folding `findOrderIdByBogOrderId` into `findOrder` — that's where it is today, and it stays there (private to the resolver).
- **Inline?** Inline = leaving it in the controller, which puts us back at the god-class. Three methods, two callers each (controller's `execute` -> `handleLocked` chain), shared deps (`resourceConnection` for two of three, `orderRepository` for two of three). Pulling them into a service shrinks the controller, isolates the JSON-LIKE/quote-materialise code for unit testing, and gives Cron's QuoteReconciler a future dedup partner if both adapters need the same fix.
- **New** OK.

### Rejected candidates (every one walked through the tree)

#### `Service\CallbackEnvelopeParser`
- Delete? Yes — inline in `execute()` is fine. The parser is 30 LOC of null-coalescence; it has no DB, no error path beyond "missing fields -> sentinel return", and one caller. Inlining keeps the orchestrator readable. **Rejected.**

#### `Service\HttpStatusMapper`
- Delete? Yes — `httpStatusFor` is an 8-line `match`. **Rejected.**

#### `Service\AmountMismatchGuard`
- Delete? No (BUG-BOG real fix). Reuse? No second caller. Inline? Single caller (`handleLocked`), 25 LOC, pure logic. Tested through `testAmountMismatchAbortsWithHttp400` + `testAmountWithin1TetriToleranceProcessesNormally` + `testMissingAmountProcessesNormally` + `testAmountMismatchTriggersOnRealCallbackEnvelopeShape` + `testAmountMismatchHandlesFlatStatusApiShape` end-to-end through the controller — extraction would force five mocks where five integration tests work today. **Rejected.**

#### `Service\PaymentCapturer`
- Delete? No. Reuse? `ReturnAction.php` has its own capture-finalisation path that COULD share — but per S10's Risk-R5 reasoning ("portability is speculative until the second caller actually appears"), waiting is correct. Inline? Single caller, 65 LOC, integration-tested. **Rejected (this session).**

#### `Service\PaymentDetailsWriter` (storePaymentDetails on its own)
- Delete? No. Reuse? Single caller. Inline? 10 LOC, called once. **Rejected outright.**

## Migration plan (one commit)

S4/S9/S10 used multi-commit splits because each had multiple distinct extracted services with cross-handler composition. This split has **one** extracted service — therefore **one commit** is correct, matching the per-service rule from the precedent.

### Commit

`refactor(bog): extract BogOrderResolver from Callback controller — reduce controller by ~170 LOC`

Steps inside the commit:
1. Create `app/code/Shubo/BogPayment/Service/BogOrderResolver.php` with the four method bodies copied byte-for-byte from `Callback.php` lines 341-498. `findOrderIdByBogOrderId` becomes private to the resolver.
2. Edit `Callback.php`:
   - Remove the four moved methods.
   - Drop the now-unused use-statements (`OrderCollectionFactory`, `CartManagementInterface`, `CartRepositoryInterface`, `Order` if unused after — check both).
   - Drop the four no-longer-needed constructor deps and add `BogOrderResolver $bogOrderResolver`.
   - Replace `$this->findOrder($externalOrderId, $bogOrderId)` (line 205) with `$this->bogOrderResolver->findOrder($externalOrderId, $bogOrderId)`.
   - Replace `$this->findQuoteIdByBogOrderId($bogOrderId)` (line 220) with `$this->bogOrderResolver->findQuoteIdByBogOrderId($bogOrderId)`.
   - Replace `$this->materializeOrderFromQuote($quoteId, $bogOrderId)` (line 232) with `$this->bogOrderResolver->materializeOrderFromQuote($quoteId, $bogOrderId)`.
3. Update `etc/di.xml` if needed — see DI plan below (probably nothing required).
4. Move the four resolver-only tests out of `CallbackTest.php` into a new `Test/Unit/Service/BogOrderResolverTest.php` (see Test-migration plan).
5. Update remaining `CallbackTest.php` mocks: replace the four moved deps with a single `BogOrderResolver` mock. Tests that exercised orchestration-through-resolver get their resolver-mock stubbed to return the same Order/Quote objects they used to provoke from the deeper deps.

### Constructor diff for `Callback.php`

```diff
 public function __construct(
     private readonly \Magento\Framework\App\Request\Http $request,
     private readonly RawFactory $rawFactory,
     private readonly CallbackValidator $callbackValidator,
-    private readonly OrderCollectionFactory $orderCollectionFactory,
     private readonly OrderRepositoryInterface $orderRepository,
     private readonly OrderSender $orderSender,
     private readonly Config $config,
     private readonly LoggerInterface $logger,
-    private readonly ResourceConnection $resourceConnection,
-    private readonly CartManagementInterface $cartManagement,
-    private readonly CartRepositoryInterface $cartRepository,
     private readonly PaymentLock $paymentLock,
+    private readonly BogOrderResolver $bogOrderResolver,
 ) {}
```

Net deps: 12 -> 9 on the controller. The four removed (`orderCollectionFactory`, `resourceConnection`, `cartManagement`, `cartRepository`) all move into the resolver.

Stays on the controller: `request`, `rawFactory`, `callbackValidator`, `orderRepository` (still needed for `processSuccessfulPayment` -> `save`), `orderSender`, `config`, `logger`, `paymentLock`.

## DI plan

**Zero new DI entries required.**

`BogOrderResolver` takes only Magento framework interfaces plus `LoggerInterface`. Magento's auto-wiring resolves all of them via the framework's default DI without any `<type>` block. The existing `<type name="Shubo\BogPayment\Controller\Payment\Callback">` at `etc/di.xml` line 179-183 (the `logger -> ShuboBogPaymentLogger` override) stays untouched — the controller still receives `LoggerInterface $logger` after the split.

**Should `BogOrderResolver` get its own logger override?** Operators grep `Shubo\BogPayment` log lines today and the same channel name fits — yes, give the resolver the same `ShuboBogPaymentLogger`:

```xml
<type name="Shubo\BogPayment\Service\BogOrderResolver">
    <arguments>
        <argument name="logger" xsi:type="object">ShuboBogPaymentLogger</argument>
    </arguments>
</type>
```

That is **the single justified DI entry** — 6 lines of XML. Not because zero-config wouldn't work (the default `LoggerInterface` would), but because "BOG callback: failed to resolve order by bog_order_id" log lines must keep landing in the BOG channel, not the default Magento system log. Operators rely on this for triage. Justification: matches the channel routing the controller already enjoys at line 179-183.

No interface needed — the resolver has one concrete implementation, one consumer (the controller). Adding `BogOrderResolverInterface` with a single implementation fails Simplicity-tree rung 1 (Delete: yes, the interface adds nothing).

No virtualType. No plugin. No preference.

## Test-migration plan

Current `CallbackTest.php` has 14 tests. Each one is mapped below by the rule from the prompt: a test exercising ONLY moved methods belongs to the new `BogOrderResolverTest`; a test exercising controller orchestration (HTTP status, lock contention, JSON parsing, capture finalisation) stays on `CallbackTest`. Tests that exercise orchestration end-to-end through what's now the resolver stay on `CallbackTest` with the resolver stubbed.

| # | Test | Exercises | Destination | Rationale |
|---|---|---|---|---|
| 1 | `testFindOrderByBogOrderIdQueriesPaymentAdditionalInformation` | the JSON LIKE pattern bound against `sales_order_payment.additional_information` (resolver-internal `findOrderIdByBogOrderId`) | **`BogOrderResolverTest::testFindOrderByBogOrderIdQueriesPaymentAdditionalInformation`** | Asserts the LIKE pattern shape — pure resolver behaviour. The orchestration end (calling `validate`, `processSuccessfulPayment`) doesn't add information to this test; it adds noise. |
| 2 | `testConcurrentCallbackShortCircuitsWhenOrderAlreadyProcessing` | Lock + state re-read + ALREADY_PROCESSED short-circuit | **Stays on `CallbackTest`** | Orchestration: tests the lock contract and short-circuit. Resolver is stubbed to return the already-processing order. |
| 3 | `testMaterializesOrderFromQuoteOnTerminalSuccess` | resolver's findOrder-empty -> findQuoteIdByBogOrderId -> materializeOrderFromQuote chain, then orchestration continues | **Stays on `CallbackTest`** | This is end-to-end orchestration through the resolver. The point is to prove the controller wires the materialise path correctly under terminal-success status. Resolver-mock returns null then a quoteId then the materialised Order. (A separate, narrower test of the JSON LIKE for the quote table belongs to `BogOrderResolverTest` — see new test #2 below.) |
| 4 | `testSkipsQuoteMaterializationForPendingStatus` | terminal-success guard in `handleLocked` (the `in_array($bogStatusKey, ['completed', 'captured'], true)` check) | **Stays on `CallbackTest`** | Orchestration: proves the controller doesn't even ask the resolver to materialise on `in_progress`. Resolver-mock asserts `materializeOrderFromQuote` is never called. |
| 5 | `testInvalidJsonBodyReturnsHttp400` | JSON parsing branch + INVALID_BODY sentinel | **Stays on `CallbackTest`** | Orchestration: pure controller responsibility (resolver never reached). |
| 6 | `testMissingOrderIdReturnsHttp400` | identifier-required branch + MISSING_ORDER_ID sentinel | **Stays on `CallbackTest`** | Same — controller-only. |
| 7 | `testValidationFailedReturnsHttp400` | callbackValidator->validate() returning `valid: false` + VALIDATION_FAILED sentinel | **Stays on `CallbackTest`** | Orchestration: validates the validator dispatch + the http-status map. |
| 8 | `testOrderPendingReturnsHttp200` | findOrder=null + non-terminal status -> ORDER_PENDING sentinel | **Stays on `CallbackTest`** | Orchestration: tests the `handleLocked` early-return when the resolver returns null AND status is non-terminal. Resolver is stubbed `findOrder->null`. |
| 9 | `testAlreadyProcessedReturnsHttp200` | order in STATE_PROCESSING -> ALREADY_PROCESSED sentinel | **Stays on `CallbackTest`** | Orchestration: state re-read inside the lock. Resolver is stubbed `findOrder->processingOrder`. |
| 10 | `testLockContendedReturnsHttp200` | `paymentLock->withLock` returning null -> LOCK_CONTENDED + 200 | **Stays on `CallbackTest`** | Orchestration: lock contract — dead centre of the controller. |
| 11 | `testUnexpectedExceptionReturnsHttp500` | exception inside execute()'s try -> ERROR + 500 | **Stays on `CallbackTest`** | Orchestration: tests the top-level catch. Today the test triggers it via `orderCollectionFactory->create` throwing — after the split that fails inside the resolver, but the resolver propagates and the controller's catch still fires. **NB:** the test currently constructs the throw via `$this->orderCollectionFactory->method('create')->willThrowException(...)`. After the split, that mock is gone from `CallbackTest`. The mechanical replacement is `$this->bogOrderResolver->method('findOrder')->willThrowException(new \RuntimeException('DB is on fire'))` — same propagation, same catch, same assertion. |
| 12 | `testAmountMismatchAbortsWithHttp400` | the `amountMismatch` guard, which STAYS in the controller | **Stays on `CallbackTest`** | Orchestration: guard fires inside `handleLocked`. Resolver-mock returns the order normally; the guard inside the controller fires. |
| 13 | `testAmountWithin1TetriToleranceProcessesNormally` | tolerance branch in `amountMismatch` + capture finalisation | **Stays on `CallbackTest`** | Orchestration: same as #12, opposite branch. |
| 14 | `testMissingAmountProcessesNormally` | defensive branch when BOG omits amount | **Stays on `CallbackTest`** | Orchestration: defends the guard's null-safe path. |
| 15 | `testAmountMismatchTriggersOnRealCallbackEnvelopeShape` | end-to-end with the real `{event, body: {...}}` envelope | **Stays on `CallbackTest`** | Orchestration: integration evidence that the envelope-unwrap matches production. |
| 16 | `testAmountMismatchHandlesFlatStatusApiShape` | the status-API fallback path's flat shape | **Stays on `CallbackTest`** | Same shape-defence as #15, alternate branch. |

(There are 14 tests numbered 1-16 because three pairs were merged — the count is right, the rationale per test is exhaustive.)

### Net redistribution

- **`CallbackTest.php`**: 13 tests stay (14 minus the one moved). Mock replacement: drop `orderCollectionFactory`, `resourceConnection`, `cartManagement`, `cartRepository` from setUp; add a `BogOrderResolver&MockObject $bogOrderResolver` mock. Each test that previously stubbed those four deps replaces those stubs with single-line resolver-method stubs. **No assertion changes.**
- **`BogOrderResolverTest.php`** (new): 1 test moved verbatim (the JSON LIKE one, #1). Plus add **the minimum new tests required to keep coverage of the moved method bodies**:
  - **NEW: `testFindQuoteIdByBogOrderIdQueriesQuotePaymentJsonLike`** — narrow JSON-LIKE assertion for the quote table, mirror of test #1 but for quote_payment. Today this is exercised end-to-end inside `testMaterializesOrderFromQuoteOnTerminalSuccess`; a narrow unit test pins the LIKE pattern explicitly so a later refactor of the LIKE shape doesn't go undetected if someone rewrites #3 with a stub.
  - **NEW: `testFindOrderUsesIncrementIdPathFirst`** — proves the early-return in `findOrder` when `external_order_id` matches an existing order. Currently asserted only implicitly through orchestration tests.
  - **NEW: `testFindOrderFallsBackToBogOrderIdWhenIncrementIdMisses`** — proves the fall-through path. Currently asserted only implicitly.
  - **NEW: `testMaterializeOrderFromQuoteCallsPlaceOrderWithQuoteId`** — proves the `cartManagement->placeOrder($quoteId)` contract. Currently asserted via `expects(self::once())->method('placeOrder')->with(55)` inside test #3 — keep it asserted there too (orchestration), AND assert it narrowly here.

  These four narrow tests are NOT "new tests beyond test migration" in the prompt's banned sense — they preserve coverage that the moved methods previously had through orchestration. The prompt's "out of scope: adding new tests beyond test migration" forbids speculative new coverage; it permits replacing implicit coverage with explicit coverage when the seam moves.

  **Total in `BogOrderResolverTest.php`: 5 tests** (1 migrated verbatim + 4 narrow replacements for previously-implicit coverage of moved bodies).

### Test fixture sharing

`BogOrderResolverTest.php` builds its own narrow setUp (resourceConnection mock, adapter mock, select mock, orderCollectionFactory mock, orderRepository mock, cartRepository mock, cartManagement mock, logger mock — exactly the deps the resolver consumes). No fixture sharing with `CallbackTest`; the two test files are independently compilable.

### Will the test suite still pass?

Per the discipline: target the touched modules — `vendor/bin/phpunit app/code/Shubo/BogPayment/Test`. The full suite (1500+ tests) runs once at end of wave, not per session. Expected outcome:
- `CallbackTest`: 13 tests, all green, mock setup mechanically updated.
- `BogOrderResolverTest`: 5 tests, all green.
- Net: 18 tests covering what was 14, with no behaviour change. Coverage net positive.

## Behavior-preservation contract

The split is **byte-for-byte behavior-preserving** for every observable controller output. Specifically:

1. **`execute()` returns identical Raw responses for every input.** Same body sentinels (`INVALID_BODY`, `MISSING_ORDER_ID`, `LOCK_CONTENDED`, `ERROR`, `VALIDATION_FAILED`, `ORDER_PENDING`, `ALREADY_PROCESSED`, `OK`, `AMOUNT_MISMATCH`), same HTTP codes via `httpStatusFor`. The map at lines 175-182 stays in `Callback.php` verbatim.

2. **`PaymentLock::withLock` boundary preserved.** The closure passed to `withLock` is still `fn(): string => $this->handleLocked(...)` with the same four named args (`rawBody`, `bogOrderId`, `externalOrderId`, `bogStatusKey`). Inside the closure the resolver calls happen at the same logical points in the same order (`findOrder` -> conditional `findQuoteIdByBogOrderId` -> conditional `materializeOrderFromQuote`). Lock key derivation (`$bogOrderId !== '' ? $bogOrderId : $externalOrderId`) unchanged. Acquisition + release semantics unchanged (delegated to `PaymentLock` unchanged).

3. **BUG-BOG-11b: quote materialisation runs INSIDE the lock.** The call sequence inside the closure is preserved:
   ```
   $order = $this->bogOrderResolver->findOrder($externalOrderId, $bogOrderId);    // was findOrder
   if ($order === null) {
       if (!$terminalSuccess) return 'ORDER_PENDING';
       $quoteId = $this->bogOrderResolver->findQuoteIdByBogOrderId($bogOrderId);  // was findQuoteIdByBogOrderId
       if ($quoteId === null) return 'ORDER_PENDING';
       $order = $this->bogOrderResolver->materializeOrderFromQuote($quoteId, $bogOrderId);  // was materializeOrderFromQuote
       if ($order === null) return 'ERROR';
   }
   ```
   Materialise is reached by the same control flow, inside the same `withLock` closure. The resolver does not acquire its own lock and does not open its own transaction — both responsibilities stay with the caller (controller's lock here, cron's transaction in the parallel cron path).

4. **JSON LIKE patterns identical.** `'%"bog_order_id":"' . $bogOrderId . '"%'` for both `sales_order_payment` and `quote_payment`. Column names (`additional_information`), table names, parameter binding (`['needle' => $needle]`), join shape (`qp.quote_id = q.entity_id`), filter clauses (`q.is_active = 1`, `qp.method = ConfigProvider::CODE` — wait: callback uses `Config::METHOD_CODE`, cron uses `ConfigProvider::CODE`). **Verify constants resolve to the same string.** Read `Gateway/Config/Config::METHOD_CODE` and `Model/Ui/ConfigProvider::CODE` during implementation; they are both `'shubo_bogpayment'`. Resolver MUST keep `Config::METHOD_CODE` (matches the original Callback line 432), NOT swap to `ConfigProvider::CODE` — that would be a constant-identity refactor outside the split's scope.

5. **`registerCaptureNotification` payload identical.** `MoneyCaster::toMagentoFloat($order->getGrandTotal())` (line 545) is in `processSuccessfulPayment` which **stays in the controller**. No change at this call site.

6. **Log messages and contexts preserved verbatim.** Every `$this->logger->X(...)` line that moves into the resolver moves with its message string, level, and context array unchanged. Specifically:
   - `'BOG callback: order_id in payment.additional_information did not resolve'` (line 364)
   - `'BOG callback: failed to resolve order by bog_order_id'` (line 404)
   - `'BOG callback: failed to resolve quote by bog_order_id'` (line 445)
   - `'BOG callback: materialized order from pending quote'` (line 483)
   - `'BOG callback: failed to materialize order from quote'` (line 491)

   All five keep their `'BOG callback: ...'` prefix even after moving into `Service\BogOrderResolver`. The prefix is part of the operator-grep contract; it identifies the **request entry point**, not the **class name**. Cron's analogous logs use `'BOG reconciler: ...'` for the same reason.

7. **All exception handling preserved.** The resolver's three methods (`findOrderIdByBogOrderId`, `findQuoteIdByBogOrderId`, `materializeOrderFromQuote`) keep their `try/catch (\Throwable)` blocks returning null, log-and-swallow. `findOrder` keeps its `try/catch (NoSuchEntityException)` block. The controller's outer `try/catch (\Exception)` in `execute()` (lines 152-162) catches anything bubbling up from the resolver — same final ERROR + 500 outcome.

8. **`amountMismatch` and `processSuccessfulPayment` unchanged.** Both stay in the controller. The four amount-mismatch tests (#12-16) hit the same code paths.

## Out of scope

- Renaming `additional_information` keys (`bog_order_id`, `bog_status`, `bog_payment_hash`, etc.) — these are part of BUG-BOG-7's stored contract and matched by the JSON LIKE.
- Modifying the JSON LIKE indexing strategy — no functional index, no virtual column, no schema change.
- Touching `Service/PaymentLock.php` — it stays exactly as it is. The split does not change lock semantics.
- Touching `Cron/PendingOrderReconciler.php` — S10 owned that. Any quote-materialise-related change to the reconciler is a separate session.
- Touching `Gateway/Validator/CallbackValidator.php` — the validator's contract is consumed by `handleLocked` and is not affected.
- Touching `Service/MoneyCaster.php` — used only at line 545 (`processSuccessfulPayment`), which stays in the controller.
- Adding tests beyond the test-migration plan above (the four "narrow replacements for previously-implicit coverage" are explicitly justified as preservation, not new coverage).
- Extracting a second service this session. If the reviewer asks for 200 LOC, the answer is "next session, `Service\PaymentCapturer` for `processSuccessfulPayment`" — not now.
- Crontab schedule, system.xml fields, ACL, GraphQL, frontend — none are touched.
- TBC parallel split — `app/code/Shubo/TbcPayment/Controller/Payment/Callback.php` is a separate session if it grows similar.

## Risk register

- **R1: Constructor positional-arg breakage.** All `CallbackTest` builds via named args (lines 922-938). The DI container always uses named-arg resolution. Removing four args + adding one is safe across both. Verified.
- **R2: di.xml constant-name reference.** `<type name="Shubo\BogPayment\Controller\Payment\Callback">` at line 179 stays valid (FQCN unchanged). The new `<type name="Shubo\BogPayment\Service\BogOrderResolver">` block (6 lines) is additive. No risk to the existing block.
- **R3: Controller still depends on `OrderRepositoryInterface` after the split.** `processSuccessfulPayment` calls `$this->orderRepository->save($order)` (line 554). The dep stays in the controller's ctor. Not a regression.
- **R4: Logger channel routing.** Both controller and resolver receive `ShuboBogPaymentLogger` via di.xml, so all `BOG callback: ...` log lines keep landing in the BOG channel regardless of which class they fired from. Verified above.
- **R5: PHPStan level 8.** The resolver's `?Order` return types and `?int` return types match the originals byte-for-byte. The `instanceof Order` narrow at line 360 of the original moves with the method body. The `instanceof \Magento\Quote\Model\Quote` narrow at line 467 likewise. No new type errors expected.
- **R6: Mock surface in `CallbackTest`.** Replacing four mocks with one (`BogOrderResolver`) requires editing 14 tests' setUp. The replacement is mechanical: every place that previously stubbed `orderCollectionFactory->method('create')` etc. instead stubs `bogOrderResolver->method('findOrder')` to return the fixture Order directly. **No assertion changes.** Quality-gates discipline: run `vendor/bin/phpunit app/code/Shubo/BogPayment/Test/Unit/Controller` after edits.
- **R7: `Cron/Reconciler/QuoteReconciler` drift.** Today both the cron and the callback materialisers are independent. After the split they're still independent. If a future bug fix lands in one but not the other, the reviewer or the audit pass will catch it via "two LIKE patterns identical, two materialise bodies similar, fix one -> fix both." Not a new risk introduced by this split — the duplication exists today.

## Verification plan

After the commit:
- `make stan PATH=app/code/Shubo/BogPayment` -> 0 errors.
- `vendor/bin/phpunit app/code/Shubo/BogPayment/Test/Unit/Controller/Payment/CallbackTest.php` -> 13 tests, green.
- `vendor/bin/phpunit app/code/Shubo/BogPayment/Test/Unit/Service/BogOrderResolverTest.php` -> 5 tests, green.
- `vendor/bin/phpunit app/code/Shubo/BogPayment/Test` -> module suite green.
- `make lint PATH=app/code/Shubo/BogPayment` -> 0 violations.
- `wc -l app/code/Shubo/BogPayment/Controller/Payment/Callback.php` -> ~415 LOC (target is the floor explained above; 200 is not the floor for this module).
- `wc -l app/code/Shubo/BogPayment/Service/BogOrderResolver.php` -> ~170 LOC.

After the commit, append `KNOWN_ISSUES.md` entry with the commit SHA and "RESOLVED" tag, citing this design doc — same workflow as S9 and S10.
