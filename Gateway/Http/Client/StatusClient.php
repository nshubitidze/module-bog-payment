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
 * Standalone HTTP client for checking payment status via BOG Payments API.
 *
 * Not part of the gateway command pool -- used directly by the callback
 * validator and future cron reconciler.
 *
 * Endpoint: GET /receipt/{order_id}
 */
class StatusClient
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
     * Check payment status for a given BOG order ID.
     *
     * @param string $orderId The BOG order ID
     * @param int $storeId Store ID for config/token lookup
     * @return array<string, mixed> BOG receipt response
     * @throws BogApiException
     */
    public function checkStatus(string $orderId, int $storeId): array
    {
        $url = $this->config->getOrderStatusUrl($orderId, $storeId);

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('BOG Status request', [
                'url' => $url,
                'order_id' => $orderId,
            ]);
        }

        try {
            $accessToken = $this->tokenProvider->getAccessToken($storeId);

            $curl = $this->curlFactory->create();
            $curl->addHeader('Authorization', 'Bearer ' . $accessToken);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->setOptions([CURLOPT_TIMEOUT => 30]);
            $curl->get($url);

            $responseBody = $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($this->config->isDebugEnabled($storeId)) {
                $this->logger->debug('BOG Status response', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new BogApiException(
                    __('BOG status API returned HTTP %1', $statusCode)
                );
            }

            $response = $this->json->unserialize($responseBody);

            if (!is_array($response)) {
                throw new BogApiException(__('Invalid status response from BOG Payments API'));
            }

            return $response;
        } catch (BogApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('BOG Status error: ' . $e->getMessage(), [
                'exception' => $e,
                'order_id' => $orderId,
            ]);
            throw new BogApiException(
                __('Unable to check payment status via BOG Payments API.'),
                $e
            );
        }
    }
}
