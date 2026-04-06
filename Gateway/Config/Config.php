<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;
use Magento\Store\Model\ScopeInterface;

class Config extends GatewayConfig
{
    public const METHOD_CODE = 'shubo_bog';

    private const KEY_ACTIVE = 'active';
    private const KEY_TITLE = 'title';
    private const KEY_CLIENT_ID = 'client_id';
    private const KEY_CLIENT_SECRET = 'client_secret';
    private const KEY_API_URL = 'api_url';

    private const KEY_ENVIRONMENT = 'environment';
    private const KEY_SPLIT_ENABLED = 'split_enabled';
    private const KEY_DEBUG = 'debug';

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        ?string $methodCode = self::METHOD_CODE,
        string $pathPattern = GatewayConfig::DEFAULT_PATH_PATTERN,
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    public function isActive(): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE);
    }

    public function getTitle(): string
    {
        return (string) $this->getValue(self::KEY_TITLE);
    }

    public function getClientId(): string
    {
        return (string) $this->getValue(self::KEY_CLIENT_ID);
    }

    public function getClientSecret(): string
    {
        $value = (string) $this->getValue(self::KEY_CLIENT_SECRET);
        return $this->encryptor->decrypt($value);
    }

    public function getApiUrl(): string
    {
        return rtrim((string) $this->getValue(self::KEY_API_URL), '/');
    }

    public function getEnvironment(): string
    {
        return (string) ($this->getValue(self::KEY_ENVIRONMENT) ?: 'test');
    }

    public function isSplitEnabled(): bool
    {
        return (bool) $this->getValue(self::KEY_SPLIT_ENABLED);
    }

    public function isDebugEnabled(): bool
    {
        return (bool) $this->getValue(self::KEY_DEBUG);
    }

    public function getOAuthTokenUrl(): string
    {
        return $this->getApiUrl() . '/oauth2/token';
    }

    public function getCreateOrderUrl(): string
    {
        return $this->getApiUrl() . '/checkout/orders';
    }

    public function getRefundUrl(): string
    {
        return $this->getApiUrl() . '/checkout/refund';
    }

    /**
     * @param string $orderId BOG order ID
     */
    public function getOrderStatusUrl(string $orderId): string
    {
        return $this->getApiUrl() . '/checkout/orders/' . $orderId;
    }
}
