<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Validator;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;

/**
 * Validates BOG callbacks using two strategies:
 *
 * 1. Primary: SHA256withRSA signature verification (Callback-Signature header)
 * 2. Fallback: checking payment status via the BOG Receipt/Status API
 *
 * BUG-BOG-3 (closed): the RSA public key is loaded from encrypted system
 * config at `payment/shubo_bog/rsa_public_key` via
 * `Shubo\BogPayment\Gateway\Config\Config::getRsaPublicKey()`. Empty config
 * is the expected pre-cutover state and silently falls through to the
 * status-API fallback (info-level log). A configured-but-malformed PEM
 * raises a warning so the operator notices.
 */
class CallbackValidator
{
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_CAPTURED = 'captured';

    public function __construct(
        private readonly StatusClient $statusClient,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
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

            if ($this->verifySignature($callbackBody, $signature, $storeId)) {
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
     * Verify SHA256withRSA signature using the configured BOG public key.
     *
     * @param string $data The raw callback body
     * @param string $signature Base64-encoded signature from Callback-Signature header
     * @param int $storeId Store scope for the configured key lookup
     */
    private function verifySignature(string $data, string $signature, int $storeId = 0): bool
    {
        $publicKey = $this->getPublicKey($storeId);
        if ($publicKey === false) {
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
     * Load the BOG RSA public key from encrypted system config.
     *
     * Returns false in two cases:
     *  - Empty config: the expected pre-cutover state (info-level log).
     *  - Malformed PEM: an operator misconfiguration (warning-level log).
     *
     * Both cases let `validate()` fall through to the status-API fallback.
     */
    private function getPublicKey(int $storeId = 0): \OpenSSLAsymmetricKey|false
    {
        $pem = $this->config->getRsaPublicKey($storeId);
        if ($pem === '') {
            $this->logger->info(
                'BOG callback: RSA public key not configured, '
                . 'signature verification skipped (fallback to status API)'
            );
            return false;
        }

        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            $this->logger->warning(
                'BOG callback: configured RSA public key is not a valid PEM, '
                . 'falling back to status API'
            );
            return false;
        }

        return $publicKey;
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
