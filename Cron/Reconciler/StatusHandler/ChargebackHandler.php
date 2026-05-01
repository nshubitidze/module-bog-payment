<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron\Reconciler\StatusHandler;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * BUG-BOG-12: BOG reports `chargeback`. Treat as a full reversal and
 * stamp a chargeback-tagged comment so the admin sees the reason.
 *
 * Extracted verbatim from {@see \Shubo\BogPayment\Cron\PendingOrderReconciler::handleChargeback}
 * during the 2026-05-01 god-class split.
 */
class ChargebackHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * BUG-BOG-12: BOG reports `chargeback`. Treat as a full reversal and
     * stamp a chargeback-tagged comment so the admin sees the reason.
     *
     * @param array<string, mixed> $response
     */
    public function handle(Order $order, array $response): void
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
}
