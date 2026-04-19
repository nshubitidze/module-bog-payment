<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Model\Order\Payment;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

class RefundRequestBuilder implements BuilderInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
    ) {
    }

    /**
     * Build the BOG refund request payload.
     *
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $amount = $this->subjectReader->readAmount($buildSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        $bogOrderId = $payment->getAdditionalInformation('bog_order_id') ?? '';

        // BUG-BOG-17: use bcmath truncation to 2-decimal scale rather than
        // number_format's half-even rounding so the refund wire amount stays
        // consistent with the bcmath chain used across Commission + Payout.
        return [
            'order_id' => $bogOrderId,
            'amount' => bcadd((string) $amount, '0', 2),
        ];
    }
}
