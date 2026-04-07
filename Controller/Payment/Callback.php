<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Validator\CallbackValidator;

/**
 * Handles BOG payment callbacks (server-to-server notifications).
 *
 * New BOG API sends JSON callbacks with the structure:
 * {
 *   "event": "order_payment",
 *   "zoned_request_time": "...",
 *   "body": { ... payment details ... }
 * }
 *
 * The callback is a safety net. The Return controller should already have
 * processed the payment in most cases. If the order doesn't exist yet
 * (customer hasn't returned), we log and return 200 so the cron handles it.
 */
class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly RawFactory $rawFactory,
        private readonly CallbackValidator $callbackValidator,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender $orderSender,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(200);

        try {
            $rawBody = (string) $this->request->getContent();

            /** @var array<string, mixed>|null $callbackData */
            $callbackData = json_decode($rawBody, true);

            if (!is_array($callbackData)) {
                $this->logger->warning('BOG callback: invalid JSON body');
                $result->setContents('INVALID_BODY');
                return $result;
            }

            // New API structure: { event, zoned_request_time, body: {...} }
            // Fall back to flat structure for backward compatibility
            $event = (string) ($callbackData['event'] ?? '');
            $body = is_array($callbackData['body'] ?? null) ? $callbackData['body'] : $callbackData;

            $bogOrderId = (string) ($body['order_id'] ?? '');
            $externalOrderId = (string) ($body['external_order_id']
                ?? ($body['shop_order_id'] ?? ''));

            $this->logger->info('BOG callback received', [
                'event' => $event,
                'bog_order_id' => $bogOrderId,
                'external_order_id' => $externalOrderId,
            ]);

            if ($bogOrderId === '' && $externalOrderId === '') {
                $this->logger->warning('BOG callback: missing both order_id and external_order_id');
                $result->setContents('MISSING_ORDER_ID');
                return $result;
            }

            // Find the Magento order
            $order = $this->findOrder($externalOrderId, $bogOrderId);

            if ($order === null) {
                // Order doesn't exist yet -- customer hasn't returned from BOG
                // Return 200 so BOG doesn't retry; cron reconciler will handle it
                $this->logger->info('BOG callback: order not found yet, cron will handle', [
                    'external_order_id' => $externalOrderId,
                    'bog_order_id' => $bogOrderId,
                ]);
                $result->setContents('ORDER_PENDING');
                return $result;
            }

            // Already processed -- skip
            if ($order->getState() === Order::STATE_PROCESSING) {
                $this->logger->info('BOG callback: order already processed', [
                    'order_id' => $order->getIncrementId(),
                ]);
                $result->setContents('ALREADY_PROCESSED');
                return $result;
            }

            // Get Callback-Signature header for SHA256withRSA verification
            $signature = $this->request->getHeader('Callback-Signature');

            // Validate payment using signature (primary) or status API (fallback)
            $storeId = (int) $order->getStoreId();
            $validation = $this->callbackValidator->validate(
                bogOrderId: $bogOrderId !== '' ? $bogOrderId : $externalOrderId,
                callbackBody: $rawBody,
                signature: is_string($signature) ? $signature : null,
                storeId: $storeId,
            );

            if (!$validation['valid']) {
                $this->logger->warning('BOG callback: payment validation failed', [
                    'bog_order_id' => $bogOrderId,
                    'validation_status' => $validation['status'],
                ]);
                $result->setContents('VALIDATION_FAILED');
                return $result;
            }

            $this->processSuccessfulPayment($order, $bogOrderId, $validation);
            $result->setContents('OK');
        } catch (\Exception $e) {
            $this->logger->critical('BOG callback processing error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Return 200 even on error to prevent BOG from excessive retrying
            $result->setContents('ERROR');
        }

        return $result;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Find order by increment ID (external_order_id) or BOG order ID.
     */
    private function findOrder(string $externalOrderId, string $bogOrderId): ?Order
    {
        if ($externalOrderId !== '') {
            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToFilter('increment_id', $externalOrderId);
            $collection->setPageSize(1);

            /** @var Order|null $order */
            $order = $collection->getFirstItem();
            if ($order && $order->getId()) {
                return $order;
            }
        }

        if ($bogOrderId !== '') {
            $this->logger->info('BOG callback: searching order by bog_order_id in payment info', [
                'bog_order_id' => $bogOrderId,
            ]);
        }

        return null;
    }

    /**
     * Process a successfully validated payment: update payment info, move to processing.
     *
     * @param array{valid: bool, status: string, data: array<string, mixed>} $validation
     */
    private function processSuccessfulPayment(Order $order, string $bogOrderId, array $validation): void
    {
        $orderPayment = $order->getPayment();
        if ($orderPayment === null) {
            $this->logger->error('BOG callback: order has no payment', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        /** @var Payment $payment */
        $payment = $orderPayment;

        $payment->setAdditionalInformation('bog_status', $validation['status']);
        $payment->setAdditionalInformation('bog_order_id', $bogOrderId);

        // Store details from the validation data
        $this->storePaymentDetails($payment, $validation['data']);

        $payment->setTransactionId($bogOrderId);
        $storeId = (int) $order->getStoreId();

        if ($this->config->isPreauth($storeId)) {
            $payment->setAdditionalInformation('preauth_approved', true);
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(false);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Funds held by BOG (callback). Order ID: %1. Use "Capture Payment" to charge.', $bogOrderId)
            );
        } else {
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(true);
            $payment->registerCaptureNotification((float) $order->getGrandTotal());
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Payment confirmed by BOG (callback). Order ID: %1', $bogOrderId)
            );
        }

        $this->orderRepository->save($order);

        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->logger->warning('BOG callback: failed to send order email', [
                'order_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);
        }

        $this->logger->info('BOG callback: order processed successfully', [
            'order_id' => $order->getIncrementId(),
            'bog_order_id' => $bogOrderId,
        ]);
    }

    /**
     * Store BOG payment details from API response data.
     *
     * @param array<string, mixed> $data
     */
    private function storePaymentDetails(Payment $payment, array $data): void
    {
        $infoKeys = ['payment_hash', 'card_type', 'pan', 'payment_method', 'terminal_id'];
        foreach ($infoKeys as $key) {
            $value = $data[$key] ?? null;
            if ($value !== null && !is_array($value)) {
                $payment->setAdditionalInformation('bog_' . $key, (string) $value);
            }
        }
    }
}
