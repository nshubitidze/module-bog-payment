<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Sets BOG payment orders to pending_payment state after placement.
 *
 * Since no payment processing happens during order creation (the actual
 * payment is handled externally by BOG), we set the order to pending_payment.
 * The callback, cron reconciler, or return controller will later move it
 * to processing after the bank confirms the payment.
 */
class SetPendingPaymentState implements ObserverInterface
{
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

        $order->setState(Order::STATE_PENDING_PAYMENT);
        $order->setStatus('pending_payment');
    }
}
