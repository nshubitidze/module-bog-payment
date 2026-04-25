<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Error;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Phrase;

/**
 * Translates raw BOG Payments-API HTTP responses into friendly,
 * locale-aware copy suitable for storefront and admin users.
 *
 * Differs from TBC's UserFacingErrorMapper in input shape: BOG returns
 * errors as HTTP-status + JSON `{message, error, error_description}` rather
 * than as Flitt-style numeric error codes. Mapper takes the HTTP status
 * (primary) plus an optional raw message for keyword-based bucketing inside
 * 400-range responses (card declined vs validation error).
 *
 * Responsibilities:
 *   - Map known BOG HTTP statuses to copy documented in
 *     `docs/error-code-map.md` §2.
 *   - Resolve between Georgian (`ka*` locales) and English (everything else).
 *   - Produce a fresh {@see LocalizedException} per call; callers decide
 *     whether to throw, use ->getMessage() for a history comment, etc.
 *
 * Non-responsibilities:
 *   - NO logging. Callers own the raw-triple log line (they have the richest
 *     context: order id, creditmemo id, store id, request_id, etc.).
 *   - NO translation of free-form BOG `message`/`error_description` strings.
 *     The raw message is never surfaced to users — only the mapped copy is.
 *   - NO retry/escalation logic. Pure function input -> exception.
 *
 * @see \Shubo\BogPayment\Test\Unit\Gateway\Error\UserFacingErrorMapperTest
 * @see docs/error-code-map.md
 */
class UserFacingErrorMapper
{
    public function __construct(
        private readonly ResolverInterface $localeResolver,
    ) {
    }

    /**
     * Map a BOG HTTP status + raw error info into a localized exception.
     *
     * `$rawErrorMessage` is used for keyword routing inside 400 responses
     * (decline-vs-validation) but is NEVER concatenated into the user-
     * facing copy — that would defeat the localization contract.
     *
     * `$errorCode` is accepted so call sites can pass a BOG-specific
     * identifier (e.g. `error` or `error_description`) for surrounding log
     * lines, but again the mapper itself never leaks it into user copy.
     */
    public function toLocalizedException(
        int $httpStatus,
        string $rawErrorMessage = '',
        ?string $errorCode = null,
    ): LocalizedException {
        $phrase = $this->resolvePhrase($httpStatus, $rawErrorMessage);

        return new LocalizedException($phrase);
    }

    /**
     * Resolve the user-facing {@see Phrase} for the given HTTP status +
     * optional message keyword routing.
     *
     * Ordering: explicit status match first; for 400, keyword routing
     * splits decline-vs-validation; ranges last; default fallback final.
     */
    private function resolvePhrase(int $httpStatus, string $rawErrorMessage): Phrase
    {
        $isKa = $this->isGeorgianLocale();

        // ---- Row 11: 0 / negative — network or unparseable response.
        if ($httpStatus <= 0) {
            return $isKa
                ? __('გადახდასთან კავშირი ვერ მოხერხდა. სცადეთ ცოტა ხანში.')
                : __('Could not reach the payment system. Please try again in a moment.');
        }

        // ---- Row 1 + Row 2: 400 with decline-keyword vs plain validation.
        if ($httpStatus === 400) {
            if ($this->looksLikeDecline($rawErrorMessage)) {
                return $isKa
                    ? __('ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით ან დაუკავშირდით თქვენს ბანკს.')
                    : __('Your bank declined the payment. Please try another card or contact your bank.');
            }
            return $isKa
                ? __('გადახდის მონაცემები არასწორია. გთხოვთ, სცადოთ ხელახლა.')
                : __('Payment data is invalid. Please try again.');
        }

        // ---- Row 3: 401 OAuth token invalid / expired.
        if ($httpStatus === 401) {
            return $isKa
                ? __('გადახდის სისტემის კონფიგურაციის შეცდომა. დაუკავშირდით მხარდაჭერას.')
                : __('Payment system configuration error. Please contact support.');
        }

        // ---- Row 4: 402 Payment required / declined.
        if ($httpStatus === 402) {
            return $isKa
                ? __('ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით.')
                : __('Your bank declined the payment. Try another card.');
        }

        // ---- Row 5: 403 Forbidden / merchant not authorized.
        if ($httpStatus === 403) {
            return $isKa
                ? __('გადახდის მეთოდი ამ შეკვეთისთვის არ არის ხელმისაწვდომი.')
                : __('This payment method is not available for this order.');
        }

        // ---- Row 6: 404 Order not found at BOG.
        if ($httpStatus === 404) {
            return $isKa
                ? __('გადახდა ვერ მოიძებნა. გთხოვთ, დაიწყოთ თავიდან.')
                : __('Payment not found. Please start again.');
        }

        // ---- Row 7: 409 Idempotency conflict / duplicate.
        if ($httpStatus === 409) {
            return $isKa
                ? __('გადახდა უკვე დამუშავებულია. შეამოწმეთ თქვენი შეკვეთები.')
                : __('This payment has already been processed. Please check your orders.');
        }

        // ---- Row 8: 422 Refund/capture state invalid (already refunded etc).
        if ($httpStatus === 422) {
            return $isKa
                ? __('მოქმედება უკვე შესრულებულია ან არასწორ მდგომარეობაშია.')
                : __('This action has already been completed or is in an invalid state.');
        }

        // ---- Row 9: 429 Rate limited.
        if ($httpStatus === 429) {
            return $isKa
                ? __('სისტემა გადატვირთულია. გთხოვთ, სცადოთ ცოტა ხანში.')
                : __('The system is busy. Please try again in a moment.');
        }

        // ---- Row 10: 500-504 Upstream BOG error / outage.
        if ($httpStatus >= 500 && $httpStatus <= 504) {
            return $isKa
                ? __('ბანკის გადახდის სისტემა დროებით მიუწვდომელია. სცადეთ მოგვიანებით.')
                : __('Bank payment system temporarily unavailable. Please try later.');
        }

        // ---- Row 12: any other 4xx — generic client error.
        if ($httpStatus >= 400 && $httpStatus < 500) {
            return $isKa
                ? __('გადახდა ვერ მოხერხდა. სცადეთ ხელახლა ან დაუკავშირდით მხარდაჭერას.')
                : __('Payment couldn\'t be completed. Please try again or contact support.');
        }

        // Default — unmapped status (e.g. 5xx outside 500-504, 1xx, 3xx).
        return $isKa
            ? __('გადახდა ვერ მოხერხდა. სცადეთ ხელახლა ან დაუკავშირდით მხარდაჭერას.')
            : __('Payment couldn\'t be completed. Please try again or contact support.');
    }

    /**
     * Heuristic: BOG sometimes returns HTTP 400 for card-side declines
     * (insufficient funds, fraud rejected, expired card). The error_description
     * carries an English keyword. Match case-insensitively against a small set.
     *
     * Conservative on purpose — false positives just route to a more
     * sympathetic user message ("your bank declined") rather than a
     * misleading "data is invalid". False negatives fall through to the
     * plainer validation copy, which is a safer default.
     */
    private function looksLikeDecline(string $rawErrorMessage): bool
    {
        if ($rawErrorMessage === '') {
            return false;
        }
        return (bool) preg_match(
            '/declin|reject|insufficient|expired|fraud/i',
            $rawErrorMessage
        );
    }

    /**
     * Treat any locale whose language tag starts with `ka` as Georgian.
     * Everything else (including `ru_RU`) falls through to English; matches
     * TBC's UserFacingErrorMapper convention (Russian deferred).
     */
    private function isGeorgianLocale(): bool
    {
        $locale = (string) $this->localeResolver->getLocale();

        return str_starts_with($locale, 'ka');
    }
}
