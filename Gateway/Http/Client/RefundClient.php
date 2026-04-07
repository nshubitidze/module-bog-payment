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

class RefundClient implements ClientInterface
{
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly OAuthTokenProvider $tokenProvider,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send refund request to BOG Payments API.
     *
     * @param TransferInterface $transferObject
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        /** @var array<string, mixed> $body */
        $body = $transferObject->getBody();

        $this->logger->debug('BOG refund request', [
            'url' => $this->config->getRefundUrl(),
            'body' => $body,
        ]);

        $accessToken = $this->tokenProvider->getAccessToken();
        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Authorization', 'Bearer ' . $accessToken);
        $curl->setOptions([CURLOPT_TIMEOUT => 60]);

        $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

        try {
            $curl->post($this->config->getRefundUrl(), $jsonBody);
        } catch (\Exception $e) {
            $this->logger->error('BOG refund HTTP request failed', [
                'exception' => $e->getMessage(),
            ]);
            throw new LocalizedException(
                __('Unable to process refund via BOG Payments API. Please try again later.')
            );
        }

        $statusCode = $curl->getStatus();
        $responseBody = $curl->getBody();

        $this->logger->debug('BOG refund response', [
            'status' => $statusCode,
            'body' => $responseBody,
        ]);

        /** @var array<string, mixed>|null $response */
        $response = json_decode($responseBody, true);

        if (!is_array($response)) {
            throw new LocalizedException(
                __('Invalid refund response from BOG Payments API.')
            );
        }

        $response['http_status'] = $statusCode;

        return $response;
    }
}
