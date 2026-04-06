<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Shubo\BogPayment\Gateway\Config\Config;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'shubo_bog';

    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
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
                ],
            ],
        ];
    }
}
