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
                    'createOrderUrl' => $this->urlBuilder->getUrl(
                        'shubo_bog/payment/createOrder',
                        ['_secure' => true]
                    ),
                    'environment' => $this->config->getEnvironment(),
                    'locale' => $this->resolveLocale(),
                ],
            ],
        ];
    }

    /**
     * Map Magento locale to BOG iPay-supported language code.
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
