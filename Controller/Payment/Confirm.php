<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Gateway\Validator\CallbackValidator;

/**
 * Confirms a BOG payment by checking the actual status via the API.
 *
 * Called as a safety net -- if the Return controller couldn't process the
 * payment, or if an admin triggers a manual check. Verifies the BOG status
 * and processes the order if payment is confirmed.
 */
class Confirm implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StatusClient $statusClient,
        private readonly CallbackValidator $callbackValidator,
        private readonly Config $config,
        private readonly OrderSender $orderSender,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order || !$order->getEntityId()) {
                return $result->setData(['success' => false, 'message' => (string) __('No order found.')]);
            }

            /** @var Payment $payment */
            $payment = $order->getPayment();
            $bogOrderId = (string) $payment->getAdditionalInformation('bog_order_id');

            if ($bogOrderId === '') {
                return $result->setData(['success' => false, 'message' => (string) __('No BOG order ID found.')]);
            }

            // Already processed
            if ($order->getState() === Order::STATE_PROCESSING) {
                return $result->setData(['success' => true, 'already_processed' => true]);
            }

            $storeId = (int) $order->getStoreId();
            $statusResponse = $this->statusClient->checkStatus($bogOrderId, $storeId);
            $orderStatusKey = strtolower(
                (string) ($statusResponse['order_status']['key'] ?? ($statusResponse['status'] ?? ''))
            );

            $this->logger->info('BOG Confirm: status check', [
                'order_id' => $order->getIncrementId(),
                'bog_status' => $orderStatusKey,
            ]);

            if (!in_array($orderStatusKey, ['completed', 'captured'], true)) {
                return $result->setData([
                    'success' => false,
                    'bog_status' => $orderStatusKey,
                    'message' => (string) __('Payment not yet confirmed.'),
                ]);
            }

            // Validate via callback validator
            $validation = $this->callbackValidator->validate(
                bogOrderId: $bogOrderId,
                storeId: $storeId,
            );

            if (!$validation['valid']) {
                $this->logger->error('BOG Confirm: validation failed', [
                    'order_id' => $order->getIncrementId(),
                ]);
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Payment validation failed.'),
                ]);
            }

            $this->processApproval($order, $payment, $statusResponse, $storeId);
            $this->orderRepository->save($order);

            return $result->setData(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('BOG Confirm error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setData([
                'success' => false,
                'message' => (string) __('Unable to confirm payment.'),
            ]);
        }
    }

    /**
     * Process an approved BOG payment: update payment info, create invoice.
     *
     * @param array<string, mixed> $statusResponse
     */
    private function processApproval(
        Order $order,
        Payment $payment,
        array $statusResponse,
        int $storeId,
    ): void {
        // Store payment details from status response
        $infoKeys = [
            'payment_hash', 'card_type', 'pan', 'payment_method',
            'terminal_id',
        ];
        foreach ($infoKeys as $key) {
            $value = $statusResponse[$key] ?? null;
            if ($value !== null && !is_array($value)) {
                $payment->setAdditionalInformation('bog_' . $key, (string) $value);
            }
        }

        $payment->setAdditionalInformation('bog_status', 'completed');

        $bogOrderId = (string) $payment->getAdditionalInformation('bog_order_id');
        $payment->setTransactionId($bogOrderId);

        if ($this->config->isPreauth($storeId)) {
            $payment->setAdditionalInformation('preauth_approved', true);
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(false);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Funds held by BOG (confirmed). Order ID: %1. Use "Capture Payment" to charge.', $bogOrderId)
            );
        } else {
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(true);
            $payment->registerCaptureNotification((float) $order->getGrandTotal());
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Payment confirmed by BOG. Order ID: %1', $bogOrderId)
            );
        }

        // Send order confirmation email
        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->logger->warning('BOG Confirm: failed to send order email', [
                'order_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);
        }

        $this->logger->info('BOG Confirm: order approved', [
            'order_id' => $order->getIncrementId(),
            'bog_order_id' => $bogOrderId,
        ]);
    }
}
