<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for BOG payment action mode (automatic capture vs manual/preauth).
 */
class PaymentAction implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'automatic', 'label' => __('Automatic Capture (auto-invoice)')],
            ['value' => 'manual', 'label' => __('Manual Capture (pre-authorization)')],
        ];
    }
}
