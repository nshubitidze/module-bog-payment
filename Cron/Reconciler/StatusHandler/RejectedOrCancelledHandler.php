<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron\Reconciler\StatusHandler;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

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
 * Extracted verbatim from {@see \Shubo\BogPayment\Cron\PendingOrderReconciler::handleRejectedOrCancelled}
 * during the 2026-05-01 god-class split. Holds a composed ReversedHandler
 * for the post-capture branch — composition over recursion.
 */
class RejectedOrCancelledHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly ReversedHandler $reversed,
    ) {
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
    public function handle(Order $order, string $status, array $response): void
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
            $this->reversed->handle($order, $response);
            return;
        }

        $this->logger->warning('BOG reconciler: unexpected rejection state', [
            'order_id' => $order->getIncrementId(),
            'state' => $state,
            'status' => $status,
        ]);
    }
}
