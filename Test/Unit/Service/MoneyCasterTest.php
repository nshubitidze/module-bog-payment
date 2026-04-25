<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Shubo\BogPayment\Service\MoneyCaster;

/**
 * BUG-BOG-8: Magento's Payment API mandates a `float` signature on
 * `Order\Payment::registerCaptureNotification`. The BOG module does every
 * other piece of monetary math via bcmath strings (Commission + Payout chain,
 * see feedback_bcmath_string_returns.md). The MoneyCaster encapsulates the
 * single boundary cast so we can audit/guard it in one place.
 *
 * Input contract: a bcmath-safe numeric string already clamped to 2 decimal
 * places by the callers (grand_total lives in sales_order as DECIMAL(4)
 * internally; BOG's wire amount comes back as a string and is normalized via
 * bcadd($amount, '0', 2) before reaching the cast boundary).
 *
 * The caster:
 *   - accepts numeric strings (and numeric-like non-string scalars as a
 *     convenience since $order->getGrandTotal() can return string|float|null)
 *   - normalizes to exactly 2 decimal places via bcadd to defeat any caller
 *     that hands us 4-decimal precision
 *   - refuses empty / non-numeric / negative inputs so bad data cannot
 *     silently short-circuit a capture
 */
class MoneyCasterTest extends TestCase
{
    public function testCleanTwoDecimalStringCastsToFloat(): void
    {
        self::assertSame(39.00, MoneyCaster::toMagentoFloat('39.00'));
        self::assertSame(1.50, MoneyCaster::toMagentoFloat('1.50'));
        self::assertSame(100.99, MoneyCaster::toMagentoFloat('100.99'));
    }

    public function testFourDecimalStringIsRoundedAtCastBoundary(): void
    {
        // bcadd($amount, '0', 2) truncates (half-floor) excess precision.
        // 12.3456 → 12.34 (not 12.35) — consistent with Payout's RefundRequestBuilder.
        self::assertSame(12.34, MoneyCaster::toMagentoFloat('12.3456'));
        self::assertSame(0.99, MoneyCaster::toMagentoFloat('0.9999'));
    }

    public function testZeroIsAllowed(): void
    {
        self::assertSame(0.00, MoneyCaster::toMagentoFloat('0'));
        self::assertSame(0.00, MoneyCaster::toMagentoFloat('0.00'));
        self::assertSame(0.00, MoneyCaster::toMagentoFloat(0));
    }

    public function testAcceptsNumericFloatInputsBecauseGrandTotalReturnsFloat(): void
    {
        // $order->getGrandTotal() can return string|float depending on
        // upstream; normalize both through the same cast boundary.
        self::assertSame(12.34, MoneyCaster::toMagentoFloat(12.3456));
        self::assertSame(39.00, MoneyCaster::toMagentoFloat(39));
    }

    public function testRejectsNegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MoneyCaster: amount must not be negative');

        MoneyCaster::toMagentoFloat('-1.00');
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MoneyCaster: amount must be a numeric string');

        MoneyCaster::toMagentoFloat('');
    }

    public function testRejectsNonNumericString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MoneyCaster: amount must be a numeric string');

        MoneyCaster::toMagentoFloat('abc');
    }

    public function testRejectsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MoneyCaster: amount must be a numeric string');

        /** @phpstan-ignore-next-line — deliberately passing null to prove guard */
        MoneyCaster::toMagentoFloat(null);
    }
}
