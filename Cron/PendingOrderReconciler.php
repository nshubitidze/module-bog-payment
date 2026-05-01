<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Cron\Reconciler\MoneyHelpers;
use Shubo\BogPayment\Cron\Reconciler\QuoteReconciler;
use Shubo\BogPayment\Cron\Reconciler\StatusHandler\ApprovedHandler;
use Shubo\BogPayment\Cron\Reconciler\StatusHandler\ChargebackHandler;
use Shubo\BogPayment\Cron\Reconciler\StatusHandler\ExpiredHandler;
use Shubo\BogPayment\Cron\Reconciler\StatusHandler\RefundedHandler;
use Shubo\BogPayment\Cron\Reconciler\StatusHandler\RejectedOrCancelledHandler;
use Shubo\BogPayment\Cron\Reconciler\StatusHandler\ReversedHandler;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;
use Shubo\BogPayment\Service\PaymentLock;

/**
 * Cron job that reconciles stuck pending BOG payment orders.
 *
 * Thin orchestrator that:
 *   1. Finds stuck Magento orders (findPendingOrders) and dispatches each
 *      to the right per-status handler under a PaymentLock + DB transaction
 *      (reconcileOrder).
 *   2. Delegates the BUG-BOG-11b quote-recovery scan to QuoteReconciler.
 *
 * Per-status decisions live in the handlers under
 * {@see \Shubo\BogPayment\Cron\Reconciler\StatusHandler}. See the
 * 2026-05-01 design doc for the rationale behind the split:
 * docs/design-reconciler-split-2026-05-01.md.
 */
class PendingOrderReconciler
{
    private const MAX_ORDERS_PER_RUN = 50;
    private const PENDING_THRESHOLD_MINUTES = 15;
    public const DEFAULT_QUOTE_TTL_HOURS = 24;

    private readonly ApprovedHandler $approved;
    private readonly RejectedOrCancelledHandler $rejectedOrCancelled;
    private readonly RefundedHandler $refunded;
    private readonly ReversedHandler $reversed;
    private readonly ChargebackHandler $chargeback;
    private readonly ExpiredHandler $expired;
    private readonly QuoteReconciler $quoteReconciler;
    private readonly ResourceConnection $resourceConnection;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StatusClient $statusClient,
        Config $config,
        OrderSender $orderSender,
        private readonly LoggerInterface $logger,
        ResourceConnection $resourceConnection,
        private readonly AppState $appState,
        private readonly PaymentLock $paymentLock,
        CartManagementInterface $cartManagement,
        CartRepositoryInterface $cartRepository,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoManagementInterface $creditmemoManagement,
        int $quoteTtlHours = self::DEFAULT_QUOTE_TTL_HOURS,
    ) {
        $this->resourceConnection = $resourceConnection;

        $money = new MoneyHelpers();
        $reversed = new ReversedHandler($orderRepository, $logger, $money);
        $this->approved = new ApprovedHandler($orderRepository, $config, $orderSender, $logger);
        $this->rejectedOrCancelled = new RejectedOrCancelledHandler($orderRepository, $logger, $reversed);
        $this->refunded = new RefundedHandler(
            $orderRepository,
            $creditmemoFactory,
            $creditmemoManagement,
            $logger,
            $money,
        );
        $this->reversed = $reversed;
        $this->chargeback = new ChargebackHandler($orderRepository, $logger);
        $this->expired = new ExpiredHandler($orderRepository, $logger);
        $this->quoteReconciler = new QuoteReconciler(
            $resourceConnection,
            $statusClient,
            $cartManagement,
            $cartRepository,
            $paymentLock,
            $this->approved,
            $orderRepository,
            $logger,
            $quoteTtlHours,
        );
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
        $quotePayloads = $this->quoteReconciler->findPendingQuotes();
        if ($quotePayloads !== []) {
            $this->logger->info('BOG reconciler: processing pending quotes', [
                'count' => count($quotePayloads),
            ]);
            foreach ($quotePayloads as $payload) {
                try {
                    $this->quoteReconciler->reconcileQuote($payload);
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
                    'completed', 'captured' => $this->approved->handle($order, $payment, $response),
                    // BUG-BOG-12: post-capture BOG-driven events.
                    'refunded' => $this->refunded->handle($order, $response),
                    'reversed' => $this->reversed->handle($order, $response),
                    'chargeback' => $this->chargeback->handle($order, $response),
                    'error', 'rejected', 'declined' => $this->rejectedOrCancelled->handle(
                        $order,
                        $orderStatusKey,
                        $response
                    ),
                    'expired' => $this->expired->handle($order),
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
}
