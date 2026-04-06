<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model;

use Shubo\BogPayment\Api\Data\SplitPaymentDataInterface;

class SplitPaymentData implements SplitPaymentDataInterface
{
    /** @var array<int, array{iban: string, percent: float, description: string}> */
    private array $splitPayments = [];

    public function hasSplitPayments(): bool
    {
        return count($this->splitPayments) > 0;
    }

    /**
     * @return array<int, array{iban: string, percent: float, description: string}>
     */
    public function getSplitPayments(): array
    {
        return $this->splitPayments;
    }

    public function addSplitPayment(string $iban, float $percent, string $description): self
    {
        $this->splitPayments[] = [
            'iban' => $iban,
            'percent' => $percent,
            'description' => $description,
        ];
        return $this;
    }

    public function reset(): self
    {
        $this->splitPayments = [];
        return $this;
    }
}
