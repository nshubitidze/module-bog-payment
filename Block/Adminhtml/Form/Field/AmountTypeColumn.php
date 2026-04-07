<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Html\Select;

class AmountTypeColumn extends Select
{
    /**
     * Set the input name for the select element.
     *
     * @param string $value Input name
     */
    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    /**
     * Set the input ID for the select element.
     *
     * @param string $value Input ID
     */
    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    /**
     * Render select element with amount type options.
     */
    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions([
                ['label' => __('Percentage (%)'), 'value' => 'percent'],
                ['label' => __('Fixed Amount'), 'value' => 'fixed'],
            ]);
        }
        return parent::_toHtml();
    }

    /**
     * Calculate CRC32 hash for option value.
     *
     * Overrides parent to exclude element ID from hash, which varies per row
     * in dynamic rows and would break option pre-selection.
     *
     * @param string $optionValue Value of the option
     */
    public function calcOptionHash($optionValue): string
    {
        return sprintf('%u', crc32($this->getName() . $optionValue));
    }
}
