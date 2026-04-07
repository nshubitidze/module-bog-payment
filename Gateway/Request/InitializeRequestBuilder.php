<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Request;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

class InitializeRequestBuilder implements BuilderInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
        private readonly UrlInterface $urlBuilder,
        private readonly ResolverInterface $localeResolver,
        private readonly Config $config,
    ) {
    }

    /**
     * Build the BOG Payments API create-order request payload.
     *
     * New API structure uses purchase_units.basket instead of items,
     * capture mode instead of intent, and adds ttl + payment_method.
     *
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $amount = $this->subjectReader->readAmount($buildSubject);

        $basket = [];
        foreach ($order->getItems() as $item) {
            $basket[] = [
                'product_id' => $item->getSku() ?? '',
                'description' => mb_substr($item->getName() ?? '', 0, 255),
                'quantity' => (int) $item->getQtyOrdered(),
                'unit_price' => round((float) $item->getPrice(), 2),
            ];
        }

        $callbackUrl = $this->urlBuilder->getUrl(
            'shubo_bog/payment/callback',
            ['_secure' => true]
        );
        $successUrl = $this->urlBuilder->getUrl(
            'shubo_bog/payment/return',
            ['_secure' => true]
        );
        $failUrl = $this->urlBuilder->getUrl(
            'checkout/onepage/failure',
            ['_secure' => true]
        );

        $currency = $order->getCurrencyCode();
        $locale = $this->resolveLocale();
        $captureMode = $this->config->getPaymentActionMode();
        $ttl = $this->config->getPaymentLifetime();
        $paymentMethods = $this->config->getAllowedPaymentMethods();

        return [
            'callback_url' => $callbackUrl,
            'external_order_id' => $order->getOrderIncrementId(),
            'capture' => $captureMode,
            'ttl' => $ttl,
            'payment_method' => $paymentMethods,
            'redirect_urls' => [
                'success' => $successUrl,
                'fail' => $failUrl,
            ],
            'purchase_units' => [
                'currency' => $currency,
                'total_amount' => round($amount, 2),
                'basket' => $basket,
            ],
            // Internal metadata — stripped by CreatePaymentClient before sending
            '__locale' => $locale,
        ];
    }

    /**
     * Map Magento locale to BOG-supported language code.
     *
     * BOG Payments API supports: ka (Georgian), en (English).
     */
    private function resolveLocale(): string
    {
        $locale = $this->localeResolver->getLocale();
        $language = substr($locale, 0, 2);

        return match ($language) {
            'ka' => 'ka',
            default => 'en',
        };
    }
}
