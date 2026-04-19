<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Sets BOG payment orders to pending_payment state after placement.
 *
 * Since no payment processing happens during order creation (the actual
 * payment is handled externally by BOG), we set the order to pending_payment.
 * The callback, cron reconciler, or return controller will later move it
 * to processing after the bank confirms the payment.
 *
 * Non-critical: if state mutation fails, order placement must still proceed.
 * The payment flow will work without the pending_payment state.
 */
class SetPendingPaymentState implements ObserverInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getData('order');

        if ($order === null) {
            return;
        }

        $payment = $order->getPayment();

        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
            return;
        }

        try {
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->setStatus('pending_payment');
        } catch (\Throwable $e) {
            // Non-critical: order placement must succeed even if state mutation fails.
            // The payment callback/reconciler will correct the state later.
            $this->logger->error('BOG: Failed to set pending_payment state on order', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
