<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Validator;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Model\OAuthTokenProvider;

/**
 * Validates BOG callback by checking payment status via the Status API.
 */
class CallbackValidator
{
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_CAPTURED = 'captured';

    public function __construct(
        private readonly Config $config,
        private readonly OAuthTokenProvider $tokenProvider,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate that a BOG order has been successfully captured/completed.
     *
     * @param string $bogOrderId BOG order ID to verify
     * @return array{valid: bool, status: string, data: array<string, mixed>}
     * @throws LocalizedException
     */
    public function validate(string $bogOrderId): array
    {
        $accessToken = $this->tokenProvider->getAccessToken();
        $statusUrl = $this->config->getOrderStatusUrl($bogOrderId);

        $curl = $this->curlFactory->create();
        $curl->addHeader('Authorization', 'Bearer ' . $accessToken);
        $curl->addHeader('Content-Type', 'application/json');
        $curl->setOptions([CURLOPT_TIMEOUT => 30]);

        try {
            $curl->get($statusUrl);
        } catch (\Exception $e) {
            $this->logger->error('BOG status check request failed', [
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
            throw new LocalizedException(
                __('Unable to verify payment status with BOG iPay.')
            );
        }

        $statusCode = $curl->getStatus();
        $responseBody = $curl->getBody();

        $this->logger->info('BOG status check response', [
            'bog_order_id' => $bogOrderId,
            'http_status' => $statusCode,
            'response' => $responseBody,
        ]);

        if ($statusCode !== 200) {
            return [
                'valid' => false,
                'status' => 'error',
                'data' => ['http_status' => $statusCode],
            ];
        }

        /** @var array<string, mixed>|null $response */
        $response = json_decode($responseBody, true);

        if (!is_array($response)) {
            return [
                'valid' => false,
                'status' => 'invalid_response',
                'data' => [],
            ];
        }

        $paymentStatus = strtolower((string) ($response['status'] ?? ''));
        $isValid = in_array($paymentStatus, [self::STATUS_COMPLETED, self::STATUS_CAPTURED], true);

        return [
            'valid' => $isValid,
            'status' => $paymentStatus,
            'data' => $response,
        ];
    }
}
