# Session 8 — BOG payment hardening · Architect scope

**Date:** 2026-04-25
**Mirrors:** Session 3 (TBC hardening) — same problem class, BOG-specific shape.
**Status:** Approved by architect, ready for developer.

---

## 0. Investigation summary (read-current-state)

| Area | Current state | Notes |
|---|---|---|
| `setParentTransactionId` call sites | **0 hits** in `app/code/Shubo/BogPayment/` | TBC's P3.1 issue does not exist here. |
| `etc/di.xml:118` `ShuboBogRefundCommand` validator | `ResponseValidator` wired in | **Same M-1 problem class as TBC** — preempts handler on non-2xx. |
| `Gateway/Http/Client/RefundClient.php:51` | POSTs to `Config::getRefundUrl()` | URL is `${api_url}/checkout/refund` — that is the **legacy iPay shape**, not the new Payments API. |
| `Gateway/Request/RefundRequestBuilder.php:36-40` | Body: `{order_id, amount}` | Matches legacy iPay form-encoded body. |
| `Gateway/Response/RefundHandler.php` | Sets `bog_refund_status`, `transaction_id`. No error routing. | Will need raw-triple log + UserFacingErrorMapper integration mirroring TBC. |
| `Gateway/Validator/CallbackValidator.php` | Two-phase: SHA256withRSA primary, status-API fallback. | Solid. Test path needs a test keypair. |
| `Controller/Payment/Initiate.php` | Has `probeExistingBogOrder()` (BUG-BOG-13b) | **Already covers double-click + already-paid edge.** |
| `Controller/Payment/Callback.php` | Quote-only materialization (BUG-BOG-11b), payment lock (BUG-BOG-6), HTTP code mapping (BUG-BOG-10) | Strong. |
| `Cron/PendingOrderReconciler.php` | Handles refunded / reversed / chargeback (BUG-BOG-12), expired, rejected, declined, error. Quote scan + materialization. | Strong. |
| `Controller/Payment/AbortRedirect.php` | Exists | Handles user cancel-on-BOG-page. |
| Tests | 8 unit-test files exist | Need additions: refund pipeline test, error-mapper unit tests, regression guards. |

**Conclusion:** BOG module is already much further along the "robust redirect-mode flow" curve than TBC was at start of Session 3. The remaining gaps are concentrated in:
1. **The refund pipeline** (M-1 validator preemption + wrong endpoint URL).
2. **Friendly error UX layer** (HTTP-status based, not error-code based — BOG returns no Flitt-style error code).
3. **Lifecycle test coverage** (5 specs converted from S12 iframe-stuck to REST + signed callback).
4. **Amount-tampering edge case** (order-amount-changes-mid-flow not blocked anywhere today).
5. **Documentation** that the parent_transaction_id pattern is intentionally absent + regression-guarded.

---

## 1. Priority 1.1 — Online credit memo (RCA + fix)

### 1.1.1 Root cause

`etc/di.xml:118` wires `Shubo\BogPayment\Gateway\Validator\ResponseValidator` as the validator on the `ShuboBogRefundCommand` virtualType. Magento's `\Magento\Payment\Gateway\Command\GatewayCommand::execute()` calls the validator AFTER the client returns and BEFORE the handler. On any non-2xx HTTP status, the validator returns `isValid=false` with error messages, and `processErrors()` raises a generic `CommandException` — so the handler never runs and any future `UserFacingErrorMapper` integration there would be unreachable. **Identical to TBC reviewer-signoff M-1.**

Compounding bug: `Config::getRefundUrl()` returns `${api_url}/checkout/refund`. With the new Payments API base URL `https://api.bog.ge/payments/v1`, the correct refund endpoint is `POST /payments/v1/payment/refund/{order_id}` per the BOG API map (and the TBC-equivalent rewrite). The legacy `/checkout/refund` form-encoded endpoint is from the old iPay API, which we're no longer wiring.

### 1.1.2 Fix

| Change | File | Why |
|---|---|---|
| Drop `<argument name="validator">` from `ShuboBogRefundCommand` | `etc/di.xml:118` | M-1 — handler must be the sole gatekeeper for refund failures so it can route through the mapper. |
| `RefundHandler` — log raw triple at ERROR + throw mapped LocalizedException on failure | `Gateway/Response/RefundHandler.php` | Mirrors TBC RefundHandler pattern verbatim. |
| `Config::getRefundUrl(string $bogOrderId, ?int $storeId = null)` returns `${api_url}/payment/refund/{$bogOrderId}` | `Gateway/Config/Config.php` | Switch from legacy iPay endpoint to new Payments API endpoint. |
| `RefundRequestBuilder` returns body `{amount, order_id}` (order_id stays in body for client to read URL from) | `Gateway/Request/RefundRequestBuilder.php` | Body shape unchanged externally; `RefundClient` extracts order_id, builds URL, removes from body. |
| `RefundClient` — extract `order_id` from body, build URL via `Config::getRefundUrl($orderId)`, drop `order_id` from POST body | `Gateway/Http/Client/RefundClient.php` | Matches new API shape. |
| `RefundRequestBuilder` validates non-empty `bog_order_id`; throws `LocalizedException` on miss | `Gateway/Request/RefundRequestBuilder.php` | Currently silently sends empty `order_id` — would 404 at BOG. Loud-fail. |
| `RefundCommandPipelineTest` (new) — drives real `GatewayCommand` through the full pipeline | `Test/Unit/Gateway/Command/RefundCommandPipelineTest.php` | Regression guard against the validator coming back. |

### 1.1.3 Tests

- New `RefundCommandPipelineTest` — 2 tests: `testHttp4xxSurfacesFriendlyMappedException`, `testRefundedResponseRunsHandlerPersistenceWithoutThrow`. Constructs a real `GatewayCommand` with our real `RefundRequestBuilder` + a stub `ClientInterface` returning a BOG 400 envelope. **Pipeline must surface `LocalizedException`, NOT `CommandException`.** Mirrors TBC's M-1 regression-guard test.
- `RefundHandlerTest` (new) — handler unit tests covering: success path persistence, 4xx error path mapped through mapper, missing bog_order_id (handler still runs but logs).
- `RefundRequestBuilderTest` (new) — happy path + empty-bog_order_id throws.

### 1.1.4 RCA artifact

`docs/online-refund-rca.md` — explains the M-1 validator preemption, the wrong-URL bug, and the fix.

---

## 2. Priority 1.2 — Lifecycle Playwright (Option A)

### 2.1.1 Pattern

Mirror `tests/e2e/payments/tbc-sandbox-lifecycle/*-rest.spec.ts`. Each spec:
1. Place a guest order via Magento REST with `payment_method=shubo_bog`.
2. POST a signed callback to `/shubo_bog/payment/callback` with appropriate `body` payload + `Callback-Signature` header.
3. Assert the order moves to expected state via REST polling.

### 2.1.2 Signing strategy — RSA test keypair

BOG uses SHA256withRSA + RSA public key from admin config. Two paths to make tests deterministic:

**Chosen: dedicated test keypair.**

- Generate a 2048-bit RSA keypair under `tests/e2e/payments/_lib/helpers/bog-test-rsa/` (`private-key.pem`, `public-key.pem`). Sandbox-only, never used in prod, gitignored if Nika prefers (we'll commit with TEST-ONLY warning headers).
- `setBogTestPublicKey()` helper writes the test public PEM to Magento config via `bin/magento config:set payment/shubo_bog/rsa_public_key <PEM>` and flushes config cache. Returns previous value for restoration.
- `signAndPostBogCallback(bogOrderId, status, amountMinor, opts)` helper:
  1. Builds the BOG callback body shape: `{event: "order_payment", zoned_request_time, body: {order_id, external_order_id, order_status: {key}, amount, ...}}`.
  2. Signs the **raw JSON body** with the test private key using `crypto.sign('SHA256', body, privateKey)` → base64.
  3. POSTs to `/shubo_bog/payment/callback` with `Callback-Signature: <base64>` header.

This works because `getRsaPublicKey()` decrypts the stored value, but Magento's encryptor passes plaintext through `decrypt()` unchanged when no encryption marker is detected. Tested in TBC's `setTbcCheckoutType` pattern.

### 2.1.3 Specs (5 → 5 active + N old `.iframe-skipped.spec.ts.bak`)

| ID | Spec file | Branch covered | What it asserts |
|---|---|---|---|
| LIFECYCLE-BOG-01-REST | `happy-path-rest.spec.ts` | callback `completed` | order → processing, invoice count ≥ 1 |
| LIFECYCLE-BOG-02-REST | `declined-rest.spec.ts` | callback `rejected` (post-quote, pre-order) | quote-only state cleanup, no Magento order, customer can retry |
| LIFECYCLE-BOG-03-REST | `abandoned-rest.spec.ts` | no callback + reconciler tick | reconciler cancels expired or leaves pending; no double-charge |
| LIFECYCLE-BOG-04-REST | `partial-refund-rest.spec.ts` | callback `refunded` with partial amount | offline creditmemo created, state unchanged for partial |
| LIFECYCLE-BOG-05-REST | `full-refund-rest.spec.ts` | callback `refunded` with full amount | full creditmemo, state → closed |

The 5 existing iframe-stuck specs (`*.iframe-skipped.spec.ts.bak`) are renamed (already are — confirmed by `ls`); we leave them in place as documentation of the prior approach.

### 2.1.4 Skip-clean fallback

If BOG sandbox creds are unavailable (no `client_id`), specs check `bogCredsConfigured()` — already in S12's helper — and skip-clean. Specs do NOT depend on BOG sandbox being reachable since callback simulation is local + signed. They DO depend on `payment/shubo_bog/active=1` being set.

---

## 3. Priority 2.1 — Edge cases matrix

| # | Case | Today's behaviour | Coverage gap | Fix |
|---|---|---|---|---|
| 1 | **Cancel on BOG page** | `AbortRedirect.php` exists; user cancel emits `rejected` → reconciler `handleRejectedOrCancelled` cancels uncaptured order | None — verified | New `declined-rest.spec.ts` asserts cleanup |
| 2 | **Card declined** | BOG callback `rejected` → reconciler cancels | None — verified | Same spec as #1 |
| 3 | **Browser closed mid-3DS** | No callback received; reconciler picks up after 15min via status-API poll; status `expired` → cancel | None — verified | New `abandoned-rest.spec.ts` |
| 4 | **Network timeout on BOG API** | `Initiate.php` catches `LocalizedException` → friendly customer message ("error initiating payment, try again") | None — verified | Manual verified via reading Initiate.php:200-208 |
| 5 | **Double-clicked Place Order** | `Initiate.php::probeExistingBogOrder` short-circuits when bog_order_id present + status pending/completed | None — verified | InitiateTest already covers (probeExistingBogOrder) |
| 6 | **Order amount changes mid-flow** | NOT PROTECTED today. Customer initiates BOG with cart=$50, edits cart in another tab to $5, returns from BOG with $50 captured → ledger mismatch | **GAP** | Callback handler asserts `body.purchase_units.total_amount` (when present) matches `order.grand_total` (within 1 tetri tolerance for rounding); on mismatch, log critical + reject with `AMOUNT_MISMATCH` (HTTP 400). |
| 7 | **Concurrent callback/return/cron race** | PaymentLock (BUG-BOG-6) advisory-locks per bog_order_id; ReturnAction + Callback + Cron serialize | None — verified | PaymentLockTest already covers; lifecycle specs incidentally exercise |
| 8 | **Stale RSA public key (rotation gap)** | `getRsaPublicKey()` returns empty → falls through to status-API. **No** silent failure | None — verified | Lifecycle declined spec without `Callback-Signature` header verifies the status-API fallback path |

### Gap fix (case #6)

```php
// Callback::handleLocked(), after `$validation` succeeds, before `processSuccessfulPayment`:
$bodyAmount = $callbackData['body']['purchase_units']['total_amount'] ?? null;
if ($bodyAmount !== null && is_numeric($bodyAmount)) {
    $bodyAmountMinor = (int) round(((float) $bodyAmount) * 100);
    $orderAmountMinor = (int) round(((float) $order->getGrandTotal()) * 100);
    if (abs($bodyAmountMinor - $orderAmountMinor) > 1) {
        $this->logger->critical('BOG callback: amount mismatch — possible cart-edit-mid-flow', [
            'order_id' => $order->getIncrementId(),
            'bog_order_id' => $bogOrderId,
            'bog_amount_minor' => $bodyAmountMinor,
            'order_amount_minor' => $orderAmountMinor,
        ]);
        return 'AMOUNT_MISMATCH';
    }
}
```

`httpStatusFor` adds case for `AMOUNT_MISMATCH` → 400 (don't retry; this is a tampered or stale request).

### Doc artifact

`docs/edge-cases-matrix.md` — table above + gap fix detail + test coverage map.

---

## 4. Priority 2.2 — UserFacingErrorMapper for BOG

### 4.1 Shape: HTTP-status based, not error-code based

BOG returns errors as `{message, error, error_description}` plus an HTTP status code. Unlike Flitt (which has 1xxx, 2xxx, 3xxx code ranges), BOG's payments API uses HTTP semantics. Mapper maps **status code + optional response body keywords** to friendly Georgian/English copy.

### 4.2 Mapping table

The mapper method signature:

```php
public function toLocalizedException(
    int $httpStatus,
    string $rawErrorMessage = '',
    ?string $errorCode = null,
): LocalizedException
```

| # | HTTP status | BOG meaning | ka (Georgian) | en (English) |
|---|---|---|---|---|
| 1 | 400 (with `error_description`/`message` mentioning amount/card/declined) | Card declined / payment-side decline | ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით ან დაუკავშირდით თქვენს ბანკს. | Your bank declined the payment. Please try another card or contact your bank. |
| 2 | 400 (other) | Validation error | გადახდის მონაცემები არასწორია. გთხოვთ, სცადოთ ხელახლა. | Payment data is invalid. Please try again. |
| 3 | 401 | OAuth token invalid / expired | გადახდის სისტემის კონფიგურაციის შეცდომა. დაუკავშირდით მხარდაჭერას. | Payment system configuration error. Please contact support. |
| 4 | 402 | Payment required / declined | ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით. | Your bank declined the payment. Try another card. |
| 5 | 403 | Forbidden / merchant not authorized | გადახდის მეთოდი ამ შეკვეთისთვის არ არის ხელმისაწვდომი. | This payment method is not available for this order. |
| 6 | 404 | Order not found at BOG | გადახდა ვერ მოიძებნა. გთხოვთ, დაიწყოთ თავიდან. | Payment not found. Please start again. |
| 7 | 409 | Idempotency conflict / duplicate | გადახდა უკვე დამუშავებულია. შეამოწმეთ თქვენი შეკვეთები. | This payment has already been processed. Please check your orders. |
| 8 | 422 | Refund/capture state invalid (e.g. already refunded) | მოქმედება უკვე შესრულებულია ან არასწორ მდგომარეობაშია. | This action has already been completed or is in an invalid state. |
| 9 | 429 | Rate limited | სისტემა გადატვირთულია. გთხოვთ, სცადოთ ცოტა ხანში. | The system is busy. Please try again in a moment. |
| 10 | 500-504 | Upstream BOG error / outage | ბანკის გადახდის სისტემა დროებით მიუწვდომელია. სცადეთ მოგვიანებით. | Bank payment system temporarily unavailable. Please try later. |
| 11 | 0 / unmapped | Network error or unparseable response | გადახდასთან კავშირი ვერ მოხერხდა. სცადეთ ცოტა ხანში. | Could not reach the payment system. Please try again in a moment. |
| 12 | default (any other 4xx) | Generic client error | გადახდა ვერ მოხერხდა. სცადეთ ხელახლა ან დაუკავშირდით მხარდაჭერას. | Payment couldn't be completed. Please try again or contact support. |

The 400-with-decline-keyword bucket (row 1) is a special case for capture/refund attempts that BOG declines for card reasons even with HTTP 400. Pattern-match `/declin|reject|insufficient|expired|fraud/i` on `error_description` or `message` to route into row 1.

### 4.3 Call sites to retrofit (5 sites)

1. `Gateway/Response/RefundHandler.php` — primary site; routes ALL refund failures.
2. `Controller/Payment/Initiate.php:200-208` — wrap LocalizedException with mapper-friendly text.
3. `Controller/Payment/Callback.php:152-162` — surfaces "ERROR" today; add raw triple log + don't surface to user (callback is server-to-server, but the order history comment IS customer-visible).
4. `Controller/Adminhtml/Order/CheckStatus.php:101-107` — admin sees `__('Status check failed: %1', $e->getMessage())` today (raw exception leaks). Route through mapper.
5. `Controller/Adminhtml/Order/Capture.php` — same pattern. Confirm and route if applicable.

Each retrofit:
- Logs raw triple `(http_status, error_body, request_id_if_any)` at ERROR via `ShuboBogPaymentLogger`.
- Calls `$mapper->toLocalizedException($status, $message, $errorCode)`.
- Throws OR uses `getMessage()` for history comment / admin toast.

### 4.4 Tests

`Test/Unit/Gateway/Error/UserFacingErrorMapperTest.php` (new) — 1 test per mapping row (12) + edge cases:
- `testZeroStatusFallsThroughToNetworkError` (row 11)
- `testLocaleResolutionKa`
- `testLocaleResolutionEn`
- `testLocaleResolutionRussianFallsThroughToEn`
- `testHttp400DeclineKeywordRoutesToRow1`
- `testHttp400PlainRoutesToRow2`
- `testRequestIdNeverLeaksIntoUserMessage`

≈ 18 tests.

### 4.5 Doc artifact

`docs/error-code-map.md` — mapping table above + call-site logging contract + Nika sign-off block.

---

## 5. Priority 3.1 — parent_transaction_id (already correct)

`grep -rn setParentTransactionId app/code/Shubo/BogPayment/` returns **zero hits**. BOG never creates fake authorization parent transactions; both the callback path and the reconciler use `registerCaptureNotification(grandTotal)` directly without a parent reference. This matches the TBC post-Session-3 state.

### 5.1 Action

- Document the intentional absence in `docs/architect-scope.md` (this doc, §5).
- Add a regression-guard test that asserts no payment in the BOG flow ever calls `setParentTransactionId`. Concretely: extend each existing controller/cron unit test (Callback, ReturnAction, Confirm, PendingOrderReconciler, CheckStatus) with `$payment->expects(self::never())->method('setParentTransactionId');` mirroring TBC Session 3 Pass 4 S-2.

### 5.2 Doc artifact

Section in `docs/architect-scope.md` (this file) + assertion in 5 unit-test files.

---

## 6. Hard rules (per CLAUDE.md, restated)

1. After EVERY PHP edit: `rsync` the touched files to `~/module-bog-payment/` (Mirror module — Session 4 pre-push hook enforces).
2. Money in tetri-int / decimal-string at API boundary; no float `==` / `>=`.
3. `declare(strict_types=1)`, PHP 8.4+ syntax, PSR-12.
4. `__()` for all user-facing strings.
5. Never modify host system. All Magento commands via `docker compose --env-file .env.docker exec php`.
6. Never spend money. Sandbox creds only.
7. PHPCS Magento2 sev=10, PHPStan level 8, PHPUnit must all pass.
8. Mirror sync byte-identical for all PHP/Test/etc files (excluding `.git`, `.gitignore`, duka-only `docs/reviewer-signoff.md`).

---

## 7. Implementation order (developer)

1. **P3.1** doc + 5 regression-guard test additions (smallest, lowest risk, lights up the test-count delta). 1 commit.
2. **P2.2** UserFacingErrorMapper class + 18 unit tests + retrofit at 4 call sites. 1 commit.
3. **P1.1** RefundCommand pipeline fix: drop validator + URL fix + handler rewire + RCA doc + RefundCommandPipelineTest + RefundHandlerTest extension. 1 commit.
4. **P2.1** Amount-mismatch guard in Callback + edge-cases-matrix doc + CallbackTest extension for amount-mismatch path. 1 commit.
5. **P1.2** 5 lifecycle Playwright specs + bog test keypair + signAndPostBogCallback helper + setBogTestPublicKey helper + signature pin TS test. 1 commit.
6. **Reviewer pass** — reviewer-signoff doc, address findings if any. 1 commit.

Total: 6 commits on `origin/main`, mirror commits on `~/module-bog-payment/`.

---

## 8. Out of scope (deferred, documented)

- BOG callback `amount` may not be in `body.purchase_units` for every status; verification deferred until first real BOG callback capture in dev. Defensive null-check makes the new amount-mismatch guard a no-op for missing fields (no false positive).
- Russian locale for UserFacingErrorMapper — TBC mirror-deferred decision.
- Live BOG sandbox runs of 5 lifecycle specs — depend on Nika running `bin/magento config:set payment/shubo_bog/{client_id,client_secret,environment}` first. Specs auto-skip without these.
- Cron memory/perf optimization — already done in BUG-OPS-PHP-CLI-OOM-1.
- BOG receipt-API alternative for happy-path verification (instead of signed callback) — deferred; signed callback is more deterministic for declined/refunded/reversed tests.

End of architect-scope.
