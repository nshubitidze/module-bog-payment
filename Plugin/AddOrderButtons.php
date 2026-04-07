<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Plugin;

use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Plugin to add BOG payment action buttons to the admin order view toolbar.
 *
 * Adds: Check BOG Status, Capture Payment, Void Payment.
 */
class AddOrderButtons
{
    /**
     * Add BOG payment buttons to order view if conditions are met.
     */
    public function beforeSetLayout(View $subject): void
    {
        $order = $subject->getOrder();

        if ($order === null) {
            return;
        }

        /** @var Payment|null $payment */
        $payment = $order->getPayment();

        if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
            return;
        }

        // "Check BOG Status" button -- available for any BOG order with a bog_order_id
        $bogOrderId = $payment->getAdditionalInformation('bog_order_id');
        if (!empty($bogOrderId)) {
            $checkUrl = $subject->getUrl(
                'shubo_bog/order/checkStatus',
                ['order_id' => $order->getEntityId()]
            );

            $subject->addButton(
                'bog_check_status',
                [
                    'label' => __('Check BOG Status'),
                    'class' => 'action-secondary',
                    'onclick' => "setLocation('{$checkUrl}')",
                ]
            );
        }

        // "Void Payment" button -- for preauth orders with held funds (not yet captured)
        if (
            $order->getState() === Order::STATE_PROCESSING
            && $payment->getAdditionalInformation('preauth_approved')
            && $payment->getAdditionalInformation('capture_status') !== 'captured'
        ) {
            $voidUrl = $subject->getUrl(
                'shubo_bog/order/voidPayment',
                ['order_id' => $order->getEntityId()]
            );

            $subject->addButton(
                'bog_void_payment',
                [
                    'label' => __('Void Payment'),
                    'class' => 'action-secondary',
                    'onclick' => "confirmSetLocation('"
                        . __('This will cancel the payment authorization. The order will be cancelled. Continue?')
                        . "', '{$voidUrl}')",
                ]
            );
        }

        if ($order->getState() !== Order::STATE_PROCESSING) {
            return;
        }

        // "Capture Payment" button -- for preauth orders awaiting capture
        if (
            $payment->getAdditionalInformation('preauth_approved')
            && $payment->getAdditionalInformation('capture_status') !== 'captured'
        ) {
            $captureUrl = $subject->getUrl(
                'shubo_bog/order/capture',
                ['order_id' => $order->getEntityId()]
            );

            $subject->addButton(
                'bog_capture_payment',
                [
                    'label' => __('Capture Payment'),
                    'class' => 'action-secondary',
                    'onclick' => "confirmSetLocation('"
                        . __('This will charge the held amount on the customer\'s card. Continue?')
                        . "', '{$captureUrl}')",
                ]
            );
        }
    }
}
