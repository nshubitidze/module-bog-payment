<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

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

        if ($orders === []) {
            return;
        }

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

    /**
     * Find pending BOG payment orders older than the threshold.
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
            ->addFilter('state', [Order::STATE_PENDING_PAYMENT, Order::STATE_PAYMENT_REVIEW], 'in')
            ->addFilter('created_at', $threshold->format('Y-m-d H:i:s'), 'lt')
            ->setPageSize(self::MAX_ORDERS_PER_RUN)
            ->setSortOrders([$sortOrder])
            ->create();

        $orderList = $this->orderRepository->getList($searchCriteria);
        $pendingOrders = [];

        /** @var Order $order */
        foreach ($orderList->getItems() as $order) {
            $payment = $order->getPayment();
            if ($payment !== null && $payment->getMethod() === ConfigProvider::CODE) {
                $pendingOrders[] = $order;
            }
        }

        return $pendingOrders;
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

        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            match ($orderStatusKey) {
                'completed', 'captured' => $this->handleApproved($order, $payment, $response),
                'error', 'rejected', 'declined' => $this->handleFailed($order, $orderStatusKey),
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
        $payment->registerCaptureNotification((float) $order->getGrandTotal());

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
     * Handle failed payment -- cancel order.
     */
    private function handleFailed(Order $order, string $status): void
    {
        $order->cancel();
        $order->addCommentToStatusHistory(
            (string) __(
                'Payment failed at BOG (reconciled by cron). Status: %1',
                $status
            )
        );

        $this->orderRepository->save($order);

        $this->logger->info('BOG reconciler: order cancelled due to failed payment', [
            'order_id' => $order->getIncrementId(),
            'status' => $status,
        ]);
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
