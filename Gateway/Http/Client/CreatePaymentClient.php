<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Http\Client;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Model\OAuthTokenProvider;

class CreatePaymentClient implements ClientInterface
{
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly OAuthTokenProvider $tokenProvider,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send create-order request to BOG Payments API.
     *
     * @param TransferInterface $transferObject
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        /** @var array<string, mixed> $body */
        $body = $transferObject->getBody();

        $url = $this->config->getCreateOrderUrl();
        $idempotencyKey = $this->generateIdempotencyKey();
        $locale = (string) ($body['__locale'] ?? 'ka');
        $theme = $this->config->getPaymentTheme();

        // Remove internal metadata keys before sending to BOG
        unset($body['__locale']);

        $this->logger->debug('BOG create order request', [
            'url' => $url,
            'idempotency_key' => $idempotencyKey,
            'body' => $this->sanitizeForLog($body),
        ]);

        $accessToken = $this->tokenProvider->getAccessToken();
        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Authorization', 'Bearer ' . $accessToken);
        $curl->addHeader('Idempotency-Key', $idempotencyKey);
        $curl->addHeader('Accept-Language', $locale);
        $curl->addHeader('Theme', $theme);
        $curl->setOptions([CURLOPT_TIMEOUT => 60]);

        $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

        try {
            $curl->post($url, $jsonBody);
        } catch (\Exception $e) {
            $this->logger->error('BOG create order HTTP request failed', [
                'exception' => $e->getMessage(),
            ]);
            throw new LocalizedException(
                __('Unable to connect to BOG Payments API. Please try again later.')
            );
        }

        $statusCode = $curl->getStatus();
        $responseBody = $curl->getBody();

        $this->logger->debug('BOG create order response', [
            'status' => $statusCode,
            'body' => $responseBody,
        ]);

        /** @var array<string, mixed>|null $response */
        $response = json_decode($responseBody, true);

        if (!is_array($response)) {
            throw new LocalizedException(
                __('Invalid response from BOG Payments API.')
            );
        }

        $response['http_status'] = $statusCode;

        return $response;
    }

    /**
     * Generate a UUID v4 for the Idempotency-Key header.
     */
    private function generateIdempotencyKey(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Remove sensitive data from log output.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeForLog(array $data): array
    {
        $sanitized = $data;
        unset($sanitized['config']);
        return $sanitized;
    }
}
