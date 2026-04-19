<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Service\PaymentLock;

/**
 * Handles the customer return from BOG payment page.
 *
 * This is where the Magento order is actually created. The flow:
 * 1. Retrieve the quote and BOG order ID from checkout session
 * 2. Check payment status via BOG Status API
 * 3. If success: place Magento order, process payment, redirect to success
 * 4. If failed/pending: redirect to failure or back to checkout
 */
class ReturnAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly CartManagementInterface $cartManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StatusClient $statusClient,
        private readonly OrderSender $orderSender,
        private readonly MessageManager $messageManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly PaymentLock $paymentLock,
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote || !$quote->getId()) {
                $this->logger->error('BOG Return: no active quote in session');
                $this->messageManager->addErrorMessage(
                    (string) __('Your session has expired. Please try again.')
                );
                return $redirect->setPath('checkout/cart');
            }

            $quotePayment = $quote->getPayment();
            $bogOrderId = (string) ($quotePayment->getAdditionalInformation('bog_order_id') ?? '');

            if ($bogOrderId === '') {
                $this->logger->error('BOG Return: no bog_order_id on quote payment', [
                    'quote_id' => $quote->getId(),
                ]);
                $this->messageManager->addErrorMessage(
                    (string) __('Payment information not found. Please try again.')
                );
                return $redirect->setPath('checkout');
            }

            $storeId = (int) $quote->getStoreId();

            // Check BOG payment status
            $statusResponse = $this->statusClient->checkStatus($bogOrderId, $storeId);
            $orderStatusKey = strtolower(
                (string) ($statusResponse['order_status']['key'] ?? ($statusResponse['status'] ?? ''))
            );

            $this->logger->info('BOG Return: status check', [
                'bog_order_id' => $bogOrderId,
                'status' => $orderStatusKey,
            ]);

            if (in_array($orderStatusKey, ['completed', 'captured'], true)) {
                return $this->handleSuccess($quote, $bogOrderId, $statusResponse, $redirect);
            }

            if ($orderStatusKey === 'in_progress' || $orderStatusKey === 'created') {
                return $this->handlePending($quote, $bogOrderId, $redirect);
            }

            // Payment failed or unknown status
            $this->logger->warning('BOG Return: payment not successful', [
                'bog_order_id' => $bogOrderId,
                'status' => $orderStatusKey,
            ]);

            // Clear the BOG data from quote so customer can retry
            $quotePayment->unsAdditionalInformation('bog_order_id');
            $quotePayment->unsAdditionalInformation('bog_redirect_url');
            $quotePayment->unsAdditionalInformation('bog_status');
            $this->cartRepository->save($quote);

            $this->messageManager->addErrorMessage(
                (string) __('Payment was not completed. Please try again.')
            );
            return $redirect->setPath('checkout');
        } catch (\Exception $e) {
            $this->logger->critical('BOG Return error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('An error occurred processing your payment. Please contact support.')
            );
            return $redirect->setPath('checkout/onepage/failure');
        }
    }

    /**
     * Handle successful BOG payment: create Magento order, process payment.
     *
     * @param \Magento\Quote\Model\Quote $quote Active quote
     * @param string $bogOrderId BOG order ID
     * @param array<string, mixed> $statusResponse BOG status response
     * @param \Magento\Framework\Controller\Result\Redirect $redirect Redirect result
     */
    private function handleSuccess(
        \Magento\Quote\Model\Quote $quote,
        string $bogOrderId,
        array $statusResponse,
        \Magento\Framework\Controller\Result\Redirect $redirect,
    ): ResultInterface {
        // BUG-BOG-6: serialize with Callback + Cron. Null on contention means
        // another handler is already placing the order; fall through and
        // redirect to success — the session's last_real_order_id was set by
        // whichever handler actually placed the order.
        $result = $this->paymentLock->withLock(
            $bogOrderId,
            fn(): Order => $this->placeAndProcessOrder($quote, $bogOrderId, $statusResponse)
        );

        if ($result === null) {
            $this->logger->info('BOG Return: lock contended, assuming another handler finalized', [
                'quote_id' => $quote->getId(),
                'bog_order_id' => $bogOrderId,
            ]);
        }

        return $redirect->setPath('checkout/onepage/success');
    }

    /**
     * Place the Magento order and run the capture flow. Runs inside a
     * PaymentLock in handleSuccess — no need to double-lock. Re-reads the
     * quote's payment method to detect an order already placed by a
     * concurrent Callback.
     *
     * @param array<string, mixed> $statusResponse
     */
    private function placeAndProcessOrder(
        \Magento\Quote\Model\Quote $quote,
        string $bogOrderId,
        array $statusResponse,
    ): Order {
        $quote->getPayment()->setMethod(Config::METHOD_CODE);
        $quote->getPayment()->setAdditionalInformation('bog_order_id', $bogOrderId);
        $quote->getPayment()->setAdditionalInformation('bog_status', 'completed');
        $this->cartRepository->save($quote);

        $orderId = $this->cartManagement->placeOrder($quote->getId());

        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);

        // Re-check state inside the lock — Callback may have beat us and
        // already moved this order to processing with a registered capture.
        if ($order->getState() === Order::STATE_PROCESSING) {
            $this->logger->info('BOG Return: order already processing, skipping re-capture', [
                'order_id' => $order->getIncrementId(),
                'bog_order_id' => $bogOrderId,
            ]);
        } else {
            $this->processSuccessfulPayment($order, $bogOrderId, $statusResponse);
        }

        $this->checkoutSession->setLastSuccessQuoteId($quote->getId());
        $this->checkoutSession->setLastQuoteId($quote->getId());
        $this->checkoutSession->setLastOrderId($orderId);
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());

        $this->logger->info('BOG Return: order placed successfully', [
            'order_id' => $order->getIncrementId(),
            'bog_order_id' => $bogOrderId,
        ]);

        return $order;
    }

    /**
     * Handle pending BOG payment.
     *
     * CRITICAL (BUG-BOG-11): do NOT place a Magento order while the BOG
     * status is still `in_progress` / `created`. If we did and BOG later
     * terminated the payment as failed, a real order would sit forever in
     * pending_payment without a matching capture — a ghost order.
     *
     * Instead:
     *   - Keep bog_order_id on the quote so the customer can return later
     *     (or the Callback/Confirm handlers can finalize when BOG posts the
     *     terminal status).
     *   - Show a friendly "payment is still processing — we'll email you"
     *     message on the checkout page. No success page is shown, since no
     *     order exists yet.
     *   - The Callback + Confirm controllers already look up a Magento order
     *     by reserved_order_id (increment_id) — when BOG terminally confirms
     *     success, they materialize the Magento order from the preserved
     *     quote via CartManagementInterface::placeOrder.
     *
     * @param \Magento\Quote\Model\Quote $quote Active quote
     * @param string $bogOrderId BOG order ID
     * @param \Magento\Framework\Controller\Result\Redirect $redirect Redirect result
     */
    private function handlePending(
        \Magento\Quote\Model\Quote $quote,
        string $bogOrderId,
        \Magento\Framework\Controller\Result\Redirect $redirect,
    ): ResultInterface {
        $quote->getPayment()->setMethod(Config::METHOD_CODE);
        $quote->getPayment()->setAdditionalInformation('bog_order_id', $bogOrderId);
        $quote->getPayment()->setAdditionalInformation('bog_status', 'in_progress');
        $this->cartRepository->save($quote);

        $this->logger->info('BOG Return: payment still in progress, no Magento order placed', [
            'quote_id' => $quote->getId(),
            'bog_order_id' => $bogOrderId,
            'reserved_order_id' => $quote->getReservedOrderId(),
        ]);

        $this->messageManager->addNoticeMessage(
            (string) __(
                'Your payment is still being processed by the bank. '
                . 'We will email you as soon as it is confirmed. '
                . 'If no email arrives within 30 minutes, please contact support.'
            )
        );

        return $redirect->setPath('checkout');
    }

    /**
     * Process a successfully validated payment: create invoice, update order status.
     *
     * @param Order $order Magento order
     * @param string $bogOrderId BOG order ID
     * @param array<string, mixed> $statusResponse BOG status response
     */
    private function processSuccessfulPayment(
        Order $order,
        string $bogOrderId,
        array $statusResponse,
    ): void {
        /** @var Payment $payment */
        $payment = $order->getPayment();

        $payment->setAdditionalInformation('bog_order_id', $bogOrderId);
        $payment->setAdditionalInformation('bog_status', 'completed');

        $this->storePaymentDetails($payment, $statusResponse);

        $payment->setTransactionId($bogOrderId);
        $storeId = (int) $order->getStoreId();

        if ($this->config->isPreauth($storeId)) {
            $payment->setAdditionalInformation('preauth_approved', true);
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(false);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Funds held by BOG. Order ID: %1. Use "Capture Payment" to charge.', $bogOrderId)
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

        $this->orderRepository->save($order);

        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->logger->warning('BOG Return: failed to send order email', [
                'order_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store BOG payment details from status response.
     *
     * @param Payment $payment Order payment
     * @param array<string, mixed> $statusResponse BOG status response
     */
    private function storePaymentDetails(Payment $payment, array $statusResponse): void
    {
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

        if (isset($statusResponse['order_status']) && is_array($statusResponse['order_status'])) {
            $payment->setAdditionalInformation(
                'bog_order_status_key',
                (string) ($statusResponse['order_status']['key'] ?? '')
            );
        }
    }
}
