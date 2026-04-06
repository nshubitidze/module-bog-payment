<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Request;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

class InitializeRequestBuilder implements BuilderInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResolverInterface $localeResolver,
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

        $storeId = (int) $order->getStoreId();
        $currency = $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();
        $locale = $this->resolveLocale();

        return [
            'intent' => 'CAPTURE',
            'items' => $items,
            'locale' => $locale,
            'shop_order_id' => $order->getOrderIncrementId(),
            'redirect_urls' => [
                'success' => $successUrl,
                'fail' => $failUrl,
            ],
            'purchase_units' => [
                'currency' => $currency,
                'total_amount' => number_format($amount, 2, '.', ''),
            ],
            'callback_url' => $callbackUrl,
        ];
    }

    /**
     * Map Magento locale to BOG-supported language code.
     *
     * BOG iPay supports: ka (Georgian), en (English).
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
