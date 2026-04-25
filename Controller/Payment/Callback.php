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
use Shubo\BogPayment\Service\MoneyCaster;
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

        try {
            $rawBody = (string) $this->request->getContent();

            /** @var array<string, mixed>|null $callbackData */
            $callbackData = json_decode($rawBody, true);

            if (!is_array($callbackData)) {
                // BUG-BOG-10: malformed JSON cannot be retried — 400.
                $this->logger->warning('BOG callback: invalid JSON body');
                $result->setContents('INVALID_BODY');
                $result->setHttpResponseCode(400);
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
                // BUG-BOG-10: a payload with no identifiers can never be
                // resolved — 400 tells BOG to stop retrying.
                $this->logger->warning('BOG callback: missing both order_id and external_order_id');
                $result->setContents('MISSING_ORDER_ID');
                $result->setHttpResponseCode(400);
                return $result;
            }

            $lockKey = $bogOrderId !== '' ? $bogOrderId : $externalOrderId;

            // Lock for the duration of order lookup + capture. The withLock
            // returns null on contention, which we surface as LOCK_CONTENDED
            // with HTTP 200 — the work is idempotent and cron/another retry
            // will finalise. Serializes against ReturnAction::handleSuccess
            // and Cron/PendingOrderReconciler (BUG-BOG-6).
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
                $this->logger->info('BOG callback: lock contended, deferring', [
                    'bog_order_id' => $bogOrderId,
                    'external_order_id' => $externalOrderId,
                ]);
                $result->setContents('LOCK_CONTENDED');
                $result->setHttpResponseCode(200);
                return $result;
            }

            // BUG-BOG-10: map response code per business meaning.
            //   VALIDATION_FAILED      -> 400  (bogus/tampered signature or
            //                                   cross-wired order — no point
            //                                   retrying)
            //   ORDER_PENDING          -> 200  (idempotent, cron finalises)
            //   ALREADY_PROCESSED / OK -> 200  (happy path / duplicate)
            //   ERROR                  -> 500  (internal failure mid-process;
            //                                   BOG exponential backoff is safe)
            $result->setContents($contents);
            $result->setHttpResponseCode($this->httpStatusFor($contents));
        } catch (\Exception $e) {
            $this->logger->critical('BOG callback processing error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // BUG-BOG-10: unexpected exceptions are transient — 500 lets
            // BOG's exponential backoff recover. The capture path is
            // idempotent (BUG-BOG-6 PaymentLock + state re-check).
            $result->setContents('ERROR');
            $result->setHttpResponseCode(500);
        }

        return $result;
    }

    /**
     * BUG-BOG-10: map controller response-body sentinels to HTTP status codes.
     *
     * Session 8 P2.1 added AMOUNT_MISMATCH (cart edited mid-flow). Like
     * VALIDATION_FAILED it's a do-not-retry situation — the discrepancy is
     * either a tampered request or a stale callback for a re-priced order;
     * either way exponential backoff at BOG won't fix it.
     */
    private function httpStatusFor(string $contents): int
    {
        return match ($contents) {
            'VALIDATION_FAILED', 'AMOUNT_MISMATCH' => 400,
            'ERROR' => 500,
            default => 200,
        };
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

        // Session 8 P2.1 edge-case #6: defend against order-amount-changes-mid-flow.
        // BOG occasionally emits the captured amount in
        // body.purchase_units.total_amount; if it disagrees with the local
        // Magento order's grand_total by more than 1 tetri, the customer (or
        // an attacker) may have edited the cart in a different tab between
        // initiation and capture. Refuse to process; the reconciler will hold
        // the order and an admin can reconcile manually.
        $mismatch = $this->amountMismatch($order, $validation['data'] ?? []);
        if ($mismatch !== null) {
            $this->logger->critical(
                'BOG callback: amount mismatch — possible cart-edit-mid-flow',
                [
                    'order_id'           => $order->getIncrementId(),
                    'bog_order_id'       => $bogOrderId,
                    'bog_amount_minor'   => $mismatch['bog_minor'],
                    'order_amount_minor' => $mismatch['order_minor'],
                    'difference_minor'   => $mismatch['diff_minor'],
                ]
            );
            return 'AMOUNT_MISMATCH';
        }

        $this->processSuccessfulPayment($order, $bogOrderId, $validation);
        return 'OK';
    }

    /**
     * Compare the BOG-reported total against the Magento order's grand_total.
     * Returns null if amounts agree (within 1 tetri tolerance for rounding)
     * OR if BOG did not report an amount (defensive — null fields are common
     * across status types). Returns the diff payload when a real mismatch is
     * detected, for the caller to log + reject.
     *
     * Integer-tetri math throughout — never compare floats on money
     * (CLAUDE.md proactive standards #6).
     *
     * Tree-depth contract (Session 8 Pass-1 reviewer M-1 fix):
     *   The signature path of `CallbackValidator::validate()` returns the
     *   FULL callback envelope as `validation['data']` — i.e. the
     *   {event, zoned_request_time, body: {...}} shape. The status-API
     *   fallback path returns the receipt response, which may or may not
     *   carry a top-level `body` key depending on the BOG endpoint version.
     *   We therefore unwrap `body` when present (matches the convention of
     *   `CallbackValidator::extractOrderStatusKey()` at line 113).
     *
     * @param array<string, mixed> $data validation['data'] from CallbackValidator.
     * @return array{bog_minor: int, order_minor: int, diff_minor: int}|null
     */
    private function amountMismatch(Order $order, array $data): ?array
    {
        // Unwrap the new BOG envelope shape ({event, body: {...}}) to match
        // the legacy flat shape — same defensive pattern the validator uses.
        $container = is_array($data['body'] ?? null) ? $data['body'] : $data;
        $bogAmount = $container['purchase_units']['total_amount']
            ?? $container['amount']
            ?? null;
        if ($bogAmount === null || !is_numeric($bogAmount)) {
            return null;
        }
        $bogMinor = (int) round(((float) $bogAmount) * 100);
        $orderMinor = (int) round(((float) $order->getGrandTotal()) * 100);
        if (abs($bogMinor - $orderMinor) <= 1) {
            return null;
        }
        return [
            'bog_minor'   => $bogMinor,
            'order_minor' => $orderMinor,
            'diff_minor'  => $bogMinor - $orderMinor,
        ];
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
            // BUG-BOG-8: MoneyCaster encapsulates the required float cast at
            // the Magento Payment API boundary. grand_total arrives as a
            // bcmath-safe numeric string (DECIMAL column); the cast is safe
            // because MoneyCaster refuses empty / non-numeric / negative
            // inputs and clamps to 2 decimal places.
            $payment->registerCaptureNotification(
                MoneyCaster::toMagentoFloat($order->getGrandTotal())
            );
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
