# PendingOrderReconciler god-class split — design (2026-05-01)

## Problem

`app/code/Shubo/BogPayment/Cron/PendingOrderReconciler.php` is **844 LOC** with 16 methods across three unrelated concerns: order-status reconciliation (6 status handlers), quote-recovery (BUG-BOG-11b), and shared helpers. Tracked in `KNOWN_ISSUES.md` line 291. This is the BOG-side analogue of last session's `Shubo\Payout\Model\ReportService` split — same shape, same approach.

## Constraints (verified in repo)

1. **`PendingOrderReconciler::execute()` is the cron entry point.** `etc/crontab.xml` (not shown but bound by FQCN convention) calls this method. Signature MUST stay `public function execute(): void`.
2. **`etc/di.xml` lines 239-245** wire 3 of the 14 constructor args (`logger`, `creditmemoFactory`, `creditmemoManagement`) by name. Constructor named-arg shape MUST stay backward-compatible — existing `<arguments>` block must keep working unchanged.
3. **Existing test contract** — `Test/Unit/Cron/PendingOrderReconcilerTest.php` (574 LOC, 11 tests, 25 assertions) constructs `PendingOrderReconciler` via named args (lines 511-526) with exactly the current 14 deps. Per session prompt the orchestrator constructor MAY grow but **the existing test must keep passing byte-identically with no test edits**. This means:
   - The 14 current named args (`orderRepository`, `searchCriteriaBuilder`, `sortOrderBuilder`, `statusClient`, `config`, `orderSender`, `logger`, `resourceConnection`, `appState`, `paymentLock`, `cartManagement`, `cartRepository`, `creditmemoFactory`, `creditmemoManagement`) must remain accepted by the constructor.
   - Any new handler-collaborator deps must have **defaults** (nullable + late-built via `ObjectManager` lazy fallback OR — simpler — kept absent and the orchestrator owns `new` of the collaborators using its existing deps). See "Constructor strategy" below.
4. **BUG-BOG-6 (`PaymentLock::withLock`) call sites** — line 217 (`reconcileOrder`) and line 713 (`reconcileQuote`). The lock key is `$bogOrderId`, the wrapped closure encloses `$response` + `$orderStatusKey`. Lock acquisition order, key, and closure boundaries MUST be identical.
5. **BUG-BOG-12 idempotency guards** — each handler has its own state-machine + idempotency check (e.g. `hasCreditmemos()` early-return at line 418, `STATE_CLOSED/STATE_CANCELED` no-ops at lines 362, 472, 537). These MUST move with the handler verbatim.
6. **`handleApproved` is reachable from two paths** — `reconcileOrder::match` (line 240) AND `materializeQuote` (line 778). Both paths must continue to dispatch to the same handler instance.
7. **`handleRejectedOrCancelled` recurses into `handleReversed`** for processing/complete states (line 393). After the split this becomes a cross-handler call — must be wired through composition, not handler-knows-handler.
8. **Integer-tetri math** in `handleRefunded` / `handleReversed` / `extractMinorAmount` (lines 426, 476, 580-596) MUST move byte-for-byte. CLAUDE.md #6 financial-arithmetic rule.

## Decision: facade + per-status handlers + helper struct, NO new interfaces

Picking **Option A — explicit per-handler classes** over Option B (shared `StatusHandlerInterface`).

### Why Option A over Option B

- **Handlers do not have a uniform signature.** `handleApproved($order, $payment, $response)` needs the `Payment`. `handleRefunded($order, $response)` doesn't. `handleExpired($order)` doesn't even take the response. `handleRejectedOrCancelled($order, $status, $response)` has a third `$status` arg. Forcing them through a single `handle(Order, Payment, array)` interface either (a) inflates each handler's signature with unused args, or (b) embeds branching back into each handler to extract its own deps from a bag — both are worse than the current shape.
- **No second caller.** Per CLAUDE.md `### Simplicity-first`: an interface earns its existence when there's a second implementation OR a second caller. Neither exists. A future TBC reconciler split would NOT share the interface — TBC has different status names (`ok`/`failed` vs BOG's 6-way matrix). The strategy interface is speculative reuse with zero current consumer.
- **Match-dispatch stays trivial.** With named handler properties, `match($status) { 'completed', 'captured' => $this->approved->handle(...) }` reads as well as the current inline `=> $this->handleApproved(...)`. A status→handler array indirection adds nothing.
- **Tests don't benefit from the interface.** Each handler is unit-testable as a concrete class; mocks go in via constructor.

### Why Option C rejected outright

VirtualType-keyed handlers sharing a single PHP class need constructor-injected behavior arrays — that's a bag of closures or template methods. Far more abstraction than the 6-handler problem warrants.

## Shape

```
PendingOrderReconciler  ← thin orchestrator / cron entry point, ~190 LOC
        │
        ├── owns 6 handler instances (constructed in __construct, no DI)
        │     Cron\Reconciler\StatusHandler\ApprovedHandler
        │     Cron\Reconciler\StatusHandler\RejectedOrCancelledHandler  (holds ref to ReversedHandler)
        │     Cron\Reconciler\StatusHandler\RefundedHandler
        │     Cron\Reconciler\StatusHandler\ReversedHandler
        │     Cron\Reconciler\StatusHandler\ChargebackHandler
        │     Cron\Reconciler\StatusHandler\ExpiredHandler
        │
        ├── owns 1 quote-recovery service
        │     Cron\Reconciler\QuoteReconciler  (findPendingQuotes, materializeQuote, deactivateQuote)
        │
        └── keeps inline:
              findPendingOrders()  (1 method, 1 caller, no reuse — INLINE per Simplicity-tree)
              extractMinorAmount() — moves to MoneyHelpers (shared by Refunded + Reversed)
              storePaymentDetails() — moves into ApprovedHandler (sole caller)
```

### Handler signatures

All handlers are concrete classes, NOT a shared interface. Each gets exactly the deps it needs.

| Class | Public method | Deps |
|---|---|---|
| `ApprovedHandler` | `handle(Order, Payment, array $response): void` | `OrderRepositoryInterface`, `Config`, `OrderSender`, `LoggerInterface` |
| `RejectedOrCancelledHandler` | `handle(Order, string $status, array $response): void` | `OrderRepositoryInterface`, `LoggerInterface`, `ReversedHandler` (composed for the post-capture branch) |
| `RefundedHandler` | `handle(Order, array $response): void` | `OrderRepositoryInterface`, `CreditmemoFactory`, `CreditmemoManagementInterface`, `LoggerInterface`, `MoneyHelpers` |
| `ReversedHandler` | `handle(Order, array $response): void` | `OrderRepositoryInterface`, `LoggerInterface`, `MoneyHelpers` |
| `ChargebackHandler` | `handle(Order, array $response): void` | `OrderRepositoryInterface`, `LoggerInterface` |
| `ExpiredHandler` | `handle(Order): void` | `OrderRepositoryInterface`, `LoggerInterface` |

### Helper

`Cron\Reconciler\MoneyHelpers` — single static-style method `extractMinorAmount(array, list<string>, int): int`. Shared by `RefundedHandler` + `ReversedHandler`. ~25 LOC.

(Considered: leave `extractMinorAmount` duplicated in both handlers — rejected. It's pure logic with the explicit "defeat float `==`" CLAUDE.md #6 contract; one place to look at it is correct.)

### Constructor strategy (preserves test compatibility)

The orchestrator constructor keeps **all 14 current named args** unchanged. Inside the constructor, it eagerly `new`s the 6 handlers + `MoneyHelpers` + `QuoteReconciler` from those existing deps. **No new constructor args. No new DI wiring.**

```php
public function __construct(
    OrderRepositoryInterface $orderRepository,
    SearchCriteriaBuilder $searchCriteriaBuilder,
    SortOrderBuilder $sortOrderBuilder,
    StatusClient $statusClient,
    Config $config,
    OrderSender $orderSender,
    LoggerInterface $logger,
    ResourceConnection $resourceConnection,
    AppState $appState,
    PaymentLock $paymentLock,
    CartManagementInterface $cartManagement,
    CartRepositoryInterface $cartRepository,
    CreditmemoFactory $creditmemoFactory,
    CreditmemoManagementInterface $creditmemoManagement,
    int $quoteTtlHours = self::DEFAULT_QUOTE_TTL_HOURS,
) {
    $money    = new MoneyHelpers();
    $reversed = new ReversedHandler($orderRepository, $logger, $money);
    $this->approved             = new ApprovedHandler($orderRepository, $config, $orderSender, $logger);
    $this->rejectedOrCancelled  = new RejectedOrCancelledHandler($orderRepository, $logger, $reversed);
    $this->refunded             = new RefundedHandler($orderRepository, $creditmemoFactory, $creditmemoManagement, $logger, $money);
    $this->reversed             = $reversed;
    $this->chargeback           = new ChargebackHandler($orderRepository, $logger);
    $this->expired              = new ExpiredHandler($orderRepository, $logger);
    $this->quoteReconciler      = new QuoteReconciler(
        $resourceConnection, $statusClient, $cartManagement, $cartRepository,
        $paymentLock, $this->approved, $orderRepository, $logger, $quoteTtlHours,
    );
    // ... assign remaining $this->* used by orchestrator (orderRepository, ...,
    // searchCriteriaBuilder, sortOrderBuilder, statusClient, paymentLock,
    // resourceConnection, appState)
}
```

This violates the "DI only" preference at face value but is **justified** here because (a) handlers are owned solely by the orchestrator with zero external consumers, (b) wiring 6 sub-handlers in `di.xml` adds 30+ lines of XML for zero behavioral or testability gain, (c) most importantly **the existing test must keep building the orchestrator with its current 14 args** — adding handlers as required constructor args breaks the test contract. The handlers are unit-testable in isolation regardless (their constructors take only Magento interfaces / scalars).

## LOC projection

| File | LOC target |
|---|---|
| `Cron/PendingOrderReconciler.php` (orchestrator)            | ~190 |
| `Cron/Reconciler/StatusHandler/ApprovedHandler.php`         | ~95  |
| `Cron/Reconciler/StatusHandler/RejectedOrCancelledHandler.php` | ~65  |
| `Cron/Reconciler/StatusHandler/RefundedHandler.php`         | ~75  |
| `Cron/Reconciler/StatusHandler/ReversedHandler.php`         | ~80  |
| `Cron/Reconciler/StatusHandler/ChargebackHandler.php`       | ~55  |
| `Cron/Reconciler/StatusHandler/ExpiredHandler.php`          | ~30  |
| `Cron/Reconciler/QuoteReconciler.php`                       | ~165 |
| `Cron/Reconciler/MoneyHelpers.php`                          | ~30  |

Orchestrator hits the ≤ 200 LOC target. Every handler ≤ 100 LOC. Total LOC across new files (~795) is slightly less than the original 844 because the per-handler docblocks consolidate and the match-dispatch loses its inline docs.

## Simplicity-tree walks

### `StatusHandler\ApprovedHandler` (NEW)
- **Delete?** No — capture-path is the primary success outcome.
- **Reuse?** No analogous Magento class exists; the registerCaptureNotification + preauth branch is BOG-specific.
- **Inline?** That's where it is today. The whole task is to un-inline.
- **New** ✓.

### `StatusHandler\RejectedOrCancelledHandler` (NEW)
- **Delete?** No.
- **Reuse?** No.
- **Inline?** Same — un-inline target.
- **New** ✓. Note: holds a composed `ReversedHandler` reference for the post-capture branch (line 393 today). Composition over recursion.

### `StatusHandler\RefundedHandler` (NEW)
- **Delete?** No — BUG-BOG-12 contract.
- **Reuse?** No creditmemo-by-cron service elsewhere.
- **Inline?** Un-inline target.
- **New** ✓.

### `StatusHandler\ReversedHandler` (NEW)
- **Delete?** No — BUG-BOG-12.
- **Reuse?** No.
- **Inline?** Un-inline target.
- **New** ✓. Reused by `RejectedOrCancelledHandler` (post-capture branch).

### `StatusHandler\ChargebackHandler` (NEW)
- **Delete?** No.
- **Reuse?** Could it just call `ReversedHandler` since post-capture is "full reversal + tag"? Considered — rejected. The pre-capture branch differs (chargeback comment vs reverse comment), the post-capture branch sets `STATE_CLOSED` directly without amount math (chargebacks are always full), and the comment text is chargeback-tagged for the audit trail. Folding into Reversed would add a `$reasonTag` parameter and conditionals — more complex than 55 LOC of explicit code.
- **Inline?** Un-inline target.
- **New** ✓.

### `StatusHandler\ExpiredHandler` (NEW)
- **Delete?** No.
- **Reuse?** Could share with `RejectedOrCancelledHandler` pre-capture branch — rejected. Expired only has the cancel-path; it doesn't carry a `$status` arg, doesn't take `$response`, doesn't have a post-capture branch. Folding it in would force a 4-state flag.
- **Inline?** Un-inline target.
- **New** ✓.

### `Cron\Reconciler\QuoteReconciler` (NEW)
- **Delete?** No — BUG-BOG-11b.
- **Reuse?** No quote-recovery service exists.
- **Inline?** Three methods (`findPendingQuotes`, `reconcileQuote`/`materializeQuote`, `deactivateQuote`) totalling ~165 LOC and one of two top-level concerns of the cron. Inline = leaving them in the orchestrator, which puts us back at 350+ LOC. Reject inline.
- **New** ✓.

### `Cron\Reconciler\MoneyHelpers` (NEW)
- **Delete?** No — `extractMinorAmount` is the only float-defeating helper and is shared between Refunded + Reversed.
- **Reuse?** `Service\MoneyCaster` exists but does the inverse (minor → Magento float). Could add a static method there — rejected because MoneyCaster is in the gateway boundary, while `extractMinorAmount` is reconciler-specific (knows about candidate-key fallback semantics).
- **Inline?** Two callers across two files = duplication. Inlining to one violates DRY for a tested-precision-critical helper.
- **New** ✓ — but kept tiny (one method).

### `findPendingOrders` (KEPT INLINE in orchestrator)
- **Delete?** No.
- **Reuse?** No.
- **Inline?** ✓ — single method, single caller (`execute`), 50 LOC, no shared logic. Per Simplicity-tree rung 3 (1:1, stable, single-module): keep inline. Pulling it into a `PendingOrderFinder` service adds a class for zero benefit.
- **No new abstraction** ✓.

### `Cron\Reconciler\ReconcilerSupport` (REJECTED)
Considered: bundle `findPendingOrders` + `extractMinorAmount` + `storePaymentDetails` into a shared support class.
- `findPendingOrders` has 1 caller and is tightly coupled to orchestrator state — wrong fit.
- `storePaymentDetails` has 1 caller (`ApprovedHandler`) — belongs on the handler.
- `extractMinorAmount` has 2 callers — earns its own tiny class (`MoneyHelpers`).
Bundling them into a dump service violates SRP. Three separate decisions, three separate outcomes.

### `StatusHandlerInterface` (REJECTED — see Decision section above)

## Test redistribution plan

**Keep all 11 existing tests in `Test/Unit/Cron/PendingOrderReconcilerTest.php` unchanged.** They are orchestrator-level integration-style tests — they exercise the full match-dispatch + lock + per-handler behavior. After the split they exercise the same paths through composed handlers and prove the wiring is intact. **No edits to the existing test file.** Test count stays at 11; assertion count stays at 25 (≥ baseline as required).

**Add per-handler unit tests as a follow-up commit (Step 5)**, NOT required for the refactor to land:

| New test file | Tests |
|---|---|
| `Test/Unit/Cron/Reconciler/StatusHandler/ApprovedHandlerTest.php` | preauth path, capture path, already-processing no-op, email-failure swallowed |
| `Test/Unit/Cron/Reconciler/StatusHandler/RefundedHandlerTest.php` | full-amount creditmemo, partial-amount creditmemo, idempotent skip when hasCreditmemos, exception swallowed |
| `Test/Unit/Cron/Reconciler/StatusHandler/ReversedHandlerTest.php` | pre-capture cancel, full close, partial comment-only, terminal-state idempotent |
| `Test/Unit/Cron/Reconciler/StatusHandler/RejectedOrCancelledHandlerTest.php` | pre-capture cancel, post-capture delegates to ReversedHandler, terminal idempotent, unknown-state warn |
| `Test/Unit/Cron/Reconciler/StatusHandler/ChargebackHandlerTest.php` | pre-capture cancel + tag, post-capture close + tag, terminal idempotent |
| `Test/Unit/Cron/Reconciler/StatusHandler/ExpiredHandlerTest.php` | cancel + log |
| `Test/Unit/Cron/Reconciler/QuoteReconcilerTest.php` | TTL deactivation, materialize on completed, deactivate on expired/rejected, in_progress no-op |
| `Test/Unit/Cron/Reconciler/MoneyHelpersTest.php` | candidate-key fallback, default on missing, default on zero/negative, integer-tetri rounding |

These are scoped follow-ups — the refactor's correctness is enforced by the 11 byte-identical orchestrator tests staying green.

## Migration plan (commit order)

Each commit is independently buildable and bisect-friendly. The orchestrator stays fully fat until the final commit; intermediate commits introduce handlers alongside the original methods (handlers are dead code until commit 6 wires them in).

1. `refactor(bog): extract MoneyHelpers + ExpiredHandler from PendingOrderReconciler` — smallest leaves first, prove the test suite stays green with new files in the tree.
2. `refactor(bog): extract ChargebackHandler + ApprovedHandler` — independent handlers, no cross-handler refs.
3. `refactor(bog): extract ReversedHandler + RefundedHandler` — Reversed first (zero internal deps), Refunded second.
4. `refactor(bog): extract RejectedOrCancelledHandler` — composes ReversedHandler.
5. `refactor(bog): extract QuoteReconciler` — owns the BUG-BOG-11b path; composed with ApprovedHandler.
6. `refactor(bog): reduce PendingOrderReconciler to orchestrator` — replace inline handler methods with `$this->handlerName->handle(...)` delegations, delete the now-dead originals. Re-run full test suite. ReportService precedent followed: target file shrinks 844 → ~190 LOC.
7. (Follow-up, separate session) `test(bog): add per-handler unit tests`.

After commit 6, `PendingOrderReconciler.php` ≤ 200 LOC and `KNOWN_ISSUES.md` line 291 entry can be marked RESOLVED with a citation to this design doc.

## Behavior-parity guarantees

1. **Same status × state matrix → same outcome.** Each handler's body is copied byte-for-byte from its origin method (same conditionals, same comments, same logger calls).
2. **PaymentLock contract preserved.** `withLock($bogOrderId, fn() => ...)` stays in the orchestrator's `reconcileOrder` and in `QuoteReconciler::reconcileQuote`. Lock key, key shape, and closure scope unchanged.
3. **DB transaction boundaries preserved.** `$connection->beginTransaction()` / `commit()` / `rollBack()` blocks at lines 237 (orchestrator) and 776 (materializeQuote) move with their owning code.
4. **Idempotency guards intact.** Each handler's early-return on `STATE_CLOSED`/`STATE_CANCELED`/`hasCreditmemos()` moves with the handler.
5. **Integer-tetri math intact.** `MoneyHelpers::extractMinorAmount` is a verbatim copy of lines 580-596. `RefundedHandler` + `ReversedHandler` keep their `(int) round(((float)$grandTotal) * 100)` lines verbatim.
6. **`handleApproved` reachable from materialize path.** `QuoteReconciler` constructor receives the same `ApprovedHandler` instance the orchestrator owns, so `materializeQuote` calls `$this->approved->handle(...)` — same dispatch, same instance.
7. **Logger messages identical.** Same prefix `'BOG reconciler: ...'`, same context arrays, same levels.

## Risk register

- **R1: Test mock setUp covers shared deps.** The 11-test fixture mocks 14 deps; handlers receive subsets of those mocks via composition. As long as the orchestrator's `__construct` builds handlers from the SAME mocked instances, the existing assertions on `$this->orderRepository->expects(self::atLeastOnce())->method('save')` keep matching. Verified: every handler that calls `save()` receives `$this->orderRepository` from the orchestrator's constructor.
- **R2: Cross-handler composition (Rejected → Reversed) cannot use cyclic refs.** Reversed is constructed first; Rejected receives it. One-way dependency. Verified above.
- **R3: `materializeQuote` transaction boundary.** Today `materializeQuote` (in orchestrator) opens its own transaction and calls `handleApproved`. After split, `QuoteReconciler::materializeQuote` opens the transaction and calls `$this->approved->handle()` — handler must NOT also open a transaction. Verified: `ApprovedHandler::handle()` does not touch `$resourceConnection` (it never did — that was orchestrator-level).
- **R4: DI cache.** Adding new class files requires `bin/magento setup:di:compile` in production. Document in commit 6 message.
- **R5: TBC reconciler portability.** `app/code/Shubo/TbcPayment/Cron/PendingOrderReconciler.php` exists at **460 LOC** — half BOG's size, simpler status matrix (`ok`/`failed` vs BOG's 6-way). NOT in scope for this session per session prompt. The handler-class shape would mostly port but the helpers wouldn't — leave for a future audit pass.

## Verification plan

After each commit:
- `make stan PATH=app/code/Shubo/BogPayment` → 0 errors
- `vendor/bin/phpunit app/code/Shubo/BogPayment/Test/Unit/Cron` → all 11 tests pass, 25 assertions
- `make lint PATH=app/code/Shubo/BogPayment` → 0 violations

After commit 6 (final):
- `wc -l app/code/Shubo/BogPayment/Cron/PendingOrderReconciler.php` → ≤ 200
- `wc -l app/code/Shubo/BogPayment/Cron/Reconciler/StatusHandler/*.php` → each ≤ 100
- Reviewer pass: byte-for-byte diff that the moved method bodies match the originals (same as Payout precedent's "eyeball comparison").

## Out of scope

- TBC reconciler split (separate session).
- Adding tests for the new handlers (separate follow-up commit; not gating).
- Any change to `Callback.php`, `ReturnAction.php`, `StatusClient.php`, `PaymentLock.php`.
- Crontab schedule, frequency, or `crontab.xml`.
- BUG-BOG-12 status-matrix changes (status names, state transitions, idempotency rules).
- Database schema.
