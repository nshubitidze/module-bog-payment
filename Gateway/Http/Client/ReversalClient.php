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
 * BUG-BOG-5 — HTTP client for cancelling (reversing) a pre-authorized
 * BOG payment. Sibling to {@see CaptureClient}.
 *
 *   POST {api_url}/payment/authorization/cancel/{order_id}
 *   body {"description": "..."}     (optional)
 *
 * Response shape on success:
 *   {"key": "request_received", "message": "...", "action_id": "<uuid>"}
 *
 * The endpoint is idempotent at the bank side — re-issuing the same
 * `bog_order_id` returns a deterministic result (`request_received` first
 * time, "already-cancelled"/"already-captured" thereafter), so callers do
 * not need to track an Idempotency-Key locally.
 *
 * Returns the parsed BOG response as `array<string, mixed>` on 2xx.
 *
 * Throws {@see BogApiException} on:
 *   - non-2xx HTTP (caller decides whether the response shape contains a
 *     business-reject vs server-error to surface to the admin)
 *   - transport failure (curl exception, timeout)
 *   - malformed JSON
 *
 * Carries `http_status` and `bog_order_id` into the exception payload so the
 * controller's catch arm can route through {@see UserFacingErrorMapper}
 * without having to re-derive context from log lines.
 */
class ReversalClient
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
     * Cancel a pre-authorized BOG payment, releasing the customer's hold.
     *
     * @param string $bogOrderId BOG order ID returned at create-order time
     * @param int    $storeId    Store ID for config + token scope
     * @param string $description Optional human-readable cancel reason; sent
     *                            verbatim to BOG when non-empty
     *
     * @return array<string, mixed> parsed BOG response. Always carries
     *                              `http_status` so callers can
     *                              distinguish business-reject from success.
     *
     * @throws BogApiException on transport failure or non-2xx HTTP
     */
    public function reverse(
        string $bogOrderId,
        int $storeId,
        string $description = '',
    ): array {
        if ($bogOrderId === '') {
            // Programming-error guard — VoidPayment::execute() filters this
            // earlier with a localized message; this branch only fires if a
            // future caller forgets. Throw loudly so the gap surfaces in CI.
            throw new BogApiException(
                __('BOG reversal requires a non-empty bog_order_id.')
            );
        }

        $url = $this->config->getCancelAuthorizationUrl($bogOrderId, $storeId);

        $body = [];
        if ($description !== '') {
            $body['description'] = $description;
        }

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('BOG Reversal request', [
                'url' => $url,
                'bog_order_id' => $bogOrderId,
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
                $this->logger->debug('BOG Reversal response', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
            }

            $response = null;
            if ($responseBody !== '') {
                try {
                    $decoded = $this->json->unserialize($responseBody);
                    if (is_array($decoded)) {
                        $response = $decoded;
                    }
                } catch (\Throwable $jsonError) {
                    // Malformed body — fall through to the non-2xx / null
                    // handling below so the caller still sees http_status.
                    $response = null;
                }
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                // Surface BOG's own message text to the log line so support
                // can grep, but DO NOT bubble raw text up to admin copy —
                // that's UserFacingErrorMapper's job in the controller.
                $bogMessage = '';
                if (is_array($response)) {
                    $bogMessage = (string) ($response['message']
                        ?? $response['error']
                        ?? $response['error_description']
                        ?? '');
                }
                throw new BogApiException(
                    __(
                        'BOG reversal API returned HTTP %1 for bog_order_id=%2: %3',
                        $statusCode,
                        $bogOrderId,
                        $bogMessage,
                    )
                );
            }

            if (!is_array($response)) {
                throw new BogApiException(
                    __('Invalid reversal response from BOG Payments API.')
                );
            }

            // Carry http_status through so the controller can branch on
            // 200-vs-202 and on response.key without re-fetching.
            $response['http_status'] = $statusCode;
            $response['bog_order_id'] = $bogOrderId;

            return $response;
        } catch (BogApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('BOG Reversal error: ' . $e->getMessage(), [
                'exception' => $e,
                'bog_order_id' => $bogOrderId,
            ]);
            throw new BogApiException(
                __('Unable to reverse payment via BOG Payments API.'),
                $e
            );
        }
    }
}
