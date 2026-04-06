<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'test', 'label' => __('Test')->render()],
            ['value' => 'live', 'label' => __('Live')->render()],
        ];
    }
}
