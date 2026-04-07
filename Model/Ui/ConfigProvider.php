<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Shubo\BogPayment\Gateway\Config\Config;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'shubo_bog';

    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly ResolverInterface $localeResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if (!$this->config->isActive()) {
            return [];
        }

        return [
            'payment' => [
                self::CODE => [
                    'isActive' => true,
                    'title' => $this->config->getTitle(),
                    'initiateUrl' => $this->urlBuilder->getUrl(
                        'shubo_bog/payment/initiate',
                        ['_secure' => true]
                    ),
                    'environment' => $this->config->getEnvironment(),
                    'locale' => $this->resolveLocale(),
                    'theme' => $this->config->getPaymentTheme(),
                    'paymentMethods' => $this->config->getAllowedPaymentMethods(),
                ],
            ],
        ];
    }

    /**
     * Map Magento locale to BOG Payments-supported language code.
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
