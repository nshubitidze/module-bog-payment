<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;
use Shubo\BogPayment\Service\MoneyCaster;
use Shubo\BogPayment\Service\PaymentLock;

/**
 * Cron job that reconciles stuck pending BOG payment orders.
 *
 * Finds orders older than 15 minutes that are still in pending_payment state,
 * checks their status via the BOG Status API, and updates accordingly:
 * - completed/captured: register capture, move to processing
 * - error/expired: cancel order
 * - in_progress/created: skip (check next run)
 */
class PendingOrderReconciler
{
    private const MAX_ORDERS_PER_RUN = 50;
    private const PENDING_THRESHOLD_MINUTES = 15;
    private const MAX_QUOTES_PER_RUN = 50;
    public const DEFAULT_QUOTE_TTL_HOURS = 24;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StatusClient $statusClient,
        private readonly Config $config,
        private readonly OrderSender $orderSender,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
        private readonly AppState $appState,
        private readonly PaymentLock $paymentLock,
        private readonly CartManagementInterface $cartManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CreditmemoFactory $creditmemoFactory,
        private readonly CreditmemoManagementInterface $creditmemoManagement,
        private readonly int $quoteTtlHours = self::DEFAULT_QUOTE_TTL_HOURS,
    ) {
    }

    public function execute(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $orders = $this->findPendingOrders();

        if ($orders !== []) {
            $this->logger->info('BOG reconciler: processing pending orders', [
                'count' => count($orders),
            ]);

            foreach ($orders as $order) {
                try {
                    $this->reconcileOrder($order);
                } catch (\Exception $e) {
                    $this->logger->error('BOG reconciler: failed to reconcile order', [
                        'order_id' => $order->getIncrementId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // BUG-BOG-11b: also scan for quote-only state (BOG redirect terminated
        // successfully in the background without the customer returning).
        $quotePayloads = $this->findPendingQuotes();
        if ($quotePayloads !== []) {
            $this->logger->info('BOG reconciler: processing pending quotes', [
                'count' => count($quotePayloads),
            ]);
            foreach ($quotePayloads as $payload) {
                try {
                    $this->reconcileQuote($payload);
                } catch (\Exception $e) {
                    $this->logger->error('BOG reconciler: failed to reconcile quote', [
                        'quote_id' => $payload['quote_id'],
                        'bog_order_id' => $payload['bog_order_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Find BOG orders that still need reconciliation.
     *
     * Two classes of orders are returned:
     *   1. Pending/payment_review — customer hasn't come back yet, we poll
     *      BOG for the terminal payment status.
     *   2. Processing/complete — already captured locally but BOG might have
     *      reported an out-of-band refund, reversal or chargeback since
     *      (BUG-BOG-12). We still need a bog_order_id to be worth polling,
     *      filtered by the `reconcileOrder()` state machine.
     *
     * @return Order[]
     */
    private function findPendingOrders(): array
    {
        $threshold = new \DateTimeImmutable(
            sprintf('-%d minutes', self::PENDING_THRESHOLD_MINUTES)
        );

        $sortOrder = $this->sortOrderBuilder
            ->setField('created_at')
            ->setAscendingDirection()
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(
                'state',
                [
                    Order::STATE_PENDING_PAYMENT,
                    Order::STATE_PAYMENT_REVIEW,
                    Order::STATE_PROCESSING,
                    Order::STATE_COMPLETE,
                ],
                'in'
            )
            ->addFilter('created_at', $threshold->format('Y-m-d H:i:s'), 'lt')
            ->setPageSize(self::MAX_ORDERS_PER_RUN)
            ->setSortOrders([$sortOrder])
            ->create();

        $orderList = $this->orderRepository->getList($searchCriteria);
        $candidates = [];

        /** @var Order $order */
        foreach ($orderList->getItems() as $order) {
            /** @var Payment|null $payment */
            $payment = $order->getPayment();
            if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
                continue;
            }
            // BUG-BOG-12: captured orders need a bog_order_id to be reconcilable
            // against a BOG-driven refund / reversal / chargeback.
            if (
                in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)
                && (string) $payment->getAdditionalInformation('bog_order_id') === ''
            ) {
                continue;
            }
            $candidates[] = $order;
        }

        return $candidates;
    }

    /**
     * Reconcile a single order by checking its BOG status.
     */
    private function reconcileOrder(Order $order): void
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $bogOrderId = (string) $payment->getAdditionalInformation('bog_order_id');

        if ($bogOrderId === '') {
            $this->logger->warning('BOG reconciler: no bog_order_id for order', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        $storeId = (int) $order->getStoreId();

        try {
            $response = $this->statusClient->checkStatus($bogOrderId, $storeId);
        } catch (BogApiException $e) {
            $this->logger->error('BOG reconciler: API error', [
                'order_id' => $order->getIncrementId(),
                'bog_order_id' => $bogOrderId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $orderStatusKey = strtolower(
            (string) ($response['order_status']['key'] ?? ($response['status'] ?? ''))
        );

        $this->logger->info('BOG reconciler: status for order', [
            'order_id' => $order->getIncrementId(),
            'bog_order_id' => $bogOrderId,
            'bog_status' => $orderStatusKey,
        ]);

        // BUG-BOG-6: serialize with Callback + ReturnAction. withLock returns
        // null if another worker beat us; we just skip — the next tick will
        // see the order in `processing` and short-circuit.
        $this->paymentLock->withLock($bogOrderId, function () use (
            $order,
            $payment,
            $response,
            $orderStatusKey
        ): void {
            // Reload state after acquiring the lock. For capture-path statuses
            // (completed/captured) already-processing orders are done — skip.
            // Post-capture statuses (refunded/reversed/chargeback) must still
            // run against processing/complete orders (BUG-BOG-12).
            $isCaptureStatus = in_array($orderStatusKey, ['completed', 'captured'], true);
            if ($isCaptureStatus && $order->getState() === Order::STATE_PROCESSING) {
                $this->logger->info(
                    'BOG reconciler: order already processing inside lock, skipping',
                    ['order_id' => $order->getIncrementId()]
                );
                return;
            }

            $connection = $this->resourceConnection->getConnection();
            $connection->beginTransaction();
            try {
                match ($orderStatusKey) {
                    'completed', 'captured' => $this->handleApproved($order, $payment, $response),
                    // BUG-BOG-12: post-capture BOG-driven events.
                    'refunded' => $this->handleRefunded($order, $response),
                    'reversed' => $this->handleReversed($order, $response),
                    'chargeback' => $this->handleChargeback($order, $response),
                    'error', 'rejected', 'declined' => $this->handleRejectedOrCancelled(
                        $order,
                        $orderStatusKey,
                        $response
                    ),
                    'expired' => $this->handleExpired($order),
                    'created', 'in_progress' => $this->logger->info(
                        'BOG reconciler: order still in progress, will retry',
                        ['order_id' => $order->getIncrementId(), 'bog_status' => $orderStatusKey]
                    ),
                    default => $this->logger->warning(
                        'BOG reconciler: unknown status',
                        ['order_id' => $order->getIncrementId(), 'bog_status' => $orderStatusKey]
                    ),
                };
                $connection->commit();
            } catch (\Exception $e) {
                $connection->rollBack();
                throw $e;
            }
        });
    }

    /**
     * Handle approved payment -- register capture, move to processing.
     *
     * @param array<string, mixed> $response BOG status response
     */
    private function handleApproved(Order $order, Payment $payment, array $response): void
    {
        if ($order->getState() === Order::STATE_PROCESSING) {
            return;
        }

        $this->storePaymentDetails($payment, $response);
        $storeId = (int) $order->getStoreId();
        $bogOrderId = (string) $payment->getAdditionalInformation('bog_order_id');

        $payment->setTransactionId($bogOrderId);

        if ($this->config->isPreauth($storeId)) {
            $payment->setAdditionalInformation('preauth_approved', true);
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(false);

            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __(
                    'Funds held by BOG (reconciled by cron). Order ID: %1. Use "Capture Payment" to charge.',
                    $bogOrderId
                )
            );

            $this->orderRepository->save($order);

            $this->logger->info('BOG reconciler: order preauth approved', [
                'order_id' => $order->getIncrementId(),
                'bog_order_id' => $bogOrderId,
            ]);
            return;
        }

        $payment->setIsTransactionPending(false);
        $payment->setIsTransactionClosed(true);
        // BUG-BOG-8: see MoneyCaster note in Callback.php.
        $payment->registerCaptureNotification(
            MoneyCaster::toMagentoFloat($order->getGrandTotal())
        );

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(
            (string) __(
                'Payment approved by BOG (reconciled by cron). Order ID: %1',
                $bogOrderId
            )
        );

        $this->orderRepository->save($order);

        // Send order confirmation email
        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->logger->warning('BOG reconciler: failed to send order email', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('BOG reconciler: order approved', [
            'order_id' => $order->getIncrementId(),
            'bog_order_id' => $bogOrderId,
        ]);
    }

    /**
     * BUG-BOG-12: `error` / `rejected` / `declined` are terminal-failure
     * statuses. State-machine matrix (mirrors TBC BUG-6 handleReversed):
     *
     *   closed / canceled           -> no-op (idempotent)
     *   pending_payment / new /
     *     payment_review / holded   -> $order->cancel() (no invoice yet)
     *   processing / complete with
     *     FULL reversal             -> STATE_CLOSED + comment
     *   processing / complete with
     *     PARTIAL reversal          -> comment only, state unchanged
     *   unknown state               -> WARN, no-op
     *
     * @param array<string, mixed> $response
     */
    private function handleRejectedOrCancelled(Order $order, string $status, array $response): void
    {
        $state = (string) $order->getState();

        // Idempotent terminal states: safe to re-deliver without side effects.
        if ($state === Order::STATE_CLOSED || $state === Order::STATE_CANCELED) {
            return;
        }

        // Pre-capture states: no invoice yet, Magento's cancel() handles items.
        if (
            in_array($state, [
                Order::STATE_PENDING_PAYMENT,
                Order::STATE_PAYMENT_REVIEW,
                Order::STATE_NEW,
                Order::STATE_HOLDED,
            ], true)
        ) {
            $order->cancel();
            $order->addCommentToStatusHistory(
                (string) __(
                    'Payment %1 at BOG (reconciled by cron). Order cancelled.',
                    $status
                )
            );
            $this->orderRepository->save($order);
            $this->logger->info('BOG reconciler: order cancelled due to failed payment', [
                'order_id' => $order->getIncrementId(),
                'status' => $status,
            ]);
            return;
        }

        // Post-capture: treat `rejected` on a processing order as a reversal
        // and route through handleReversed for consistent integer-tetri math.
        if ($state === Order::STATE_PROCESSING || $state === Order::STATE_COMPLETE) {
            $this->handleReversed($order, $response);
            return;
        }

        $this->logger->warning('BOG reconciler: unexpected rejection state', [
            'order_id' => $order->getIncrementId(),
            'state' => $state,
            'status' => $status,
        ]);
    }

    /**
     * BUG-BOG-12: BOG reports `refunded`. Create an offline creditmemo for
     * the refund amount (full or partial) and let Magento's
     * CreditmemoManagementInterface handle state transitions + inventory.
     *
     * Idempotent: skip if the order already has any creditmemo (BOG's
     * refund_id isn't reliably mirrored into Magento's creditmemo
     * transaction_id, so we keep it simple — at-most-one creditmemo per
     * order from this path).
     *
     * @param array<string, mixed> $response BOG receipt payload
     */
    private function handleRefunded(Order $order, array $response): void
    {
        if ($order->hasCreditmemos()) {
            $this->logger->info('BOG reconciler: order already has creditmemo, skipping refund', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        // Integer-tetri math — never compare floats on money (CLAUDE.md #6).
        $grandTotalMinor = (int) round(((float) $order->getGrandTotal()) * 100);
        $refundAmountMinor = $this->extractMinorAmount($response, ['refund_amount', 'amount'], $grandTotalMinor);
        $isFull = $refundAmountMinor >= $grandTotalMinor;

        try {
            $creditmemo = $this->creditmemoFactory->createByOrder($order);
            $creditmemo->setAutomaticallyCreated(true);
            $creditmemo->addComment(
                (string) __(
                    'Creditmemo auto-generated from BOG refund (reconciled by cron).%1',
                    $isFull ? '' : ' Partial refund.'
                )
            );
            $this->creditmemoManagement->refund($creditmemo, true);

            $order->addCommentToStatusHistory(
                (string) __(
                    'Payment refunded at BOG (reconciled by cron). Amount: %1 %2',
                    number_format($refundAmountMinor / 100, 2, '.', ''),
                    (string) $order->getOrderCurrencyCode()
                )
            );
            $this->orderRepository->save($order);

            $this->logger->info('BOG reconciler: created offline creditmemo for refund', [
                'order_id' => $order->getIncrementId(),
                'refund_minor' => $refundAmountMinor,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('BOG reconciler: failed to create creditmemo for refund', [
                'order_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * BUG-BOG-12: BOG reports `reversed`. State-machine mirrors
     * TBC Callback::handleReversed.
     *
     * @param array<string, mixed> $response
     */
    private function handleReversed(Order $order, array $response): void
    {
        $state = (string) $order->getState();

        if ($state === Order::STATE_CLOSED || $state === Order::STATE_CANCELED) {
            return;
        }

        $grandTotalMinor = (int) round(((float) $order->getGrandTotal()) * 100);
        $reverseAmountMinor = $this->extractMinorAmount(
            $response,
            ['reverse_amount', 'amount'],
            $grandTotalMinor
        );
        $isFull = $reverseAmountMinor >= $grandTotalMinor;

        if (
            in_array($state, [
                Order::STATE_PENDING_PAYMENT,
                Order::STATE_PAYMENT_REVIEW,
                Order::STATE_NEW,
                Order::STATE_HOLDED,
            ], true)
        ) {
            $order->cancel();
            $order->addCommentToStatusHistory(
                (string) __('Payment reversed at BOG before capture (reconciled by cron). Order cancelled.')
            );
            $this->orderRepository->save($order);
            return;
        }

        if ($state === Order::STATE_PROCESSING || $state === Order::STATE_COMPLETE) {
            if ($isFull) {
                $order->setState(Order::STATE_CLOSED)->setStatus(Order::STATE_CLOSED);
                $order->addCommentToStatusHistory(
                    (string) __('Payment fully reversed at BOG (reconciled by cron). Order closed.')
                );
                $this->orderRepository->save($order);
                return;
            }

            $order->addCommentToStatusHistory(
                (string) __(
                    'Partial reversal at BOG (reconciled by cron). Amount: %1 %2. State unchanged.',
                    number_format($reverseAmountMinor / 100, 2, '.', ''),
                    (string) $order->getOrderCurrencyCode()
                )
            );
            $this->orderRepository->save($order);
            return;
        }

        $this->logger->warning('BOG reconciler: unexpected reversal state', [
            'order_id' => $order->getIncrementId(),
            'state' => $state,
        ]);
    }

    /**
     * BUG-BOG-12: BOG reports `chargeback`. Treat as a full reversal and
     * stamp a chargeback-tagged comment so the admin sees the reason.
     *
     * @param array<string, mixed> $response
     */
    private function handleChargeback(Order $order, array $response): void
    {
        $state = (string) $order->getState();

        if ($state === Order::STATE_CLOSED || $state === Order::STATE_CANCELED) {
            return;
        }

        // Chargebacks only apply to captured orders; on any pre-capture
        // state we still cancel and log as chargeback for the trail.
        if (
            in_array($state, [
                Order::STATE_PENDING_PAYMENT,
                Order::STATE_PAYMENT_REVIEW,
                Order::STATE_NEW,
                Order::STATE_HOLDED,
            ], true)
        ) {
            $order->cancel();
            $order->addCommentToStatusHistory(
                (string) __('BOG chargeback (reconciled by cron) on uncaptured order. Order cancelled.')
            );
            $this->orderRepository->save($order);
            return;
        }

        // Post-capture chargeback == full reversal + explicit tag.
        $order->setState(Order::STATE_CLOSED)->setStatus(Order::STATE_CLOSED);
        $order->addCommentToStatusHistory(
            (string) __('BOG chargeback (reconciled by cron). Order closed.')
        );
        $this->orderRepository->save($order);

        $this->logger->warning('BOG reconciler: order closed due to chargeback', [
            'order_id' => $order->getIncrementId(),
        ]);
    }

    /**
     * Extract a monetary amount in minor units from a BOG response.
     * Walks a list of candidate keys; falls back to $defaultMinor when none
     * are present or positive. String/float input is rounded to integer
     * tetri to defeat float `==` / `>=` precision bugs (CLAUDE.md #6).
     *
     * @param array<string, mixed> $response
     * @param list<string> $candidateKeys
     */
    private function extractMinorAmount(array $response, array $candidateKeys, int $defaultMinor): int
    {
        foreach ($candidateKeys as $key) {
            if (!isset($response[$key])) {
                continue;
            }
            $raw = $response[$key];
            if (!is_numeric($raw)) {
                continue;
            }
            $minor = (int) round(((float) $raw) * 100);
            if ($minor > 0) {
                return $minor;
            }
        }
        return $defaultMinor;
    }

    /**
     * Handle expired payment session -- cancel order.
     */
    private function handleExpired(Order $order): void
    {
        $order->cancel();
        $order->addCommentToStatusHistory(
            (string) __('Payment session expired at BOG (reconciled by cron).')
        );

        $this->orderRepository->save($order);

        $this->logger->info('BOG reconciler: order expired and cancelled', [
            'order_id' => $order->getIncrementId(),
        ]);
    }

    /**
     * BUG-BOG-11b: find pending quotes for our payment method that carry a
     * bog_order_id — these are customers who returned from BOG while the
     * bank still said `in_progress` (no Magento order placed yet).
     *
     * @return array<int, array{quote_id: int, bog_order_id: string, age_hours: float}>
     */
    private function findPendingQuotes(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $quoteTable = $this->resourceConnection->getTableName('quote');
            $paymentTable = $this->resourceConnection->getTableName('quote_payment');

            $select = $connection->select()
                ->from(
                    ['q' => $quoteTable],
                    ['quote_id' => 'entity_id', 'updated_at' => 'updated_at']
                )
                ->join(
                    ['qp' => $paymentTable],
                    'qp.quote_id = q.entity_id',
                    ['additional_information']
                )
                ->where('q.is_active = ?', 1)
                ->where('qp.method = ?', ConfigProvider::CODE)
                ->where('qp.additional_information LIKE ?', '%"bog_order_id":"%')
                ->limit(self::MAX_QUOTES_PER_RUN);

            $rows = $connection->fetchAll($select);
        } catch (\Throwable $e) {
            $this->logger->error('BOG reconciler: quote scan failed', [
                'exception' => $e->getMessage(),
            ]);
            return [];
        }

        $now = new \DateTimeImmutable();
        $payloads = [];

        foreach ($rows as $row) {
            $additional = is_string($row['additional_information'])
                ? json_decode($row['additional_information'], true)
                : null;
            if (!is_array($additional) || empty($additional['bog_order_id'])) {
                continue;
            }

            $updatedAt = isset($row['updated_at']) && is_string($row['updated_at'])
                ? new \DateTimeImmutable($row['updated_at'])
                : $now;
            $ageSeconds = $now->getTimestamp() - $updatedAt->getTimestamp();
            $ageHours = $ageSeconds / 3600;

            $payloads[] = [
                'quote_id' => (int) $row['quote_id'],
                'bog_order_id' => (string) $additional['bog_order_id'],
                'age_hours' => (float) $ageHours,
            ];
        }

        return $payloads;
    }

    /**
     * Reconcile a single quote: check BOG status; on terminal success call
     * CartManagementInterface::placeOrder + run the approved flow. On
     * terminal failure or TTL expiry, deactivate the quote so the customer
     * starts fresh next time.
     *
     * @param array{quote_id: int, bog_order_id: string, age_hours: float} $payload
     */
    private function reconcileQuote(array $payload): void
    {
        $quoteId = $payload['quote_id'];
        $bogOrderId = $payload['bog_order_id'];

        // TTL cleanup first — don't burn BOG API quota on stale quotes.
        if ($payload['age_hours'] >= (float) $this->quoteTtlHours) {
            $this->deactivateQuote($quoteId, 'ttl_exceeded');
            return;
        }

        try {
            $response = $this->statusClient->checkStatus($bogOrderId, 0);
        } catch (BogApiException $e) {
            $this->logger->error('BOG reconciler: quote status API error', [
                'quote_id' => $quoteId,
                'bog_order_id' => $bogOrderId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $orderStatusKey = strtolower(
            (string) ($response['order_status']['key'] ?? ($response['status'] ?? ''))
        );

        $this->paymentLock->withLock($bogOrderId, function () use (
            $quoteId,
            $bogOrderId,
            $orderStatusKey,
            $response
        ): void {
            match ($orderStatusKey) {
                'completed', 'captured' => $this->materializeQuote($quoteId, $bogOrderId, $response),
                'expired' => $this->deactivateQuote($quoteId, 'bog_expired'),
                'error', 'rejected', 'declined' => $this->deactivateQuote($quoteId, 'bog_' . $orderStatusKey),
                'created', 'in_progress' => $this->logger->info(
                    'BOG reconciler: quote still in progress, will retry',
                    ['quote_id' => $quoteId, 'bog_order_id' => $bogOrderId]
                ),
                default => $this->logger->warning(
                    'BOG reconciler: unknown quote status',
                    ['quote_id' => $quoteId, 'bog_status' => $orderStatusKey]
                ),
            };
        });
    }

    /**
     * Materialize a quote → Magento order and run the approved capture flow.
     *
     * @param array<string, mixed> $response
     */
    private function materializeQuote(int $quoteId, string $bogOrderId, array $response): void
    {
        try {
            $quote = $this->cartRepository->get($quoteId);
            if ($quote instanceof \Magento\Quote\Model\Quote) {
                $quotePayment = $quote->getPayment();
                if ($quotePayment !== null) {
                    $quotePayment->setMethod(ConfigProvider::CODE);
                    $quotePayment->setAdditionalInformation('bog_order_id', $bogOrderId);
                    $quotePayment->setAdditionalInformation('bog_status', 'completed');
                }
            }
            $this->cartRepository->save($quote);

            $orderId = $this->cartManagement->placeOrder((int) $quote->getId());
            $order = $this->orderRepository->get((int) $orderId);
            if (!$order instanceof Order) {
                $this->logger->error('BOG reconciler: materialized entity is not a concrete Order', [
                    'quote_id' => $quoteId,
                    'bog_order_id' => $bogOrderId,
                ]);
                return;
            }
            /** @var Payment $payment */
            $payment = $order->getPayment();
            if ($payment === null) {
                $this->logger->error('BOG reconciler: materialized order has no payment', [
                    'order_id' => $order->getIncrementId(),
                ]);
                return;
            }

            // Store the bog_order_id before handleApproved reads it.
            $payment->setAdditionalInformation('bog_order_id', $bogOrderId);

            $connection = $this->resourceConnection->getConnection();
            $connection->beginTransaction();
            try {
                $this->handleApproved($order, $payment, $response);
                $connection->commit();
            } catch (\Exception $e) {
                $connection->rollBack();
                throw $e;
            }

            $this->logger->info('BOG reconciler: materialized order from pending quote', [
                'quote_id' => $quoteId,
                'order_id' => $order->getIncrementId(),
                'bog_order_id' => $bogOrderId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('BOG reconciler: failed to materialize order from quote', [
                'quote_id' => $quoteId,
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deactivate a stale/failed pending quote. Uses direct DB to avoid
     * touching full quote totals/items in a cron context.
     */
    private function deactivateQuote(int $quoteId, string $reason): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $quoteTable = $this->resourceConnection->getTableName('quote');
            $connection->update(
                $quoteTable,
                ['is_active' => 0],
                ['entity_id = ?' => $quoteId]
            );

            $this->logger->info('BOG reconciler: deactivated stale quote', [
                'quote_id' => $quoteId,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('BOG reconciler: failed to deactivate quote', [
                'quote_id' => $quoteId,
                'reason' => $reason,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store BOG payment details from status response.
     *
     * @param array<string, mixed> $response
     */
    private function storePaymentDetails(Payment $payment, array $response): void
    {
        $payment->setAdditionalInformation('bog_status', 'completed');

        $infoKeys = ['payment_hash', 'card_type', 'pan', 'payment_method', 'terminal_id'];
        foreach ($infoKeys as $key) {
            $value = $response[$key] ?? null;
            if ($value !== null && !is_array($value)) {
                $payment->setAdditionalInformation('bog_' . $key, (string) $value);
            }
        }
    }
}
