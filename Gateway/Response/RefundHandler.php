<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Response;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

/**
 * Handles the response from the BOG Payments API refund endpoint.
 *
 * Session 8 Priority 1.1 — the handler is now the SOLE gatekeeper for
 * refund-failure routing (the validator was removed from
 * `ShuboBogRefundCommand` to allow this — see etc/di.xml comment).
 *
 * Error semantics:
 *   - On non-2xx HTTP, log the raw envelope (status, message,
 *     error_description, bog_order_id, order_increment_id) at ERROR via
 *     the BOG-dedicated logger BEFORE mapping. Ops + support need the raw
 *     values to correlate the admin-facing message back to BOG.
 *   - Throw the LocalizedException returned by
 *     {@see UserFacingErrorMapper}. Magento's creditmemo controller
 *     catches LocalizedException and surfaces a friendly admin toast; the
 *     upstream `Creditmemo::register` rollback semantics are unchanged.
 *
 * See `docs/online-refund-rca.md` for the full root-cause analysis.
 */
class RefundHandler implements HandlerInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
        private readonly UserFacingErrorMapper $userFacingErrorMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $handlingSubject
     * @param array<string, mixed> $response
     * @throws LocalizedException
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        $httpStatus = (int) ($response['http_status'] ?? 0);
        $bogOrderId = (string) ($response['bog_order_id'] ?? '');

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $rawMessage = $this->extractRawMessage($response);
            $errorCode = $this->extractErrorCode($response);

            $order = $paymentDO->getOrder();
            $orderIncrementId = $order !== null ? (string) $order->getOrderIncrementId() : null;

            // Raw triple BEFORE mapping — contract documented in
            // docs/error-code-map.md §3. Mapper itself does no logging.
            $this->logger->error('BOG HTTP error mapped to user copy', [
                'context'            => 'refund.handler',
                'http_status'        => $httpStatus,
                'message'            => $response['message'] ?? null,
                'error'              => $response['error'] ?? null,
                'error_description'  => $response['error_description'] ?? null,
                'bog_order_id'       => $bogOrderId,
                'order_increment_id' => $orderIncrementId,
            ]);

            throw $this->userFacingErrorMapper->toLocalizedException(
                $httpStatus,
                $rawMessage,
                $errorCode,
            );
        }

        // Success path — persist refund metadata and close transaction.
        if (isset($response['status'])) {
            $payment->setAdditionalInformation('bog_refund_status', (string) $response['status']);
        }

        $refundId = $response['refund_id'] ?? ($response['id'] ?? null);
        if ($refundId !== null && !is_array($refundId)) {
            $payment->setTransactionId((string) $refundId);
            $payment->setAdditionalInformation('bog_refund_id', (string) $refundId);
        }

        $payment->setIsTransactionClosed(true);
    }

    /**
     * BOG returns the human-readable reason in one of three keys depending on
     * which subsystem produced the error. Pick the most specific available.
     *
     * @param array<string, mixed> $response
     */
    private function extractRawMessage(array $response): string
    {
        foreach (['error_description', 'message', 'error'] as $key) {
            $value = $response[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Some BOG envelopes carry a string `error` code (e.g. "validation_failed",
     * "invalid_request"). Used for log correlation, NEVER surfaced to users.
     *
     * @param array<string, mixed> $response
     */
    private function extractErrorCode(array $response): ?string
    {
        $candidate = $response['error'] ?? null;
        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }
}
