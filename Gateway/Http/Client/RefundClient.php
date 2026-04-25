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

/**
 * Client for the new BOG Payments API refund endpoint.
 *
 *   POST  {api_url}/payment/refund/{bog_order_id}
 *   body  {"amount": "10.00"}
 *
 * Session 8 Priority 1.1 — the prior implementation POSTed to
 * `${api_url}/checkout/refund` with body `{order_id, amount}`. That was the
 * legacy iPay shape and 404'd against the new Payments API.
 *
 * The body produced by `RefundRequestBuilder` carries `order_id` so the
 * client can construct the URL; we strip it before sending so the wire
 * payload matches BOG's documented schema.
 *
 * On non-2xx, the response is returned to the handler with `http_status`
 * embedded — the handler routes through `UserFacingErrorMapper`. NO
 * exception thrown here for non-2xx; only for hard transport failures
 * (curl exception, malformed JSON) where the pipeline cannot continue.
 */
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

        $bogOrderId = (string) ($body['order_id'] ?? '');
        if ($bogOrderId === '') {
            // RefundRequestBuilder guards this earlier; a missing id at this
            // point is a programming error worth surfacing loudly.
            throw new LocalizedException(
                __('BOG refund request is missing the bog_order_id — cannot construct refund URL.')
            );
        }

        // Strip from the wire body — the new API takes `order_id` in the URL only.
        unset($body['order_id']);

        $url = $this->config->getRefundUrl($bogOrderId);

        $this->logger->debug('BOG refund request', [
            'url' => $url,
            'bog_order_id' => $bogOrderId,
            'body' => $body,
        ]);

        $accessToken = $this->tokenProvider->getAccessToken();
        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Authorization', 'Bearer ' . $accessToken);
        $curl->setOptions([CURLOPT_TIMEOUT => 60]);

        $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

        try {
            $curl->post($url, $jsonBody);
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
            // Non-JSON or empty response — synthesize a minimal envelope so
            // the handler can route it through UserFacingErrorMapper instead
            // of crashing.
            $response = ['raw_body' => (string) $responseBody];
        }

        $response['http_status'] = $statusCode;
        $response['bog_order_id'] = $bogOrderId;

        return $response;
    }
}
