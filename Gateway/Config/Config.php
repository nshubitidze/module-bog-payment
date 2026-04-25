<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;

class Config extends GatewayConfig
{
    public const METHOD_CODE = 'shubo_bog';

    private const KEY_ACTIVE = 'active';
    private const KEY_TITLE = 'title';
    private const KEY_CLIENT_ID = 'client_id';
    private const KEY_CLIENT_SECRET = 'client_secret';
    private const KEY_RSA_PUBLIC_KEY = 'rsa_public_key';
    private const KEY_API_URL = 'api_url';
    private const KEY_OAUTH_URL = 'oauth_url';
    private const KEY_ENVIRONMENT = 'environment';
    private const KEY_SPLIT_ENABLED = 'split_enabled';
    private const KEY_SPLIT_AUTO_SETTLE = 'split_auto_settle';
    private const KEY_SPLIT_RECEIVERS = 'split_receivers';
    private const KEY_DEBUG = 'debug';
    private const KEY_PAYMENT_LIFETIME = 'payment_lifetime';
    private const KEY_PAYMENT_THEME = 'payment_theme';
    private const KEY_PAYMENT_METHODS = 'payment_methods';
    private const KEY_PAYMENT_ACTION_MODE = 'payment_action_mode';

    private const PAYMENT_LIFETIME_MIN = 2;
    private const PAYMENT_LIFETIME_MAX = 1440;
    private const PAYMENT_LIFETIME_DEFAULT = 15;

    /**
     * BUG-BOG-15: the resolver is optional at the constructor boundary to
     * avoid the circular dep that would arise from ApiUrlResolver depending
     * on Config + Config depending on ApiUrlResolver. DI wires it via
     * `@inject` property since constructor injection would fail the
     * virtualType parents. Any caller that wants the effective, environment-
     * aware base URL should go via `getEffectiveApiUrl()` below — the
     * `getCreateOrderUrl / getOrderStatusUrl / getCaptureUrl / getRefundUrl`
     * helpers already use it.
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        ?ApiUrlResolver $apiUrlResolver = null,
        ?string $methodCode = self::METHOD_CODE,
        string $pathPattern = GatewayConfig::DEFAULT_PATH_PATTERN,
    ) {
        $this->apiUrlResolver = $apiUrlResolver;
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    private ?ApiUrlResolver $apiUrlResolver;

    /**
     * Return the effective API base URL, applying environment-based
     * defaults when the admin hasn't set api_url explicitly.
     */
    public function getEffectiveApiUrl(?int $storeId = null): string
    {
        if ($this->apiUrlResolver !== null) {
            return rtrim($this->apiUrlResolver->resolve($storeId), '/');
        }
        // Fallback for legacy instantiation paths that don't inject the
        // resolver — behave as before (raw admin value).
        return $this->getApiUrl($storeId);
    }

    public function isActive(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE, $storeId);
    }

    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_TITLE, $storeId);
    }

    public function getClientId(?int $storeId = null): string
    {
        return (string) $this->getValue(self::KEY_CLIENT_ID, $storeId);
    }

    public function getClientSecret(?int $storeId = null): string
    {
        $value = (string) $this->getValue(self::KEY_CLIENT_SECRET, $storeId);
        return $this->encryptor->decrypt($value);
    }

    /**
     * Get the decrypted BOG RSA public key (PEM) used to verify callback
     * signatures. Returns '' when not configured so callers can cleanly
     * fall through to the status-API fallback (BUG-BOG-3 fail-closed
     * contract).
     */
    public function getRsaPublicKey(?int $storeId = null): string
    {
        $value = (string) $this->getValue(self::KEY_RSA_PUBLIC_KEY, $storeId);
        if ($value === '') {
            return '';
        }
        return $this->encryptor->decrypt($value);
    }

    /**
     * Raw admin-configured api_url (empty string when not set). The
     * environment-aware resolution lives in `ApiUrlResolver::resolve()` so
     * callers that want the effective URL should use the resolver directly
     * (or the downstream helpers on this class, which have been updated to
     * delegate through the resolver).
     */
    public function getApiUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->getValue(self::KEY_API_URL, $storeId), '/');
    }

    /**
     * Get the OAuth2 token endpoint URL.
     *
     * The OAuth URL is separate from the API URL because BOG uses a different
     * host for authentication (oauth2.bog.ge) vs API calls (api.bog.ge).
     */
    public function getOAuthUrl(?int $storeId = null): string
    {
        $oauthUrl = (string) $this->getValue(self::KEY_OAUTH_URL, $storeId);
        if ($oauthUrl !== '') {
            return rtrim($oauthUrl, '/');
        }

        // Fallback: derive from effective API URL for backward compatibility
        return $this->getEffectiveApiUrl($storeId) . '/oauth2/token';
    }

    public function getEnvironment(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_ENVIRONMENT, $storeId) ?: 'test');
    }

    public function isSplitEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_SPLIT_ENABLED, $storeId);
    }

    public function isSplitAutoSettleEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_SPLIT_AUTO_SETTLE, $storeId);
    }

    public function getSplitReceivers(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_SPLIT_RECEIVERS, $storeId) ?: '');
    }

    public function isDebugEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_DEBUG, $storeId);
    }

    /**
     * Get payment session lifetime in minutes.
     *
     * Clamped between 2 and 1440 minutes (24 hours).
     */
    public function getPaymentLifetime(?int $storeId = null): int
    {
        $value = (int) ($this->getValue(self::KEY_PAYMENT_LIFETIME, $storeId) ?: self::PAYMENT_LIFETIME_DEFAULT);
        return min(max($value, self::PAYMENT_LIFETIME_MIN), self::PAYMENT_LIFETIME_MAX);
    }

    /**
     * Get payment page theme: 'light' or 'dark'.
     */
    public function getPaymentTheme(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_PAYMENT_THEME, $storeId) ?: 'light');
    }

    /**
     * Get allowed payment methods as an array.
     *
     * @return array<int, string> e.g. ['card', 'google_pay']
     */
    public function getAllowedPaymentMethods(?int $storeId = null): array
    {
        $value = (string) ($this->getValue(self::KEY_PAYMENT_METHODS, $storeId) ?: 'card');
        $methods = array_map('trim', explode(',', $value));
        return array_values(array_filter($methods, static fn(string $m): bool => $m !== ''));
    }

    /**
     * Get payment capture mode: 'automatic' or 'manual'.
     */
    public function getPaymentActionMode(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_PAYMENT_ACTION_MODE, $storeId) ?: 'automatic');
    }

    /**
     * Whether pre-authorization (manual capture) is enabled.
     */
    public function isPreauth(?int $storeId = null): bool
    {
        return $this->getPaymentActionMode($storeId) === 'manual';
    }

    public function getOAuthTokenUrl(?int $storeId = null): string
    {
        return $this->getOAuthUrl($storeId);
    }

    public function getCreateOrderUrl(?int $storeId = null): string
    {
        return $this->getEffectiveApiUrl($storeId) . '/ecommerce/orders';
    }

    /**
     * Refund endpoint for the new BOG Payments API:
     *   POST {api_url}/payment/refund/{order_id}     body: {amount}
     *
     * Session 8 Priority 1.1 fix — the prior signature was
     * `getRefundUrl(?int $storeId = null)` returning
     * `${api_url}/checkout/refund`, the LEGACY iPay form-encoded endpoint
     * (Bog used to expose at `ipay.ge/opay/api/v1`). The current default
     * `api_url` (`https://api.bog.ge/payments/v1`) does not host that
     * legacy path; refund attempts 404'd. The new shape carries the BOG
     * order_id in the URL and only `amount` in the body.
     */
    public function getRefundUrl(string $bogOrderId, ?int $storeId = null): string
    {
        return $this->getEffectiveApiUrl($storeId) . '/payment/refund/' . $bogOrderId;
    }

    /**
     * Get receipt/status URL for a BOG order.
     *
     * @param string $orderId BOG order ID
     */
    public function getOrderStatusUrl(string $orderId, ?int $storeId = null): string
    {
        return $this->getEffectiveApiUrl($storeId) . '/receipt/' . $orderId;
    }

    /**
     * Get pre-auth capture approval URL.
     *
     * @param string $orderId BOG order ID
     */
    public function getCaptureUrl(string $orderId, ?int $storeId = null): string
    {
        return $this->getEffectiveApiUrl($storeId) . '/payment/authorization/approve/' . $orderId;
    }
}
