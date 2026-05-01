<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron\Reconciler\StatusHandler;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Cron\Reconciler\MoneyHelpers;

/**
 * BUG-BOG-12: BOG reports `reversed`. State-machine mirrors
 * TBC Callback::handleReversed.
 *
 * Extracted verbatim from {@see \Shubo\BogPayment\Cron\PendingOrderReconciler::handleReversed}
 * during the 2026-05-01 god-class split. Composed by RejectedOrCancelledHandler
 * for the post-capture branch.
 */
class ReversedHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly MoneyHelpers $money,
    ) {
    }

    /**
     * BUG-BOG-12: BOG reports `reversed`. State-machine mirrors
     * TBC Callback::handleReversed.
     *
     * @param array<string, mixed> $response
     */
    public function handle(Order $order, array $response): void
    {
        $state = (string) $order->getState();

        if ($state === Order::STATE_CLOSED || $state === Order::STATE_CANCELED) {
            return;
        }

        $grandTotalMinor = (int) round(((float) $order->getGrandTotal()) * 100);
        $reverseAmountMinor = $this->money->extractMinorAmount(
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
}
