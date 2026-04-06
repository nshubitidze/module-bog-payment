<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

class RefundHandler implements HandlerInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
    ) {
    }

    /**
     * Handle response from BOG refund API.
     *
     * @param array<string, mixed> $handlingSubject
     * @param array<string, mixed> $response
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        if (isset($response['status'])) {
            $payment->setAdditionalInformation('bog_refund_status', (string) $response['status']);
        }

        if (isset($response['refund_id'])) {
            $payment->setTransactionId((string) $response['refund_id']);
        }

        $payment->setIsTransactionClosed(true);
    }
}
