<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\Exception\LocalizedException;

class Receivers extends ArraySerialized
{
    /**
     * Validate split receiver configuration before saving.
     *
     * @throws LocalizedException
     */
    public function beforeSave(): static
    {
        $value = $this->getValue();

        if (!is_array($value)) {
            return parent::beforeSave();
        }

        // Remove the __empty row that Magento adds for the template
        unset($value['__empty']);

        if (empty($value)) {
            $this->setValue([]);
            return parent::beforeSave();
        }

        $percentTotal = 0.0;

        foreach ($value as $rowId => &$row) {
            $iban = trim((string) ($row['iban'] ?? ''));
            $amountType = (string) ($row['amount_type'] ?? 'percent');
            $amount = trim((string) ($row['amount'] ?? ''));

            if ($iban === '') {
                throw new LocalizedException(
                    __('Split receiver in row "%1": IBAN is required.', $rowId)
                );
            }

            if (!is_numeric($amount) || (float) $amount <= 0) {
                throw new LocalizedException(
                    __('Split receiver "%1": Amount must be a positive number.', $iban)
                );
            }

            if ($amountType === 'percent') {
                $percentTotal += (float) $amount;
                if ((float) $amount > 100) {
                    throw new LocalizedException(
                        __('Split receiver "%1": Percentage cannot exceed 100.', $iban)
                    );
                }
            }
        }
        unset($row);

        if ($percentTotal > 100.0) {
            throw new LocalizedException(
                __('Total split percentages cannot exceed 100%1. Current total: %2%1.', '%', round($percentTotal, 2))
            );
        }

        $this->setValue($value);
        return parent::beforeSave();
    }
}
