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

        $bogOrderId = $info->getAdditionalInformation('bog_order_id');
        if ($bogOrderId) {
            $this->addToTransport($transport, (string) __('BOG Order ID'), (string) $bogOrderId);
        }

        $bogPaymentId = $info->getAdditionalInformation('bog_payment_id');
        if ($bogPaymentId) {
            $this->addToTransport($transport, (string) __('BOG Payment ID'), (string) $bogPaymentId);
        }

        $bogStatus = $info->getAdditionalInformation('bog_status');
        if ($bogStatus) {
            $this->addToTransport($transport, (string) __('BOG Status'), (string) $bogStatus);
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
