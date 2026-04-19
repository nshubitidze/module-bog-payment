<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Validator\CallbackValidator;
use Shubo\BogPayment\Service\PaymentLock;

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
 * (customer hasn't returned), we may need to materialize it from the quote
 * (BUG-BOG-11b). Otherwise we log and return 200 so cron handles it.
 *
 * Concurrency: all capture processing for a given bog_order_id runs inside a
 * PaymentLock (BUG-BOG-6) to prevent double-invoice / double-commission
 * when Callback + ReturnAction + Cron race.
 *
 * Lookup: supports both external_order_id (increment_id) and bog_order_id
 * alone. The bog_order_id path queries sales_order_payment.additional_information
 * via a JSON LIKE — Magento 2.4.8 serializes that column as JSON via
 * Magento\Framework\Serialize\Serializer\Json (BUG-BOG-7).
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
        private readonly ResourceConnection $resourceConnection,
        private readonly CartManagementInterface $cartManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly PaymentLock $paymentLock,
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
            // Fall back to flat structure for backward compatibility.
            $event = (string) ($callbackData['event'] ?? '');
            $body = is_array($callbackData['body'] ?? null) ? $callbackData['body'] : $callbackData;

            $bogOrderId = (string) ($body['order_id'] ?? '');
            $externalOrderId = (string) ($body['external_order_id']
                ?? ($body['shop_order_id'] ?? ''));
            $bogStatusKey = strtolower(
                (string) ($body['order_status']['key'] ?? ($body['status'] ?? ''))
            );

            $this->logger->info('BOG callback received', [
                'event' => $event,
                'bog_order_id' => $bogOrderId,
                'external_order_id' => $externalOrderId,
                'bog_status' => $bogStatusKey,
            ]);

            if ($bogOrderId === '' && $externalOrderId === '') {
                $this->logger->warning('BOG callback: missing both order_id and external_order_id');
                $result->setContents('MISSING_ORDER_ID');
                return $result;
            }

            $lockKey = $bogOrderId !== '' ? $bogOrderId : $externalOrderId;

            // Lock for the duration of order lookup + capture. The withLock
            // returns null on contention, which we surface as ORDER_PENDING so
            // BOG keeps the callback retry budget. Serializes against
            // ReturnAction::handleSuccess and Cron/PendingOrderReconciler.
            $contents = $this->paymentLock->withLock(
                $lockKey,
                fn(): string => $this->handleLocked(
                    rawBody: $rawBody,
                    bogOrderId: $bogOrderId,
                    externalOrderId: $externalOrderId,
                    bogStatusKey: $bogStatusKey,
                )
            );

            if ($contents === null) {
                // Another handler is currently processing this bog_order_id.
                // Return 200 to avoid callback storms; caller will retry or
                // cron will finalize.
                $this->logger->info('BOG callback: lock contended, deferring', [
                    'bog_order_id' => $bogOrderId,
                    'external_order_id' => $externalOrderId,
                ]);
                $result->setContents('LOCK_CONTENDED');
                return $result;
            }

            $result->setContents($contents);
        } catch (\Exception $e) {
            $this->logger->critical('BOG callback processing error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Return 200 even on error to prevent BOG from excessive retrying.
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
     * Body of the callback handler that runs while holding the PaymentLock.
     * Returns the response body string to write back (e.g. OK, ORDER_PENDING).
     */
    private function handleLocked(
        string $rawBody,
        string $bogOrderId,
        string $externalOrderId,
        string $bogStatusKey,
    ): string {
        // Find the Magento order (if any).
        $order = $this->findOrder($externalOrderId, $bogOrderId);

        // BUG-BOG-11b: quote-only state + terminal success → materialize.
        if ($order === null) {
            $terminalSuccess = in_array($bogStatusKey, ['completed', 'captured'], true);

            if (!$terminalSuccess) {
                $this->logger->info('BOG callback: order not found and status non-terminal, cron will handle', [
                    'external_order_id' => $externalOrderId,
                    'bog_order_id' => $bogOrderId,
                    'bog_status' => $bogStatusKey,
                ]);
                return 'ORDER_PENDING';
            }

            $quoteId = $this->findQuoteIdByBogOrderId($bogOrderId);
            if ($quoteId === null) {
                $this->logger->info(
                    'BOG callback: no Magento order or quote found yet, cron will handle',
                    [
                        'external_order_id' => $externalOrderId,
                        'bog_order_id' => $bogOrderId,
                    ]
                );
                return 'ORDER_PENDING';
            }

            $order = $this->materializeOrderFromQuote($quoteId, $bogOrderId);
            if ($order === null) {
                return 'ERROR';
            }
        }

        // Already processed — skip. Re-read state inside the lock to defeat
        // the race between handlers.
        if ($order->getState() === Order::STATE_PROCESSING) {
            $this->logger->info('BOG callback: order already processed', [
                'order_id' => $order->getIncrementId(),
            ]);
            return 'ALREADY_PROCESSED';
        }

        // Get Callback-Signature header for SHA256withRSA verification.
        $signature = $this->request->getHeader('Callback-Signature');

        // Validate payment using signature (primary) or status API (fallback).
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
            return 'VALIDATION_FAILED';
        }

        $this->processSuccessfulPayment($order, $bogOrderId, $validation);
        return 'OK';
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
            $orderId = $this->findOrderIdByBogOrderId($bogOrderId);
            if ($orderId !== null) {
                try {
                    $resolved = $this->orderRepository->get($orderId);
                    if ($resolved instanceof Order) {
                        return $resolved;
                    }
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    $this->logger->warning('BOG callback: order_id in payment.additional_information did not resolve', [
                        'bog_order_id' => $bogOrderId,
                        'order_id' => $orderId,
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * BUG-BOG-7: resolve sales_order.entity_id by the bog_order_id embedded in
     * sales_order_payment.additional_information.
     *
     * Magento 2.4.8 stores this column as JSON via
     * Magento\Framework\Serialize\Serializer\Json, so the LIKE pattern targets
     * the JSON key/value representation. Example stored blob:
     *   {"method_title":"BOG","bog_order_id":"BOG-XYZ","bog_status":"created"}
     */
    private function findOrderIdByBogOrderId(string $bogOrderId): ?int
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('sales_order_payment');

            $select = $connection->select()
                ->from($table, ['parent_id'])
                ->where('additional_information LIKE :needle')
                ->limit(1);

            $needle = '%"bog_order_id":"' . $bogOrderId . '"%';
            $result = $connection->fetchOne($select, ['needle' => $needle]);

            if ($result === false || $result === null || $result === '') {
                return null;
            }

            return (int) $result;
        } catch (\Throwable $e) {
            $this->logger->error('BOG callback: failed to resolve order by bog_order_id', [
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * BUG-BOG-11b: resolve quote_id by the bog_order_id embedded in
     * quote_payment.additional_information, restricted to active quotes with
     * our payment method. Returns null on miss.
     */
    private function findQuoteIdByBogOrderId(string $bogOrderId): ?int
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $quoteTable = $this->resourceConnection->getTableName('quote');
            $paymentTable = $this->resourceConnection->getTableName('quote_payment');

            $select = $connection->select()
                ->from(['q' => $quoteTable], ['entity_id'])
                ->join(
                    ['qp' => $paymentTable],
                    'qp.quote_id = q.entity_id',
                    []
                )
                ->where('q.is_active = ?', 1)
                ->where('qp.method = ?', Config::METHOD_CODE)
                ->where('qp.additional_information LIKE :needle')
                ->limit(1);

            $needle = '%"bog_order_id":"' . $bogOrderId . '"%';
            $result = $connection->fetchOne($select, ['needle' => $needle]);

            if ($result === false || $result === null || $result === '') {
                return null;
            }

            return (int) $result;
        } catch (\Throwable $e) {
            $this->logger->error('BOG callback: failed to resolve quote by bog_order_id', [
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * BUG-BOG-11b: materialize a Magento order from a quote that was left
     * pending by ReturnAction::handlePending. Runs INSIDE the PaymentLock
     * so a concurrent customer-return cannot race.
     */
    private function materializeOrderFromQuote(int $quoteId, string $bogOrderId): ?Order
    {
        try {
            $quote = $this->cartRepository->get($quoteId);

            // CartInterface does not declare getPayment(), but the concrete
            // Quote model does. Narrow only when the concrete model is in
            // play so PHPStan stays happy and alternative implementations
            // don't blow up at runtime.
            if ($quote instanceof \Magento\Quote\Model\Quote) {
                $quotePayment = $quote->getPayment();
                if ($quotePayment !== null) {
                    $quotePayment->setMethod(Config::METHOD_CODE);
                    $quotePayment->setAdditionalInformation('bog_order_id', $bogOrderId);
                    $quotePayment->setAdditionalInformation('bog_status', 'completed');
                }
            }
            $this->cartRepository->save($quote);

            $orderId = $this->cartManagement->placeOrder((int) $quote->getId());
            $order = $this->orderRepository->get((int) $orderId);
            if (!$order instanceof Order) {
                return null;
            }

            $this->logger->info('BOG callback: materialized order from pending quote', [
                'quote_id' => $quoteId,
                'order_id' => $order->getIncrementId(),
                'bog_order_id' => $bogOrderId,
            ]);

            return $order;
        } catch (\Throwable $e) {
            $this->logger->error('BOG callback: failed to materialize order from quote', [
                'quote_id' => $quoteId,
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
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

        // Store details from the validation data.
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
            // Magento API requires float; bcmath values live in additional_information.
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
