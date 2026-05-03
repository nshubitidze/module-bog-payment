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
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\CaptureClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;
use Shubo\BogPayment\Service\MoneyCaster;

/**
 * Admin controller to manually capture a pre-authorized BOG payment.
 *
 * Session 18 (BUG-BOG-CAPTURE-AFTER-AUTH): under
 * `payment_action_mode=manual` (preauth) this controller calls BOG's
 * preauth-capture endpoint and registers a Magento capture notification.
 * Under the default `payment_action_mode=automatic` the customer was
 * already charged-and-captured at payment time via the BOG create-order
 * callback, so the "Capture" button becomes an informational refresh
 * (no API call, no order-state change, no `registerCaptureNotification`).
 * Mode-check ordering, idempotency layers, and failure-mode taxonomy are
 * defined in `docs/design-bog-capture-after-authorise-2026-05-03.md`.
 */
class Capture extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_BogPayment::capture';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly CaptureClient $captureClient,
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

            // Guard 1 — payment method (BUG-BOG-4 regression). Refuse
            // PayPal/checkmo/etc. so this controller cannot be repurposed
            // as a generic capture backdoor.
            /** @var Payment|null $payment */
            $payment = $order->getPayment();
            if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
                throw new LocalizedException(__('Invalid payment method for this action.'));
            }

            $storeId = (int) $order->getStoreId();
            $mode = $this->config->getPaymentActionMode($storeId);

            // Guard 2 — payment action mode. Under automatic capture the
            // customer was already charged-and-captured via the create-order
            // callback, the invoice exists, and the order is already in
            // `processing`. Calling BOG's preauth-capture endpoint would be
            // a duplicate that BOG rejects with confusing copy. Skip the
            // API call entirely and surface an informational message.
            //
            // CRITICAL: do NOT registerCaptureNotification here — the
            // invoice already exists; re-firing would raise a duplicate-
            // invoice exception that masquerades as a capture failure.
            if ($mode !== 'manual') {
                $this->logger->info('BOG capture: skipping API (automatic mode)', [
                    'order_id' => $orderId,
                    'mode' => $mode,
                ]);
                $this->messageManager->addSuccessMessage(
                    (string) __('Payment was already captured at payment time.')
                );
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            // Guard 3 — bog_order_id presence (preauth path only).
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

            // Guard 4 — local idempotency. If a prior click already captured,
            // skip the BOG round-trip; BOG would respond correctly either way
            // but we save a token + network round-trip per accidental click.
            // Belt-and-suspenders against double-click races.
            if ($payment->getAdditionalInformation('capture_status') === 'captured') {
                $this->logger->info('BOG capture: capture already recorded locally, skipping API', [
                    'order_id' => $orderId,
                    'bog_order_id' => $bogOrderId,
                ]);
                $this->messageManager->addSuccessMessage(
                    (string) __('Payment was already captured.')
                );
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            // BUG-BOG-8: MoneyCaster encapsulates the Payment API float boundary.
            // Used both for the wire amount to BOG and for the subsequent
            // registerCaptureNotification call — a single clamped value
            // guarantees the two always agree.
            $amount = MoneyCaster::toMagentoFloat($order->getGrandTotal());
            $currency = (string) $order->getOrderCurrencyCode();

            try {
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
                // BOG returned non-2xx OR transport failure. Two sub-paths:
                //   a) "already captured" (BOG idempotent reply) → benign;
                //      log WARNING, set capture_status idempotently, admin
                //      sees success-style copy. CRITICAL: do NOT call
                //      registerCaptureNotification — the invoice already
                //      exists from a prior click or the callback, and
                //      double-firing raises a duplicate-invoice exception.
                //   b) anything else (network 5xx, generic 4xx, malformed) →
                //      fail-closed: order state and capture_status untouched;
                //      admin sees retry copy via UserFacingErrorMapper. Cron
                //      reconciler will pick up on next pass.
                $rawText = strtolower($e->getMessage());
                $isAlreadyCaptured = $this->messageMentionsAlready(
                    $rawText,
                    ['captur', 'complet', 'approve']
                );

                if ($isAlreadyCaptured) {
                    $this->logger->warning('BOG capture: already captured (idempotent)', [
                        'context' => 'admin.capture',
                        'order_id' => $orderId,
                        'bog_order_id' => $bogOrderId,
                        'raw_message' => $e->getMessage(),
                    ]);
                    $payment->setAdditionalInformation('preauth_approved', false);
                    $payment->setAdditionalInformation('capture_status', 'captured');
                    $this->orderRepository->save($order);

                    $this->messageManager->addSuccessMessage(
                        (string) __('Payment was already captured.')
                    );
                } else {
                    $this->logger->error('BOG HTTP error mapped to user copy', [
                        'context' => 'admin.capture',
                        'order_id' => $orderId,
                        'bog_order_id' => $bogOrderId,
                        'exception_class' => $e::class,
                        'raw_message' => $e->getMessage(),
                    ]);
                    $friendly = $this->userFacingErrorMapper->toLocalizedException(
                        0,
                        $e->getMessage(),
                    );
                    $this->messageManager->addErrorMessage($friendly->getMessage());
                }
            }
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

    /**
     * Returns true when the BOG message mentions "already" + any of the
     * supplied lowercase keyword stems. Keeps message-text routing in one
     * place so the BogApiException catch arm stays readable.
     *
     * @param array<int, string> $stems
     */
    private function messageMentionsAlready(string $loweredMessage, array $stems): bool
    {
        if (!str_contains($loweredMessage, 'already')) {
            return false;
        }
        foreach ($stems as $stem) {
            if (str_contains($loweredMessage, $stem)) {
                return true;
            }
        }
        return false;
    }
}
