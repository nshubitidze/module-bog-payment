<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Block\Payment;

use Magento\Payment\Block\Info as PaymentInfo;

class Info extends PaymentInfo
{
    /**
     * Prepare payment info for display.
     *
     * @param \Magento\Framework\DataObject|null $transport
     * @return \Magento\Framework\DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $info = $this->getInfo();

        $displayFields = [
            'bog_order_id' => 'BOG Order ID',
            'bog_status' => 'BOG Status',
            'bog_card_type' => 'Card Type',
            'bog_pan' => 'Card Number',
            'bog_payment_method' => 'Payment Method',
            'bog_payment_hash' => 'Payment Hash',
            'bog_details_url' => 'Details URL',
            'capture_status' => 'Capture Status',
        ];

        foreach ($displayFields as $key => $label) {
            $value = $info->getAdditionalInformation($key);
            if ($value !== null && $value !== '') {
                $this->addToTransport($transport, (string) __($label), (string) $value);
            }
        }

        return $transport;
    }

    /**
     * @param \Magento\Framework\DataObject $transport
     */
    private function addToTransport(\Magento\Framework\DataObject $transport, string $label, string $value): void
    {
        /** @var array<string, string> $data */
        $data = $transport->getData();
        $data[$label] = $value;
        $transport->setData($data);
    }
}
