<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order\Payment;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

/**
 * Build the refund request for BOG Payments API.
 *
 * Wire body shape (see RefundClient — `order_id` is stripped before send):
 *   {"order_id": "BOG-XYZ", "amount": "10.00"}
 *
 * Session 8 Priority 1.1 — added a hard guard against an empty
 * `bog_order_id`. Previously this builder silently sent an empty
 * `order_id` field, which BOG accepted as a 404 response — leaving the
 * admin staring at a Magento generic exception with no actionable info.
 * Now the builder raises a `LocalizedException` describing exactly which
 * payment record is missing the BOG identifier, which propagates through
 * the creditmemo controller and surfaces as an admin toast with the
 * order's increment id baked in.
 */
class RefundRequestBuilder implements BuilderInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
    ) {
    }

    /**
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     * @throws LocalizedException when the payment is missing a `bog_order_id`.
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $amount = $this->subjectReader->readAmount($buildSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        $bogOrderId = (string) ($payment->getAdditionalInformation('bog_order_id') ?? '');

        if ($bogOrderId === '') {
            $orderIncrement = '(unknown)';
            $order = $paymentDO->getOrder();
            if ($order !== null) {
                $orderIncrement = (string) $order->getOrderIncrementId();
            }
            throw new LocalizedException(
                __(
                    'Cannot refund order %1: the bog_order_id is missing on the payment '
                    . 'record. The payment must have been captured via BOG before a '
                    . 'refund can be issued. Verify the order was paid through BOG; if '
                    . 'so, contact support to reconcile the missing identifier.',
                    $orderIncrement
                )
            );
        }

        // BUG-BOG-17: bcmath truncation to 2-decimal scale rather than
        // number_format's half-even rounding so the refund wire amount stays
        // consistent with the bcmath chain used across Commission + Payout.
        return [
            'order_id' => $bogOrderId,
            'amount' => bcadd((string) $amount, '0', 2),
        ];
    }
}
