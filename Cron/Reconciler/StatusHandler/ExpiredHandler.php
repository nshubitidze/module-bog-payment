<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron\Reconciler\StatusHandler;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Handle expired payment session -- cancel order.
 *
 * Extracted verbatim from {@see \Shubo\BogPayment\Cron\PendingOrderReconciler::handleExpired}
 * during the 2026-05-01 god-class split. Behavior-preserving: byte-identical
 * body, same comments, same logger messages.
 */
class ExpiredHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle expired payment session -- cancel order.
     */
    public function handle(Order $order): void
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
}
