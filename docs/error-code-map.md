# BOG HTTP Status -> User-Facing Copy Map

**Module:** `Shubo_BogPayment`
**Session:** 8 (2026-04-25) Priority 2.2
**Author:** Architect (this doc is the SKELETON — developer finalizes the
Georgian copy with Nika before shipping.)
**Status:** Implemented in `Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper`.
**Test:** `Test/Unit/Gateway/Error/UserFacingErrorMapperTest` (23 tests).

---

## 1. Principles

1. **Never leak the raw BOG message to the user.** Raw strings always log
   at ERROR level via `ShuboBogPaymentLogger` with `http_status`,
   `message`, `error_description`, `error`, and the Magento order /
   creditmemo context.
2. **Two locales only — `ka` and `en`.** Georgian for Georgian-locale
   stores, English everywhere else. Russian is deferred (mirror of TBC
   architect-scope §2.2.2).
3. **Actionable copy.** Each message tells the user what to do next:
   try again, try another card, contact support, etc. Never just
   "something went wrong."
4. **HTTP-status keyed, with a 400-keyword refinement.** Unlike Flitt,
   BOG does not publish a numeric error-code taxonomy. The mapper keys
   off the HTTP status; for HTTP 400, it inspects the message for a
   small set of decline keywords (`declin/reject/insufficient/expired/
   fraud`) to differentiate "card declined" from "validation error".
5. **Default copy is NOT "Unknown error".** It is the generic
   "system-error-please-retry" copy from row #12.

---

## 2. Mapping table

The mapper method signature:

```php
public function toLocalizedException(
    int $httpStatus,
    string $rawErrorMessage = '',
    ?string $errorCode = null,
): \Magento\Framework\Exception\LocalizedException
```

| # | HTTP status | BOG meaning | ka (Georgian) copy | en (English) copy |
|---|---|---|---|---|
| 1 | `400` (decline kw) | Card decline reported as 400 | ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით ან დაუკავშირდით თქვენს ბანკს. | Your bank declined the payment. Please try another card or contact your bank. |
| 2 | `400` (other) | Validation error | გადახდის მონაცემები არასწორია. გთხოვთ, სცადოთ ხელახლა. | Payment data is invalid. Please try again. |
| 3 | `401` | OAuth token invalid / expired | გადახდის სისტემის კონფიგურაციის შეცდომა. დაუკავშირდით მხარდაჭერას. | Payment system configuration error. Please contact support. |
| 4 | `402` | Payment required / declined | ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით. | Your bank declined the payment. Try another card. |
| 5 | `403` | Forbidden / merchant not authorized | გადახდის მეთოდი ამ შეკვეთისთვის არ არის ხელმისაწვდომი. | This payment method is not available for this order. |
| 6 | `404` | Order not found at BOG | გადახდა ვერ მოიძებნა. გთხოვთ, დაიწყოთ თავიდან. | Payment not found. Please start again. |
| 7 | `409` | Idempotency conflict / duplicate | გადახდა უკვე დამუშავებულია. შეამოწმეთ თქვენი შეკვეთები. | This payment has already been processed. Please check your orders. |
| 8 | `422` | Refund/capture state invalid (already refunded) | მოქმედება უკვე შესრულებულია ან არასწორ მდგომარეობაშია. | This action has already been completed or is in an invalid state. |
| 9 | `429` | Rate limited | სისტემა გადატვირთულია. გთხოვთ, სცადოთ ცოტა ხანში. | The system is busy. Please try again in a moment. |
| 10 | `500-504` | Upstream BOG error / outage | ბანკის გადახდის სისტემა დროებით მიუწვდომელია. სცადეთ მოგვიანებით. | Bank payment system temporarily unavailable. Please try later. |
| 11 | `0` / negative | Network unreachable / unparseable | გადახდასთან კავშირი ვერ მოხერხდა. სცადეთ ცოტა ხანში. | Could not reach the payment system. Please try again in a moment. |
| 12 | other 4xx + default | Generic | გადახდა ვერ მოხერხდა. სცადეთ ხელახლა ან დაუკავშირდით მხარდაჭერას. | Payment couldn't be completed. Please try again or contact support. |

### 400-decline keyword regex

`/declin|reject|insufficient|expired|fraud/i`

Conservative on purpose. False positives route to a more sympathetic
"your bank declined" copy (acceptable). False negatives route to the
plainer validation copy (also acceptable). Regex covers the BOG sandbox
error_description strings observed in dev logs.

---

## 3. Call-site logging contract

Before throwing the mapped exception, every caller MUST log the raw
context at ERROR level using the BOG-dedicated logger
(`ShuboBogPaymentLogger` virtualType in di.xml). Log line shape:

```
BOG HTTP error mapped to user copy
  http_status: 400
  message: "Card was declined by issuing bank"
  error_description: "..."
  error: "..."
  order_increment_id: "000000042"
  creditmemo_id: 17                 # if refund context
  bog_order_id: "BOG-XYZ"
  user_locale: "ka_GE"
  mapped_row: 1                     # the row # from this table
```

Logging is done BY THE CALLER, not the mapper. The mapper is a pure
function (input -> LocalizedException). This keeps it dead-simple to
unit-test (covered by `UserFacingErrorMapperTest`).

---

## 4. Call sites to retrofit

From architect-scope §4.3:

1. `Gateway/Response/RefundHandler.php` — primary site (Priority 1.1).
   Routes ALL refund failures through the mapper.
2. `Controller/Payment/Initiate.php` — wrap LocalizedException with
   mapper-friendly text on BOG API failure.
3. `Controller/Adminhtml/Order/CheckStatus.php` — admin-facing exception
   text leaks today; route through mapper.
4. `Controller/Payment/Callback.php` — the order history comment is
   customer-visible in "My Orders". Use mapper-derived comment text on
   `VALIDATION_FAILED` / `AMOUNT_MISMATCH` paths.

Each retrofit:
- Logs the raw triple (`http_status`, `message`, `error_description`)
  at ERROR.
- Calls `$mapper->toLocalizedException($status, $message, $errorCode)`.
- Throws OR uses `getMessage()` for a history comment / admin toast.

---

## 5. Testing requirements

Unit test file:
`Test/Unit/Gateway/Error/UserFacingErrorMapperTest.php` (DONE — 23 tests).

One test per mapping row (12) + edge cases:
- `testZeroStatusFallsThroughToNetworkError`: input `0` -> row 11.
- `testHttp400DeclineKeywordRoutesToBankDeclined`: keyword routing.
- `testHttp400PlainRoutesToValidation`: plain 400.
- `testHttp400EmptyMessageRoutesToValidation`: empty message guard.
- `testRussianLocaleFallsThroughToEnglish`: deferred locale handling.
- `testRawMessageNeverLeaksIntoUserCopy`: contract assertion.
- `testErrorCodeNeverLeaksIntoUserCopy`: contract assertion.
- `testDeclineKeywordsAreCaseInsensitive`: 5 keywords across ka/en.
- `testReturnsLocalizedExceptionInstance`: type contract + freshness.

Total: 23 tests, 32 assertions. All green at commit time.

---

## 6. Nika sign-off required before ship

The Georgian copy above is my best-effort architect translation. Nika
(native Georgian speaker) must review row 1, 2, 6, and 10 at minimum —
these are the highest-volume sandbox cases and must read naturally.
Developer includes a `[ ] Nika approved ka copy` checkbox in
`session-8-bog-payment-hardening_results.md`.

End of error-code-map.
