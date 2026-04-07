<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;

class OAuthTokenProvider
{
    private const TOKEN_TTL_BUFFER_SECONDS = 60;

    private ?string $cachedToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get a valid OAuth2 access token, refreshing if expired.
     *
     * @throws LocalizedException
     */
    public function getAccessToken(?int $storeId = null): string
    {
        if ($this->isTokenValid() && $this->cachedToken !== null) {
            return $this->cachedToken;
        }

        return $this->refreshToken($storeId);
    }

    /**
     * Force refresh the OAuth2 token.
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

        // Use Basic Auth with client_id:client_secret
        $curl->setCredentials($clientId, $clientSecret);

        $this->logger->debug('BOG OAuth token request', [
            'url' => $tokenUrl,
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

        $this->cachedToken = $response['access_token'];
        $expiresIn = (int) ($response['expires_in'] ?? 3600);
        $this->tokenExpiresAt = time() + $expiresIn - self::TOKEN_TTL_BUFFER_SECONDS;

        return $this->cachedToken;
    }

    private function isTokenValid(): bool
    {
        return $this->cachedToken !== null
            && $this->tokenExpiresAt !== null
            && time() < $this->tokenExpiresAt;
    }
}
