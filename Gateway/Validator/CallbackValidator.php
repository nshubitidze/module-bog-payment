<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Validator;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;

/**
 * Validates BOG callbacks using two strategies:
 *
 * 1. Primary: SHA256withRSA signature verification (Callback-Signature header)
 * 2. Fallback: checking payment status via the BOG Receipt/Status API
 */
class CallbackValidator
{
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_CAPTURED = 'captured';

    /**
     * BOG public key for SHA256withRSA callback signature verification.
     *
     * This is the RSA public key provided by BOG for verifying webhook signatures.
     * In production, consider loading this from config or a PEM file.
     */
    private const BOG_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuGfbszLsQ/JosnCIsGV3
y6WrEg/YCmPaGMbU5590CJGUkYqxYs8kR2sHq32t/Nh6v4zXKEB1V5RiPz0hiwf
CEPsKrSVr6bNpSNqnMHMwvJavCWbYY1g23yxBHg4WHaIEbyhJ3yjAlRZpyqJKw5b
lSDwIUjt3CK3RLnEKsRmzAdPp8aGoMIFzEGKhb1b1lzZPJQlWz0BQn1bnPkC6yB7
XkQ1AwUF1TboJPH2wnqC0EKAXT1KWLJpJF0gI6lMR7hH5XgL7cYg9dNahYK7VHri
OVDC3jjz1Wv4SqRON8V/YkFMrH3CxJzh0CQ6r82MVGB+PLRfHN7S73JVqaTvaxJw
3QIDAQAB
-----END PUBLIC KEY-----
PEM;

    public function __construct(
        private readonly StatusClient $statusClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate a BOG callback.
     *
     * If a Callback-Signature header is present, verify the SHA256withRSA signature.
     * Otherwise, fall back to checking the BOG Status/Receipt API.
     *
     * @param string $bogOrderId BOG order ID to verify
     * @param string $callbackBody Raw callback request body
     * @param string|null $signature Callback-Signature header value
     * @param int $storeId Store ID for config lookup
     * @return array{valid: bool, status: string, data: array<string, mixed>}
     * @throws LocalizedException
     */
    public function validate(
        string $bogOrderId,
        string $callbackBody = '',
        ?string $signature = null,
        int $storeId = 0,
    ): array {
        // Primary: signature-based verification
        if ($signature !== null && $signature !== '') {
            $this->logger->info('BOG callback: verifying SHA256withRSA signature', [
                'bog_order_id' => $bogOrderId,
            ]);

            if ($this->verifySignature($callbackBody, $signature)) {
                $this->logger->info('BOG callback: signature verified successfully', [
                    'bog_order_id' => $bogOrderId,
                ]);

                /** @var array<string, mixed> $callbackData */
                $callbackData = json_decode($callbackBody, true) ?: [];
                // BUG-BOG-2: the new BOG Payments API wraps the receipt under
                // a top-level `body` key. Unwrap before reading order_status.
                $paymentStatus = $this->extractOrderStatusKey($callbackData);
                $isValid = in_array($paymentStatus, [self::STATUS_COMPLETED, self::STATUS_CAPTURED], true);

                return [
                    'valid' => $isValid,
                    'status' => $paymentStatus,
                    'data' => $callbackData,
                ];
            }

            $this->logger->warning('BOG callback: signature verification failed, falling back to status API', [
                'bog_order_id' => $bogOrderId,
            ]);
        }

        // Fallback: verify via BOG Status/Receipt API
        return $this->validateViaStatusApi($bogOrderId, $storeId);
    }

    /**
     * Extract order_status.key from a BOG payload, supporting both the new
     * API's nested `body.order_status.key` shape and the legacy flat
     * `order_status.key` shape. Falls through to a top-level `status` string
     * for older iPay responses.
     *
     * BUG-BOG-2 fix. Returns a lowercased status string, or '' if no key
     * could be resolved.
     *
     * @param array<string, mixed> $payload
     */
    private function extractOrderStatusKey(array $payload): string
    {
        $container = is_array($payload['body'] ?? null) ? $payload['body'] : $payload;

        if (
            is_array($container['order_status'] ?? null)
            && isset($container['order_status']['key'])
        ) {
            return strtolower((string) $container['order_status']['key']);
        }

        if (isset($container['status']) && !is_array($container['status'])) {
            return strtolower((string) $container['status']);
        }

        return '';
    }

    /**
     * Verify SHA256withRSA signature using BOG's public key.
     *
     * @param string $data The raw callback body
     * @param string $signature Base64-encoded signature from Callback-Signature header
     */
    private function verifySignature(string $data, string $signature): bool
    {
        $publicKey = openssl_pkey_get_public(self::BOG_PUBLIC_KEY);
        if ($publicKey === false) {
            $this->logger->error('BOG callback: failed to load public key for signature verification');
            return false;
        }

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            $this->logger->warning('BOG callback: signature is not valid base64');
            return false;
        }

        $result = openssl_verify($data, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /**
     * Validate payment by calling BOG Status/Receipt API.
     *
     * @return array{valid: bool, status: string, data: array<string, mixed>}
     * @throws LocalizedException
     */
    private function validateViaStatusApi(string $bogOrderId, int $storeId): array
    {
        try {
            $response = $this->statusClient->checkStatus($bogOrderId, $storeId);
        } catch (BogApiException $e) {
            $this->logger->error('BOG status check failed during callback validation', [
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
            throw new LocalizedException(
                __('Unable to verify payment status with BOG Payments API.')
            );
        }

        // BUG-BOG-2: unwrap `body` if the receipt API returns the nested shape.
        $paymentStatus = $this->extractOrderStatusKey($response);
        $isValid = in_array($paymentStatus, [self::STATUS_COMPLETED, self::STATUS_CAPTURED], true);

        $this->logger->info('BOG status API validation result', [
            'bog_order_id' => $bogOrderId,
            'status' => $paymentStatus,
            'valid' => $isValid,
        ]);

        return [
            'valid' => $isValid,
            'status' => $paymentStatus,
            'data' => $response,
        ];
    }
}
