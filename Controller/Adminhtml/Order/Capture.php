<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\CaptureClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;
use Shubo\BogPayment\Service\MoneyCaster;

/**
 * Admin controller to manually capture a pre-authorized BOG payment.
 *
 * Uses the BOG Payments API to approve (capture) a pre-authorized payment.
 */
class Capture extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_BogPayment::capture';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CaptureClient $captureClient,
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
            $storeId = (int) $order->getStoreId();

            $bogOrderId = (string) $payment->getAdditionalInformation('bog_order_id');
            if ($bogOrderId === '') {
                // LocalizedException so the admin sees the actionable message
                // verbatim (it's by Magento convention author-safe). Don't use
                // a RuntimeException here — that would land in the bland
                // generic catch and the admin couldn't tell which order
                // was missing the identifier.
                throw new LocalizedException(
                    __('No BOG order ID found on this order.')
                );
            }

            // BUG-BOG-8: MoneyCaster encapsulates the Payment API float boundary.
            // Used both for the wire amount to BOG and for the subsequent
            // registerCaptureNotification call — a single clamped value
            // guarantees the two always agree.
            $amount = MoneyCaster::toMagentoFloat($order->getGrandTotal());
            $currency = (string) $order->getOrderCurrencyCode();

            $response = $this->captureClient->capture(
                orderId: $bogOrderId,
                storeId: $storeId,
                amount: $amount,
                description: (string) __('Capture for order %1', $order->getIncrementId()),
            );

            $captureStatus = strtolower(
                (string) ($response['order_status']['key'] ?? ($response['status'] ?? ''))
            );

            if (in_array($captureStatus, ['captured', 'completed', 'success'], true)) {
                $payment->setAdditionalInformation('preauth_approved', false);
                $payment->setAdditionalInformation('capture_status', 'captured');
                $payment->registerCaptureNotification($amount);

                $order->addCommentToStatusHistory(
                    (string) __('Payment captured by BOG. Amount: %1 %2', $order->getGrandTotal(), $currency)
                );

                $this->orderRepository->save($order);
                $this->messageManager->addSuccessMessage(
                    (string) __('Payment has been captured successfully.')
                );
            } else {
                // Session 8 P2.2 — log raw BOG envelope for support; throw a
                // mapped friendly exception (HTTP 422 = "action invalid for
                // current state" — the closest match for "BOG returned a
                // status that wasn't captured/completed").
                $this->logger->error('BOG HTTP error mapped to user copy', [
                    'context' => 'admin.capture',
                    'order_id' => $orderId,
                    'capture_status' => $captureStatus,
                    'message' => $response['message'] ?? null,
                    'error' => $response['error'] ?? null,
                ]);
                throw $this->userFacingErrorMapper->toLocalizedException(
                    422,
                    (string) ($response['message'] ?? $response['error'] ?? ''),
                );
            }
        } catch (BogApiException $e) {
            $this->logger->error('BOG HTTP error mapped to user copy', [
                'context' => 'admin.capture',
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'raw_message' => $e->getMessage(),
            ]);
            $friendly = $this->userFacingErrorMapper->toLocalizedException(
                0,
                $e->getMessage(),
            );
            $this->messageManager->addErrorMessage($friendly->getMessage());
        } catch (LocalizedException $e) {
            // Magento LocalizedException is by convention author-safe.
            $this->logger->error('BOG manual capture — LocalizedException', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            // Pass-1 reviewer S-2: never leak raw exception text from a
            // generic catch. Log everything; admin sees a bland-but-no-leak
            // message and the dedicated log carries the trace for support.
            $this->logger->error('BOG manual capture failed', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __(
                    'Capture failed. See shubo_bog_payment.log for details.'
                )
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
