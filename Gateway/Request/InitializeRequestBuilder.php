<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Request;

use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

class InitializeRequestBuilder implements BuilderInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    /**
     * Build the BOG create-order request payload.
     *
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $amount = $this->subjectReader->readAmount($buildSubject);

        /** @var Order $orderModel */
        $orderModel = $paymentDO->getPayment()->getOrder();

        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = [
                'amount' => number_format((float) $item->getPrice(), 2, '.', ''),
                'description' => mb_substr($item->getName() ?? '', 0, 255),
                'quantity' => (string) ((int) $item->getQtyOrdered()),
                'product_id' => $item->getSku() ?? '',
            ];
        }

        $callbackUrl = $this->urlBuilder->getUrl(
            'shubo_bog/payment/callback',
            ['_secure' => true]
        );
        $successUrl = $this->urlBuilder->getUrl(
            'checkout/onepage/success',
            ['_secure' => true]
        );
        $failUrl = $this->urlBuilder->getUrl(
            'checkout/onepage/failure',
            ['_secure' => true]
        );

        return [
            'intent' => 'CAPTURE',
            'items' => $items,
            'shop_order_id' => $order->getOrderIncrementId(),
            'redirect_urls' => [
                'success' => $successUrl,
                'fail' => $failUrl,
            ],
            'purchase_units' => [
                'currency' => $this->config->getCurrency(),
                'total_amount' => number_format($amount, 2, '.', ''),
            ],
            'callback_url' => $callbackUrl,
        ];
    }
}
