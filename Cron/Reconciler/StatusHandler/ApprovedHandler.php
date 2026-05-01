<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron\Reconciler\StatusHandler;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Service\MoneyCaster;

/**
 * Handle approved payment -- register capture, move to processing.
 *
 * Extracted verbatim from {@see \Shubo\BogPayment\Cron\PendingOrderReconciler::handleApproved}
 * during the 2026-05-01 god-class split. The private storePaymentDetails
 * helper (sole-caller was handleApproved) moves with this handler.
 */
class ApprovedHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Config $config,
        private readonly OrderSender $orderSender,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle approved payment -- register capture, move to processing.
     *
     * @param array<string, mixed> $response BOG status response
     */
    public function handle(Order $order, Payment $payment, array $response): void
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
        // BUG-BOG-8: see MoneyCaster note in Callback.php.
        $payment->registerCaptureNotification(
            MoneyCaster::toMagentoFloat($order->getGrandTotal())
        );

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
