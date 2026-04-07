<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Http\Client;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Model\OAuthTokenProvider;

class CreatePaymentClient implements ClientInterface
{
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly OAuthTokenProvider $tokenProvider,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Send create-order request to BOG iPay API.
     *
     * @param TransferInterface $transferObject
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        /** @var array<string, mixed> $body */
        $body = $transferObject->getBody();

        $this->logger->debug([
            'request_url' => $this->config->getCreateOrderUrl(),
            'request_body' => $this->sanitizeForLog($body),
        ]);

        $accessToken = $this->tokenProvider->getAccessToken();
        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Authorization', 'Bearer ' . $accessToken);
        $curl->setOptions([CURLOPT_TIMEOUT => 60]);

        $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

        try {
            $curl->post($this->config->getCreateOrderUrl(), $jsonBody);
        } catch (\Exception $e) {
            $this->logger->debug([
                'error' => 'BOG create order HTTP request failed',
                'exception' => $e->getMessage(),
            ]);
            throw new LocalizedException(
                __('Unable to connect to BOG iPay. Please try again later.')
            );
        }

        $statusCode = $curl->getStatus();
        $responseBody = $curl->getBody();

        $this->logger->debug([
            'response_status' => $statusCode,
            'response_body' => $responseBody,
        ]);

        /** @var array<string, mixed>|null $response */
        $response = json_decode($responseBody, true);

        if (!is_array($response)) {
            throw new LocalizedException(
                __('Invalid response from BOG iPay API.')
            );
        }

        $response['http_status'] = $statusCode;

        return $response;
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
