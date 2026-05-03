# Session 8 — BOG Hardening Reviewer Sign-off

**Date:** 2026-04-25
**Reviewer:** reviewer (Opus 4.7)
**Pass:** 2 (re-verification of Pass-1 findings)

---

## Per-finding final verdict

| Finding | Verdict | Evidence |
|---|---|---|
| **M-1** Amount-mismatch tree-depth bug | **APPROVE** | `Controller/Payment/Callback.php:315-336` — `amountMismatch()` now unwraps `body` defensively (`$container = is_array($data['body'] ?? null) ? $data['body'] : $data;`) before reading `purchase_units.total_amount`. Multi-line WHY-comment at lines 303-311 cites the `CallbackValidator::extractOrderStatusKey()` precedent at line 113 and explains the signature-path-vs-status-API divergence. Two new regression tests landed: `CallbackTest::testAmountMismatchTriggersOnRealCallbackEnvelopeShape` (lines 805-867) drives the full envelope `{event, zoned_request_time, body: {…purchase_units…}}` shape through `validate()` and asserts `AMOUNT_MISMATCH` + HTTP 400 + critical log row with `bog_amount_minor=7500, order_amount_minor=3000`. `testAmountMismatchHandlesFlatStatusApiShape` (lines 873-920) covers the receipt-shape path (no `body` wrapper) — both paths exercised. The two original tests (`testAmountMismatchAbortsWithHttp400`, `testAmountWithin1TetriToleranceProcessesNormally`) were updated to use the production envelope shape. |
| **S-1** CheckStatus generic Exception leak | **APPROVE** | `Controller/Adminhtml/Order/CheckStatus.php:103-144` — three-clause catch landed: `BogApiException` (lines 103-119) routes through `UserFacingErrorMapper::toLocalizedException(0, …)` with raw message logged separately; `LocalizedException` (lines 120-128) flows through verbatim with author-safe rationale comment; generic `\Exception` (lines 129-143) logs the full triple (`exception_class`, `error`, `trace`) then surfaces only `__('Status check failed. See shubo_bog_payment.log for details.')`. `$e->getMessage()` is NEVER concatenated into the admin message. New `Test/Unit/Controller/Adminhtml/Order/CheckStatusErrorRoutingTest.php` carries 3 tests, one per branch. The generic-exception test plants a `RuntimeException('Internal trace: secret_token=abc123, db_password=xyz')` and asserts the captured admin error contains none of `secret_token`, `abc123`, or `db_password` — secret-leak contract pinned. |
| **S-2** Capture admin leak + RuntimeException routing | **APPROVE** | `Controller/Adminhtml/Order/Capture.php:115-150` — same three-clause pattern (`BogApiException` → mapper, `LocalizedException` → verbatim, generic `\Exception` → bland message + raw-triple log). The empty-`bog_order_id` case at lines 56-65 now throws `LocalizedException(__('No BOG order ID found on this order.'))` with an explicit comment block explaining why `RuntimeException` would land in the bland generic catch and hide the actionable identifier from the admin. `PaymentMethodGuardTest::testCorrectPaymentMethodPassesGuard` is in the green test count of 144. |

---

## Cross-cutting gates

- **PHPUnit BOG:** 144 tests / 420 assertions all green. Up from 141/—  in Pass-1 = +3 tests for `CheckStatusErrorRoutingTest`. No skipped/risky/incomplete. PASS.
- **PHPStan level 8:** `vendor/bin/phpstan analyse -c /var/www/html/phpstan.neon --level 8 app/code/Shubo/BogPayment` → 0 errors across 44 files. PASS.
- **PHPCS Magento2 sev=10:** 0 errors. The single pre-existing void-tag warning in `view/frontend/web/template/payment/shubo-bog.html` carries forward unchanged (mirror of the TBC template warning, unrelated to this session). PASS.
- **No new anti-patterns:** No ObjectManager, no concrete Model imports across modules, no float-money in the mismatch path (integer-tetri arithmetic preserved per CLAUDE.md proactive standards #6). LocalizedException replaces RuntimeException at the Capture identifier check — proper Magento author-safe surface. PASS.
- **No security concerns introduced:**
  - The amount-mismatch guard now correctly fires on the real production envelope shape (was silently a no-op on the signature path before M-1 fix — meaning a malicious BOG-side amount inflation attempt would have processed unchallenged). This is a meaningful security hardening, not just correctness.
  - Generic-catch leak vector closed at both admin entry points (CheckStatus + Capture). `secret_token`/`db_password`/trace text from any internal exception now stays in `shubo_bog_payment.log`, never in the admin toast.
  - PASS.

---

## Deferred items (next session, explicitly out of scope here)

- **S-3** Lifecycle Playwright specs are structural-only (partial-refund / full-refund authors honestly commented the limitation in-spec). To be filled in once BOG sandbox refund credentials are wired through to CI. Open with note.
- **S-4** Standalone mirror sync (`~/module-bog-payment/`) is the developer's next step before the duka push. Pre-push hook (`check_public_module_sync`) will block if drift remains, so this is enforced at push time. Open with note.

---

## Push readiness

**APPROVE TO PUSH.**

All three Pass-1 fixes (M-1 amount-mismatch envelope unwrap, S-1 CheckStatus three-clause routing, S-2 Capture three-clause routing + LocalizedException at the identifier check) landed at the right files with appropriate WHY-comments and matching regression tests. Quality gates (PHPCS / PHPStan L8 / PHPUnit 144/144) all green with a healthy +3 test increase from `CheckStatusErrorRoutingTest`. The secret-leak negative assertions in the new error-routing test are particularly strong — they pin the contract that "raw exception text never reaches the admin UI" and will fail loudly if anyone ever re-introduces a `__('… %1', $e->getMessage())` pattern.

Sync the standalone mirror at `~/module-bog-payment/` (S-4) and push.
