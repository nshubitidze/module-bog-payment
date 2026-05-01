<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron\Reconciler\StatusHandler;

use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Cron\Reconciler\MoneyHelpers;

/**
 * BUG-BOG-12: BOG reports `refunded`. Create an offline creditmemo for
 * the refund amount (full or partial) and let Magento's
 * CreditmemoManagementInterface handle state transitions + inventory.
 *
 * Extracted verbatim from {@see \Shubo\BogPayment\Cron\PendingOrderReconciler::handleRefunded}
 * during the 2026-05-01 god-class split.
 */
class RefundedHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CreditmemoFactory $creditmemoFactory,
        private readonly CreditmemoManagementInterface $creditmemoManagement,
        private readonly LoggerInterface $logger,
        private readonly MoneyHelpers $money,
    ) {
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
    public function handle(Order $order, array $response): void
    {
        if ($order->hasCreditmemos()) {
            $this->logger->info('BOG reconciler: order already has creditmemo, skipping refund', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        // Integer-tetri math — never compare floats on money (CLAUDE.md #6).
        $grandTotalMinor = (int) round(((float) $order->getGrandTotal()) * 100);
        $refundAmountMinor = $this->money->extractMinorAmount($response, ['refund_amount', 'amount'], $grandTotalMinor);
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
}
