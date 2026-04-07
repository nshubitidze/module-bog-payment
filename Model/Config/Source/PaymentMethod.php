<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for allowed BOG payment methods (multiselect).
 */
class PaymentMethod implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'card', 'label' => __('Card')],
            ['value' => 'google_pay', 'label' => __('Google Pay')],
            ['value' => 'apple_pay', 'label' => __('Apple Pay')],
            ['value' => 'bog_p2p', 'label' => __('BOG P2P')],
            ['value' => 'bog_loyalty', 'label' => __('BOG Loyalty')],
        ];
    }
}
