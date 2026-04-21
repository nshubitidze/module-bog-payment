<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;

/**
 * OAuth2 client-credentials token provider for the BOG Payments API.
 *
 * BUG-BOG-9: tokens are cached per-storeId in Magento's persistent cache pool
 * (`cache.static` → `Magento\Framework\App\CacheInterface`) so:
 *   - multi-storefront deployments with different BOG client creds never
 *     cross-pollinate tokens (storeId A's token must not be used for storeId B);
 *   - PHP-FPM workers share the token across requests (in-memory cache alone
 *     would re-auth on every worker cold start).
 *
 * Cache payload is a small JSON blob `{ access_token, expires_at }`; the wire
 * TTL is `expires_in - TOKEN_TTL_BUFFER_SECONDS` (60 s) so we always refetch
 * strictly before BOG expires the token. Cache read errors are non-fatal —
 * we log a WARN and fall through to a fresh HTTP fetch.
 */
class OAuthTokenProvider
{
    private const TOKEN_TTL_BUFFER_SECONDS = 60;
    private const DEFAULT_EXPIRES_IN = 3600;
    private const CACHE_KEY_PREFIX = 'bog_oauth_token_';
    private const CACHE_TAG = 'SHUBO_BOG_OAUTH';

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Get a valid OAuth2 access token for the given store, refreshing if
     * the cached value is missing, malformed, or within the TTL buffer.
     *
     * @throws LocalizedException
     */
    public function getAccessToken(?int $storeId = null): string
    {
        $cacheKey = $this->cacheKey($storeId);

        $cached = $this->readCachedToken($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        return $this->refreshToken($storeId);
    }

    /**
     * Force refresh the OAuth2 token for the given store, bypassing the cache.
     *
     * @throws LocalizedException
     */
    public function refreshToken(?int $storeId = null): string
    {
        $clientId = $this->config->getClientId($storeId);
        $clientSecret = $this->config->getClientSecret($storeId);

        if ($clientId === '' || $clientSecret === '') {
            throw new LocalizedException(
                __('BOG Payments API credentials are not configured. Please set Client ID and Client Secret.')
            );
        }

        $tokenUrl = $this->config->getOAuthTokenUrl($storeId);

        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $curl->setOptions([CURLOPT_TIMEOUT => 30]);

        $postData = http_build_query([
            'grant_type' => 'client_credentials',
        ]);

        $curl->setCredentials($clientId, $clientSecret);

        $this->logger->debug('BOG OAuth token request', [
            'url' => $tokenUrl,
            'store_id' => $storeId,
        ]);

        try {
            $curl->post($tokenUrl, $postData);
        } catch (\Exception $e) {
            $this->logger->error('BOG OAuth token request failed', [
                'exception' => $e->getMessage(),
            ]);
            throw new LocalizedException(
                __('Unable to authenticate with BOG Payments API. Please try again later.')
            );
        }

        $statusCode = $curl->getStatus();
        $responseBody = $curl->getBody();

        if ($statusCode !== 200) {
            $this->logger->error('BOG OAuth token request returned non-200 status', [
                'status' => $statusCode,
                'response' => $responseBody,
            ]);
            throw new LocalizedException(
                __('BOG Payments authentication failed. HTTP status: %1', $statusCode)
            );
        }

        /** @var array{access_token?: string, expires_in?: int}|null $response */
        $response = json_decode($responseBody, true);

        if (!is_array($response) || empty($response['access_token'])) {
            $this->logger->error('BOG OAuth token response missing access_token', [
                'response' => $responseBody,
            ]);
            throw new LocalizedException(
                __('Invalid response from BOG Payments authentication endpoint.')
            );
        }

        $accessToken = (string) $response['access_token'];
        $expiresIn = (int) ($response['expires_in'] ?? self::DEFAULT_EXPIRES_IN);
        $ttl = max(1, $expiresIn - self::TOKEN_TTL_BUFFER_SECONDS);
        $expiresAt = time() + $ttl;

        $this->storeCachedToken($this->cacheKey($storeId), $accessToken, $expiresAt, $ttl);

        return $accessToken;
    }

    /**
     * Read the cached token for the given key. Returns null on miss, parse
     * error, or expired payload. Parse errors are logged WARN so operators
     * see them, but never block a fresh fetch downstream.
     */
    private function readCachedToken(string $cacheKey): ?string
    {
        $raw = $this->cache->load($cacheKey);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('BOG OAuth cache read failed: malformed JSON', [
                'cache_key' => $cacheKey,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }

        if (
            !is_array($decoded)
            || !isset($decoded['access_token'], $decoded['expires_at'])
            || !is_string($decoded['access_token'])
            || !is_int($decoded['expires_at'])
        ) {
            $this->logger->warning('BOG OAuth cache read failed: unexpected payload shape', [
                'cache_key' => $cacheKey,
            ]);
            return null;
        }

        if ($decoded['expires_at'] <= time()) {
            return null;
        }

        return $decoded['access_token'];
    }

    /**
     * Persist the token to cache. Best-effort — storage failures are logged
     * but never thrown so a single cache backend hiccup cannot block a
     * payment.
     */
    private function storeCachedToken(string $cacheKey, string $accessToken, int $expiresAt, int $ttl): void
    {
        try {
            $payload = json_encode(
                ['access_token' => $accessToken, 'expires_at' => $expiresAt],
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            $this->logger->warning('BOG OAuth cache encode failed', [
                'cache_key' => $cacheKey,
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        try {
            $this->cache->save($payload, $cacheKey, [self::CACHE_TAG], $ttl);
        } catch (\Exception $e) {
            $this->logger->warning('BOG OAuth cache save failed', [
                'cache_key' => $cacheKey,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function cacheKey(?int $storeId): string
    {
        return self::CACHE_KEY_PREFIX . ($storeId ?? 0);
    }
}
