<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for BOG payment page theme (light/dark).
 */
class PaymentTheme implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'light', 'label' => __('Light')],
            ['value' => 'dark', 'label' => __('Dark')],
        ];
    }
}
