<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Aborts a stalled/failed BOG checkout so the customer can retry.
 *
 * Two scenarios trigger this endpoint:
 *
 *   1. The `initiate` AJAX call succeeded (we have a bog_redirect_url on the
 *      quote), but the browser-side `window.location.href = …` navigation
 *      never took effect (CSP block, popup blocker, extension, slow network).
 *      The quote still carries `bog_order_id` + `bog_redirect_url` and must be
 *      cleared so the customer can re-initiate without the "payment already
 *      in progress" stale state.
 *
 *   2. The `initiate` AJAX itself failed. Same cleanup: if any BOG data was
 *      stored on the quote, wipe it so the next attempt starts clean.
 *
 * Unlike TBC's AbortRedirect this endpoint normally has NO Magento order to
 * cancel, because the BOG flow intentionally defers order placement until the
 * customer returns with a terminal payment status. We only guard/cancel an
 * order if `increment_id` is present AND the order is a genuine `shubo_bog`
 * pending_payment orphan (uninvoiced). That can happen if an admin manually
 * called CartManagementInterface::placeOrder() or in future paths we haven't
 * planned for.
 *
 * CSRF: exempt because this is invoked from our own checkout JS with a
 * form_key (validated by the default FormKeyValidator plugin on the POST
 * route). Exempting here prevents spurious CSRF errors in test contexts and
 * matches the pattern used by Callback.php on the same route.
 */
class AbortRedirect implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly HttpRequest $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): ResultInterface
    {
        /** @var JsonResult $result */
        $result = $this->jsonFactory->create();

        $incrementId = trim((string) $this->request->getParam('increment_id', ''));

        try {
            $quoteCleared = $this->clearQuoteBogData();
            $orderCancelled = false;

            if ($incrementId !== '') {
                $orderCancelled = $this->cancelOrphanOrderIfCancelable($incrementId);
            }

            $this->logger->info('BOG abortRedirect: completed', [
                'increment_id' => $incrementId,
                'quote_cleared' => $quoteCleared,
                'order_cancelled' => $orderCancelled,
            ]);

            return $result->setData([
                'success' => true,
                'increment_id' => $incrementId,
                'quote_cleared' => $quoteCleared,
                'order_cancelled' => $orderCancelled,
                'message' => (string) __('Payment session reset. You may retry.'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('BOG abortRedirect: unexpected error', [
                'increment_id' => $incrementId,
                'exception' => $e->getMessage(),
            ]);

            return $result->setData([
                'success' => false,
                'message' => (string) __('Unable to reset payment session.'),
            ]);
        }
    }

    /**
     * Remove any BOG-specific payment info from the active quote so the next
     * checkout attempt starts clean. Returns true if anything was cleared.
     */
    private function clearQuoteBogData(): bool
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Exception $e) {
            $this->logger->warning('BOG abortRedirect: could not load quote', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($quote->getId() === null) {
            return false;
        }

        $payment = $quote->getPayment();

        $keys = ['bog_order_id', 'bog_redirect_url', 'bog_details_url', 'bog_status'];
        $touched = false;
        foreach ($keys as $key) {
            if ($payment->getAdditionalInformation($key) !== null) {
                $payment->unsAdditionalInformation($key);
                $touched = true;
            }
        }

        if (!$touched) {
            return false;
        }

        try {
            $this->cartRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->warning('BOG abortRedirect: failed to save quote after clear', [
                'quote_id' => $quote->getId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Cancel an orphan `shubo_bog` order in `pending_payment` state, if one
     * exists for the given increment_id. Guards ensure this endpoint cannot
     * be weaponized to cancel arbitrary orders:
     *
     *   - Order must be loadable by increment_id.
     *   - Order state must be pending_payment (not already captured).
     *   - Order must have zero invoices (defence-in-depth against a race).
     *   - Payment method must be exactly `shubo_bog`.
     */
    private function cancelOrphanOrderIfCancelable(string $incrementId): bool
    {
        $order = $this->loadOrder($incrementId);

        if ($order === null) {
            return false;
        }

        if (!$this->isCancelable($order)) {
            $this->logger->info('BOG abortRedirect: order not in a cancelable state', [
                'increment_id' => $incrementId,
                'state' => $order->getState(),
                'invoices' => $order->getInvoiceCollection()->getSize(),
                'method' => $this->getPaymentMethod($order),
            ]);
            return false;
        }

        $order->cancel();
        $order->addCommentToStatusHistory(
            (string) __('BOG payment redirect failed on the client. Order cancelled so customer can retry.')
        );
        $this->orderRepository->save($order);

        // Best-effort: restore the quote so the customer's cart is repopulated
        // and they can try again without rebuilding it. This no-ops cleanly if
        // no lastOrderId is set on the session (the usual case in our flow).
        try {
            $this->checkoutSession->restoreQuote();
        } catch (\Exception $e) {
            $this->logger->warning('BOG abortRedirect: restoreQuote failed', [
                'increment_id' => $incrementId,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function isCancelable(Order $order): bool
    {
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return false;
        }

        if ($order->getInvoiceCollection()->getSize() > 0) {
            return false;
        }

        if ($this->getPaymentMethod($order) !== ConfigProvider::CODE) {
            return false;
        }

        return true;
    }

    private function getPaymentMethod(Order $order): string
    {
        /** @var Payment|null $payment */
        $payment = $order->getPayment();

        if ($payment === null) {
            return '';
        }

        return (string) $payment->getMethod();
    }

    private function loadOrder(string $incrementId): ?Order
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        /** @var Order|null $order */
        $order = reset($orders) ?: null;

        return $order;
    }
}
