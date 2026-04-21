<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Service;

use InvalidArgumentException;

/**
 * Boundary cast from a bcmath-safe numeric string to a float for Magento's
 * Payment API (BUG-BOG-8).
 *
 * Why this exists:
 *   - `Magento\Sales\Model\Order\Payment::registerCaptureNotification(float $amount)`
 *     mandates a float signature we cannot change.
 *   - Everywhere else in Commission + Payout we carry monetary values as
 *     bcmath strings to avoid 1-tetri drift (see
 *     `feedback_bcmath_string_returns.md`).
 *   - Sprinkling `(float) $order->getGrandTotal()` across the codebase hides
 *     the precision boundary. Centralising it means one place to audit, one
 *     place to enforce clamping to 2 decimals, and one place to reject
 *     invalid input so a corrupt payload cannot silently become `0.0`.
 *
 * Input contract:
 *   - $bcmathAmount is a bcmath-safe numeric string (or a numeric scalar
 *     returned by `$order->getGrandTotal()` which may be either string or
 *     float depending on ORM state).
 *   - Callers are expected to have already normalized via `bcadd(..., '0', 2)`
 *     if the source can produce 4-decimal precision (e.g. BOG wire amounts
 *     normalized in RefundRequestBuilder). We do the same `bcadd` here as a
 *     defence-in-depth so one forgotten caller cannot leak half-rounded
 *     values into the fraud-check comparison inside Magento's payment.
 *
 * Output: a float clamped to 2 decimal places. Guaranteed non-negative,
 * guaranteed finite (no NaN / Inf) because the input must be numeric.
 */
class MoneyCaster
{
    private const SCALE = 2;

    /**
     * @param string|float|int|null $bcmathAmount
     * @throws InvalidArgumentException on empty / non-numeric / negative input
     */
    public static function toMagentoFloat(string|float|int|null $bcmathAmount): float
    {
        if ($bcmathAmount === null || $bcmathAmount === '' || !is_numeric($bcmathAmount)) {
            throw new InvalidArgumentException('MoneyCaster: amount must be a numeric string');
        }

        $asString = (string) $bcmathAmount;

        if (bccomp($asString, '0', self::SCALE) === -1) {
            throw new InvalidArgumentException('MoneyCaster: amount must not be negative');
        }

        // Clamp to 2-decimal precision at the cast boundary. bcadd truncates
        // (floor) extra digits, consistent with RefundRequestBuilder.
        $clamped = bcadd($asString, '0', self::SCALE);

        return (float) $clamped;
    }
}
