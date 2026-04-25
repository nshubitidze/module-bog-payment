<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;
use Shubo\BogPayment\Service\MoneyCaster;

/**
 * Admin controller to check and sync the BOG payment status for an order.
 *
 * Queries the BOG Status API. If the payment is completed but the order hasn't
 * been updated yet, processes the approval (capture + invoice).
 */
class CheckStatus extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_BogPayment::check_status';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StatusClient $statusClient,
        private readonly Config $config,
        private readonly OrderSender $orderSender,
        private readonly LoggerInterface $logger,
        private readonly UserFacingErrorMapper $userFacingErrorMapper,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);
            /** @var Payment|null $payment */
            $payment = $order->getPayment();
            if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
                throw new LocalizedException(__('Invalid payment method for this action.'));
            }
            $bogOrderId = (string) $payment->getAdditionalInformation('bog_order_id');

            if ($bogOrderId === '') {
                $this->messageManager->addWarningMessage((string) __('No BOG order ID found.'));
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            $storeId = (int) $order->getStoreId();
            $response = $this->statusClient->checkStatus($bogOrderId, $storeId);
            $orderStatusKey = strtolower(
                (string) ($response['order_status']['key'] ?? ($response['status'] ?? 'unknown'))
            );

            $this->messageManager->addSuccessMessage(
                (string) __('BOG payment status: %1 | Order ID: %2 | Card: %3',
                    $orderStatusKey,
                    $bogOrderId,
                    $response['pan'] ?? 'N/A'
                )
            );

            // If BOG says completed but order is still pending -- process it
            if (
                in_array($orderStatusKey, ['completed', 'captured'], true)
                && in_array($order->getState(), [Order::STATE_PAYMENT_REVIEW, Order::STATE_PENDING_PAYMENT], true)
            ) {
                $this->processApproval($order, $payment, $response, $storeId);
                $this->orderRepository->save($order);

                $this->messageManager->addSuccessMessage(
                    (string) __('Order updated to processing. Payment confirmed.')
                );
            } elseif (
                in_array($orderStatusKey, ['error', 'rejected', 'expired', 'declined'], true)
                && $order->getState() !== Order::STATE_CANCELED
            ) {
                $order->cancel();
                $order->addCommentToStatusHistory(
                    (string) __('Order cancelled after manual status check. BOG status: %1', $orderStatusKey)
                );
                $this->orderRepository->save($order);
                $this->messageManager->addWarningMessage(
                    (string) __('Payment %1. Order has been cancelled.', $orderStatusKey)
                );
            }
        } catch (BogApiException $e) {
            // Session 8 P2.2 — never surface raw API exception text to admin.
            // Log the raw triple so support can correlate, then route the
            // friendly mapped message to the toast.
            $this->logger->error('BOG HTTP error mapped to user copy', [
                'context' => 'admin.checkstatus',
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'raw_message' => $e->getMessage(),
            ]);
            // BogApiException doesn't carry an HTTP code today; default to 0
            // (network-error bucket) so the admin sees a sympathetic message.
            $friendly = $this->userFacingErrorMapper->toLocalizedException(
                0,
                $e->getMessage(),
            );
            $this->messageManager->addErrorMessage($friendly->getMessage());
        } catch (LocalizedException $e) {
            // Magento LocalizedException is by convention author-safe (built
            // via __()); message text is allowed to flow through to admin.
            $this->logger->error('BOG admin status check — LocalizedException', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            // Pass-1 reviewer S-1 (mirror of TBC Pass-4 S-4): never leak
            // raw exception text from a generic catch. Log the full triple
            // to the dedicated BOG log; admin sees a bland but no-leak msg.
            $this->logger->error('BOG admin status check failed', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __(
                    'Status check failed. See shubo_bog_payment.log for details.'
                )
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }

    /**
     * Process an approved payment -- mirrors the callback handler.
     *
     * @param array<string, mixed> $response
     */
    private function processApproval(Order $order, Payment $payment, array $response, int $storeId): void
    {
        $infoKeys = ['payment_hash', 'card_type', 'pan', 'payment_method', 'terminal_id'];
        foreach ($infoKeys as $key) {
            $value = $response[$key] ?? null;
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
                (string) __('Funds held by BOG (manual status check). Order ID: %1. Use "Capture Payment" to charge.', $bogOrderId)
            );
        } else {
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(true);
            // BUG-BOG-8: MoneyCaster encapsulates the Payment API float boundary.
            $payment->registerCaptureNotification(
                MoneyCaster::toMagentoFloat($order->getGrandTotal())
            );
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $order->addCommentToStatusHistory(
                (string) __('Payment approved by BOG (manual status check). Order ID: %1', $bogOrderId)
            );
        }

        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->logger->warning('BOG admin: failed to send order email', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
