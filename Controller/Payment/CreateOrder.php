<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class CreateOrder implements HttpPostActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly JsonFactory $jsonFactory,
        private readonly CommandPoolInterface $commandPool,
        private readonly PaymentDataObjectFactory $paymentDataObjectFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order || !$order->getId()) {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('No order found in session.'),
                ]);
            }

            $payment = $order->getPayment();
            if ($payment === null) {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Payment information not found.'),
                ]);
            }

            if (!$payment instanceof InfoInterface) {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Payment object is incompatible.'),
                ]);
            }

            $paymentDataObject = $this->paymentDataObjectFactory->create($payment);

            $this->commandPool->get('initialize')->execute([
                'payment' => $paymentDataObject,
                'amount' => (float) $order->getGrandTotal(),
            ]);

            // Reload payment to get updated additional information
            $this->orderRepository->save($order);

            $additionalInfo = $payment->getAdditionalInformation();
            $additionalInfo = is_array($additionalInfo) ? $additionalInfo : [];
            $redirectUrl = $additionalInfo['bog_redirect_url'] ?? null;
            $bogOrderId = $additionalInfo['bog_order_id'] ?? null;

            return $result->setData([
                'success' => true,
                'redirect_url' => $redirectUrl,
                'bog_order_id' => $bogOrderId,
            ]);
        } catch (LocalizedException $e) {
            $this->logger->error('BOG CreateOrder controller error', [
                'message' => $e->getMessage(),
            ]);
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->critical('BOG CreateOrder controller unexpected error', [
                'exception' => $e->getMessage(),
            ]);
            return $result->setData([
                'success' => false,
                'message' => (string) __('An error occurred while creating the payment. Please try again.'),
            ]);
        }
    }
}
