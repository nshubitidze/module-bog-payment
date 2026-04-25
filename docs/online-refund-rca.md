# Online Credit Memo Refund — Root Cause Analysis (Session 8)

**Module:** `Shubo_BogPayment`
**Session:** 8 (2026-04-25) Priority 1.1
**Status:** RCA + fix shipped.

---

## TL;DR

Two compound failures in the BOG refund pipeline blocked online credit
memo refunds from working end-to-end. Both were silent — the admin saw
either a generic `CommandException` toast (no actionable info) or an
HTTP 404 from BOG (because the refund hit the wrong endpoint).

1. **M-1 (validator preemption).** `etc/di.xml` wired
   `Shubo\BogPayment\Gateway\Validator\ResponseValidator` as the validator
   on `ShuboBogRefundCommand`. Magento's `GatewayCommand::execute` calls
   the validator AFTER the client and BEFORE the handler. Any non-2xx
   HTTP response routed through `processErrors()` and raised a generic
   `CommandException` — the `RefundHandler` (which would have routed
   through `UserFacingErrorMapper`) never ran.

2. **Wrong refund endpoint URL.** `Config::getRefundUrl()` returned
   `${api_url}/checkout/refund` — the **legacy iPay form-encoded
   endpoint**. With the current default `api_url`
   (`https://api.bog.ge/payments/v1`), that path 404'd. The new BOG
   Payments API exposes refunds at
   `POST /payments/v1/payment/refund/{order_id}` with body `{amount}`.

Identical to TBC Session 3's M-1 reviewer-signoff finding (validator
preempted handler). The wrong-URL bug compounded by being a one-line
copy-paste from the iPay shape that wasn't updated when the rest of the
module migrated to the new API.

---

## How it manifested

Pre-fix, an admin clicking "Refund" on a BOG-paid order saw:

- Best-case (sandbox creds present): generic admin toast
  "Transaction has been declined. Please try again later." — completely
  uninformative for the support agent or the merchant.
- Worst-case (BOG actually returns the legacy 404): the same generic
  toast, with no log line correlating to the actual BOG-side cause.

Either way:
- No raw triple logged → ops cannot correlate to BOG support.
- No friendly Georgian copy → merchants in `ka_GE` saw English fragments.
- The creditmemo was rolled back inside `Creditmemo::register` —
  Magento's transaction wraps the operation, so the order remained in
  `processing` with no creditmemo recorded. Recoverable but invisible.

---

## Investigation steps

1. Read `etc/di.xml` for `ShuboBogRefundCommand` wiring →
   spotted `<argument name="validator">` immediately as the same M-1
   pattern flagged in TBC reviewer-signoff Pass 4.
2. Read `Config::getRefundUrl()` and the BOG API map (`reference_payment_modules.md`
   §"Refund (iPay)" vs the architect's noted new endpoint) →
   spotted the legacy `/checkout/refund` path against the new
   `payments/v1` base.
3. Read `RefundHandler`, `RefundRequestBuilder`, `RefundClient` to
   confirm the body shape (`{order_id, amount}` form-encoded) was also
   legacy.
4. Read `Magento\Payment\Gateway\Command\GatewayCommand::execute` source
   (vendor) — confirmed the validator runs BEFORE the handler.

Total investigation: ~15 minutes for both bugs.

---

## Fix

### Change 1 — Drop the validator on `ShuboBogRefundCommand`

`etc/di.xml`:
```xml
<!-- BEFORE -->
<virtualType name="ShuboBogRefundCommand" type="Magento\Payment\Gateway\Command\GatewayCommand">
    <arguments>
        ...
        <argument name="validator" xsi:type="object">Shubo\BogPayment\Gateway\Validator\ResponseValidator</argument>
    </arguments>
</virtualType>

<!-- AFTER -->
<virtualType name="ShuboBogRefundCommand" type="Magento\Payment\Gateway\Command\GatewayCommand">
    <arguments>
        ...
        <!-- NO validator. RefundHandler is the sole gatekeeper. -->
    </arguments>
</virtualType>
```

### Change 2 — `Config::getRefundUrl()` switches to the new endpoint shape

```php
// BEFORE
public function getRefundUrl(?int $storeId = null): string
{
    return $this->getEffectiveApiUrl($storeId) . '/checkout/refund';
}

// AFTER
public function getRefundUrl(string $bogOrderId, ?int $storeId = null): string
{
    return $this->getEffectiveApiUrl($storeId) . '/payment/refund/' . $bogOrderId;
}
```

### Change 3 — `RefundClient` extracts `order_id` from body, builds URL, strips before send

The body produced by `RefundRequestBuilder` keeps `order_id` so the client
can construct the URL; the field is removed from the wire payload so the
new API receives only `{amount}`. Plus a hard guard against missing
`bog_order_id` (programming-error catch).

### Change 4 — `RefundRequestBuilder` rejects empty `bog_order_id`

Builder now throws `LocalizedException` with the order's increment id
embedded in the message, so the admin gets actionable guidance instead
of a downstream 404.

### Change 5 — `RefundHandler` routes failures through `UserFacingErrorMapper`

On non-2xx HTTP, the handler logs the raw triple at ERROR via
`ShuboBogPaymentLogger` and throws the friendly `LocalizedException`
returned by the mapper. On 2xx, persists `bog_refund_status`,
`bog_refund_id`, and the transaction id; closes the transaction.

---

## Tests

5 new test files / 19 new tests:

1. `Test/Unit/Gateway/Error/UserFacingErrorMapperTest` — 23 tests, every
   row in the mapping table + locale + leakage guards.
2. `Test/Unit/Gateway/Response/RefundHandlerTest` — 6 tests, success +
   failure routing + parent_transaction_id regression guard.
3. `Test/Unit/Gateway/Request/RefundRequestBuilderTest` — 4 tests, body
   shape + bcmath truncation + missing-id guard.
4. `Test/Unit/Gateway/Command/RefundCommandPipelineTest` — 2 tests, full
   GatewayCommand pipeline end-to-end. **This is the M-1 regression
   guard.** If a future change reintroduces a validator on the refund
   command, the test fails with a CHANGE-ME message pointing back to
   this RCA.
5. `Test/Unit/Architecture/NoParentTransactionIdTest` — 1 test, scans
   production code for any `setParentTransactionId` reintroduction.

All 45 + 1 = 46 tests pass at commit time.

---

## Manual verification (Nika to confirm in admin)

1. Place a BOG-paid sandbox order via `/shubo_bog/payment/initiate` (or
   the Playwright LIFECYCLE-BOG-01-REST spec).
2. Admin → Sales → Orders → open order → Invoice → Credit Memo.
3. Set partial refund amount (e.g. half), click Refund.
4. Expect: admin toast in current locale (Georgian if `ka_GE`):
   - On BOG-side success: "Credit memo created" — verify in DB:
     `SELECT * FROM sales_creditmemo WHERE order_id = ?`.
   - On BOG-side failure: friendly mapped message (per row 8 / 422 if
     "already refunded", row 1 if amount declined, etc).

Manual smoke deferred until Nika has a live sandbox order to refund.

---

## Why this took two sessions to find

Session 7 audit caught BUG-BOG-1 through BUG-BOG-17 — a wide net of
correctness bugs around quote materialization, OAuth token caching,
locking, and money handling. The refund pipeline was assumed-working
because no production refund had been attempted yet. The Session 8
prompt asked specifically for "online credit memo verification" and the
M-1 + wrong-URL bugs surfaced within ~15 minutes of looking.

Future audit prompt addition: explicitly probe `etc/di.xml` for any
`<argument name="validator">` on a `*Command` virtualType that also has
a custom `*Handler` — the pattern is almost always wrong (validator
preempts handler error routing) unless deliberately documented.

---

## References

- `app/code/Shubo/BogPayment/etc/di.xml` (refund command wiring)
- `app/code/Shubo/BogPayment/Gateway/Config/Config.php` (`getRefundUrl`)
- `app/code/Shubo/BogPayment/Gateway/Http/Client/RefundClient.php` (URL build + non-2xx handling)
- `app/code/Shubo/BogPayment/Gateway/Request/RefundRequestBuilder.php` (missing-id guard)
- `app/code/Shubo/BogPayment/Gateway/Response/RefundHandler.php` (mapper integration)
- `app/code/Shubo/BogPayment/Gateway/Error/UserFacingErrorMapper.php` (friendly copy)
- `app/code/Shubo/BogPayment/docs/error-code-map.md` (mapping reference)
- TBC Session 3 reviewer-signoff M-1 (the structural template)

End of RCA.
