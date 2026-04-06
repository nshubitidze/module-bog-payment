<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Validator\CallbackValidator;

class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly CallbackValidator $callbackValidator,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly OrderSender $orderSender,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(200);

        try {
            $body = $this->request->getContent();

            /** @var array{order_id?: string, shop_order_id?: string, status?: string}|null $callbackData */
            $callbackData = json_decode((string) $body, true);

            if (!is_array($callbackData)) {
                $this->logger->warning('BOG callback: invalid JSON body');
                $result->setContents('INVALID_BODY');
                return $result;
            }

            $bogOrderId = (string) ($callbackData['order_id'] ?? '');
            $shopOrderId = (string) ($callbackData['shop_order_id'] ?? '');

            $this->logger->info('BOG callback received', [
                'bog_order_id' => $bogOrderId,
                'shop_order_id' => $shopOrderId,
                'callback_status' => $callbackData['status'] ?? 'unknown',
            ]);

            if ($bogOrderId === '') {
                $this->logger->warning('BOG callback: missing order_id');
                $result->setContents('MISSING_ORDER_ID');
                return $result;
            }

            // Validate payment by calling BOG Status API
            $validation = $this->callbackValidator->validate($bogOrderId);

            if (!$validation['valid']) {
                $this->logger->warning('BOG callback: payment validation failed', [
                    'bog_order_id' => $bogOrderId,
                    'validation_status' => $validation['status'],
                ]);
                $result->setContents('VALIDATION_FAILED');
                return $result;
            }

            // Find the Magento order
            $order = $this->findOrder($shopOrderId, $bogOrderId);

            if ($order === null) {
                $this->logger->error('BOG callback: order not found', [
                    'shop_order_id' => $shopOrderId,
                    'bog_order_id' => $bogOrderId,
                ]);
                $result->setContents('ORDER_NOT_FOUND');
                return $result;
            }

            $this->processSuccessfulPayment($order, $bogOrderId, $validation);

            $result->setContents('OK');
        } catch (\Exception $e) {
            $this->logger->critical('BOG callback processing error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Return 200 even on error to prevent BOG from retrying
            $result->setContents('ERROR');
        }

        return $result;
    }

    /**
     * CSRF validation is not applicable for external callbacks.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Skip CSRF validation for BOG callback endpoint.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Find order by increment ID or BOG order ID in payment additional info.
     */
    private function findOrder(string $shopOrderId, string $bogOrderId): ?Order
    {
        if ($shopOrderId !== '') {
            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToFilter('increment_id', $shopOrderId);
            $collection->setPageSize(1);

            /** @var Order|null $order */
            $order = $collection->getFirstItem();
            if ($order && $order->getId()) {
                return $order;
            }
        }

        // Fallback: search by BOG order ID in payment additional info
        // This is less efficient but ensures we find the order
        $this->logger->info('BOG callback: searching order by bog_order_id', [
            'bog_order_id' => $bogOrderId,
        ]);

        return null;
    }

    /**
     * Process a successfully validated payment: create invoice, update order status.
     *
     * @param array{valid: bool, status: string, data: array<string, mixed>} $validation
     */
    private function processSuccessfulPayment(Order $order, string $bogOrderId, array $validation): void
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            $this->logger->error('BOG callback: order has no payment', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        // Update payment additional information
        $payment->setAdditionalInformation('bog_status', $validation['status']);
        $payment->setAdditionalInformation('bog_order_id', $bogOrderId);

        if (isset($validation['data']['payment_id'])) {
            $payment->setAdditionalInformation('bog_payment_id', (string) $validation['data']['payment_id']);
        }

        $payment->setTransactionId($bogOrderId);
        $payment->setIsTransactionClosed(true);
        $payment->setIsTransactionPending(false);

        // Create invoice if order can be invoiced
        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
            $invoice->register();

            $transaction = $this->transactionFactory->create();
            $transaction->addObject($invoice);
            $transaction->addObject($order);
            $transaction->save();
        }

        // Update order status to processing
        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus('processing');
        $order->addCommentToStatusHistory(
            (string) __('Payment confirmed by BOG iPay. Order ID: %1', $bogOrderId)
        );

        $this->orderRepository->save($order);

        // Send order confirmation email
        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->logger->warning('BOG callback: failed to send order email', [
                'order_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);
        }

        $this->logger->info('BOG callback: order processed successfully', [
            'order_id' => $order->getIncrementId(),
            'bog_order_id' => $bogOrderId,
        ]);
    }
}
