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
use Shubo\BogPayment\Gateway\Http\Client\ReversalClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Admin controller to void a pending or pre-authorized BOG payment.
 *
 * BUG-BOG-5: under `payment_action_mode=manual` (preauth), this controller
 * now calls the BOG reversal API to release the customer's authorization
 * hold before cancelling the Magento order. Under the default
 * `payment_action_mode=automatic`, no hold exists, so the controller skips
 * the API call and just cancels the order. Mode-check ordering and failure
 * taxonomy are defined in `docs/design-bog-5-reversal-api-2026-05-03.md`.
 */
class VoidPayment extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_BogPayment::void';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly ReversalClient $reversalClient,
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
            // as a generic void backdoor.
            /** @var Payment|null $payment */
            $payment = $order->getPayment();
            if ($payment === null || $payment->getMethod() !== ConfigProvider::CODE) {
                throw new LocalizedException(__('Invalid payment method for this action.'));
            }

            $storeId = (int) $order->getStoreId();
            $mode = $this->config->getPaymentActionMode($storeId);

            // Guard 2 — payment action mode. Under automatic capture there
            // is no preauth hold to release, so we skip the BOG API call.
            // Under manual capture (preauth), we MUST call the reversal
            // endpoint or the customer's hold lingers up to 30 days.
            if ($mode !== 'manual') {
                $this->logger->info('BOG void: skipping reversal API (automatic mode)', [
                    'order_id' => $orderId,
                    'mode' => $mode,
                ]);
                $payment->setAdditionalInformation('preauth_approved', false);

                $order->cancel();
                $order->addCommentToStatusHistory(
                    (string) __('Payment voided by admin. Order cancelled. Card was not charged.')
                );
                $this->orderRepository->save($order);

                $this->messageManager->addSuccessMessage(
                    (string) __('Payment has been voided and order cancelled.')
                );

                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            // Guard 3 — bog_order_id presence (preauth path only).
            $bogOrderId = (string) $payment->getAdditionalInformation('bog_order_id');
            if ($bogOrderId === '') {
                throw new LocalizedException(
                    __('No BOG order ID found on this order. Cannot reverse the authorization.')
                );
            }

            // Guard 4 — local idempotency. If a prior click already cancelled,
            // skip the BOG round-trip; BOG would respond correctly either way
            // but we save a token + network round-trip per accidental click.
            if ($payment->getAdditionalInformation('cancel_status') === 'cancelled') {
                $this->logger->info('BOG void: cancel already recorded locally, skipping API', [
                    'order_id' => $orderId,
                    'bog_order_id' => $bogOrderId,
                ]);
                $this->messageManager->addSuccessMessage(
                    (string) __('Authorization was already released. Order remains cancelled.')
                );
                return $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);
            }

            try {
                $response = $this->reversalClient->reverse(
                    bogOrderId: $bogOrderId,
                    storeId: $storeId,
                    description: (string) __('Void by admin for order %1', $order->getIncrementId()),
                );

                $payment->setAdditionalInformation('preauth_approved', false);
                $payment->setAdditionalInformation('cancel_status', 'cancelled');
                $payment->setAdditionalInformation(
                    'bog_cancel_action_id',
                    (string) ($response['action_id'] ?? '')
                );

                $order->cancel();
                $order->addCommentToStatusHistory(
                    (string) __(
                        'BOG authorization released. Order cancelled. (BOG action_id=%1)',
                        (string) ($response['action_id'] ?? 'n/a'),
                    )
                );
                $this->orderRepository->save($order);

                $this->messageManager->addSuccessMessage(
                    (string) __('Authorization released. Order cancelled.')
                );
            } catch (BogApiException $e) {
                // BOG returned non-2xx OR transport failure. Three sub-paths:
                //   a) "already cancelled" (BOG idempotent reply) → benign;
                //      log WARNING, cancel order locally, admin sees the
                //      success-style copy. The hold is gone either way.
                //   b) "already captured" → cannot void; do NOT cancel order;
                //      admin sees actionable copy ("refund instead").
                //   c) anything else (network 5xx, generic 4xx) → fail-closed:
                //      do NOT cancel order; admin sees retry copy. Cron
                //      reconciler will pick up on next pass.
                $rawText = strtolower($e->getMessage());
                $isAlreadyCancelled = $this->messageMentionsAlready($rawText, ['cancel', 'reverse', 'release', 'declin']);
                $isAlreadyCaptured = $this->messageMentionsAlready($rawText, ['captur', 'complet', 'approve']);

                if ($isAlreadyCancelled && !$isAlreadyCaptured) {
                    $this->logger->warning('BOG reversal: already cancelled (idempotent)', [
                        'context' => 'admin.void',
                        'order_id' => $orderId,
                        'bog_order_id' => $bogOrderId,
                        'raw_message' => $e->getMessage(),
                    ]);
                    $payment->setAdditionalInformation('preauth_approved', false);
                    $payment->setAdditionalInformation('cancel_status', 'cancelled');

                    $order->cancel();
                    $order->addCommentToStatusHistory(
                        (string) __('BOG authorization was already released. Order cancelled.')
                    );
                    $this->orderRepository->save($order);

                    $this->messageManager->addSuccessMessage(
                        (string) __('Authorization was already released. Order cancelled.')
                    );
                } elseif ($isAlreadyCaptured) {
                    $this->logger->warning('BOG reversal: already captured — cannot void', [
                        'context' => 'admin.void',
                        'order_id' => $orderId,
                        'bog_order_id' => $bogOrderId,
                        'raw_message' => $e->getMessage(),
                    ]);
                    $this->messageManager->addErrorMessage(
                        (string) __(
                            'Cannot void: the authorization has already been captured. Issue a refund instead.'
                        )
                    );
                } else {
                    $this->logger->error('BOG reversal failed', [
                        'context' => 'admin.void',
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
            // LocalizedException is by Magento convention author-safe to show.
            $this->logger->error('BOG void payment — LocalizedException', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            // Bland fallback so raw exception text never leaks to admin UI.
            $this->logger->error('BOG void payment failed', [
                'order_id' => $orderId,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Void failed. See shubo_bog_payment.log for details.')
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
