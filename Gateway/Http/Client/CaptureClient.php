<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Model\OAuthTokenProvider;

/**
 * HTTP client for capturing pre-authorized payments via BOG Payments API.
 *
 * Endpoint: POST /payment/authorization/approve/{order_id}
 * Body: {"amount": 5.00, "description": "..."} (amount optional for full capture)
 */
class CaptureClient
{
    public function __construct(
        private readonly Config $config,
        private readonly OAuthTokenProvider $tokenProvider,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Capture a pre-authorized payment.
     *
     * @param string $orderId BOG order ID
     * @param int $storeId Store ID for config/token lookup
     * @param float|null $amount Amount to capture (null for full capture)
     * @param string $description Capture description
     * @return array<string, mixed> BOG response
     * @throws BogApiException
     */
    public function capture(
        string $orderId,
        int $storeId,
        ?float $amount = null,
        string $description = '',
    ): array {
        $url = $this->config->getCaptureUrl($orderId, $storeId);

        $body = [];
        if ($amount !== null) {
            $body['amount'] = round($amount, 2);
        }
        if ($description !== '') {
            $body['description'] = $description;
        }

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('BOG Capture request', [
                'url' => $url,
                'order_id' => $orderId,
                'body' => $body,
            ]);
        }

        try {
            $accessToken = $this->tokenProvider->getAccessToken($storeId);

            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Authorization', 'Bearer ' . $accessToken);
            $curl->setOptions([CURLOPT_TIMEOUT => 30]);
            $curl->post($url, (string) $this->json->serialize($body));

            $responseBody = $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($this->config->isDebugEnabled($storeId)) {
                $this->logger->debug('BOG Capture response', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new BogApiException(
                    __('BOG capture API returned HTTP %1', $statusCode)
                );
            }

            $response = $this->json->unserialize($responseBody);
            if (!is_array($response)) {
                throw new BogApiException(__('Invalid capture response from BOG Payments API'));
            }

            return $response;
        } catch (BogApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('BOG Capture error: ' . $e->getMessage(), [
                'exception' => $e,
                'order_id' => $orderId,
            ]);
            throw new BogApiException(
                __('Unable to capture payment via BOG Payments API.'),
                $e
            );
        }
    }
}
