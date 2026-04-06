<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Api\Data;

/**
 * Split payment data for BOG iPay multi-vendor payment distribution.
 */
interface SplitPaymentDataInterface
{
    /**
     * Check if split payment data has been populated.
     */
    public function hasSplitPayments(): bool;

    /**
     * Get the array of split payment entries.
     *
     * @return array<int, array{iban: string, percent: float, description: string}>
     */
    public function getSplitPayments(): array;

    /**
     * Add a split payment entry.
     *
     * @param string $iban Merchant IBAN
     * @param float $percent Percentage of total amount (0-100)
     * @param string $description Description for this split
     */
    public function addSplitPayment(string $iban, float $percent, string $description): self;

    /**
     * Reset all split payment data.
     */
    public function reset(): self;
}
