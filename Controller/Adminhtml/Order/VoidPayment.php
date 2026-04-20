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
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Admin controller to void a pending or pre-authorized BOG payment.
 *
 * Cancels the Magento order. For pre-authorized payments, the hold expires
 * automatically on the bank side.
 */
class VoidPayment extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_BogPayment::void';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
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
            $payment->setAdditionalInformation('preauth_approved', false);

            $order->cancel();
            $order->addCommentToStatusHistory(
                (string) __('Payment voided by admin. Order cancelled.')
            );
            $this->orderRepository->save($order);

            $this->messageManager->addSuccessMessage(
                (string) __('Payment has been voided and order cancelled.')
            );
        } catch (\Exception $e) {
            $this->logger->error('BOG void payment failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Void failed: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
