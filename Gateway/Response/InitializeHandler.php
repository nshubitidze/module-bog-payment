<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

class InitializeHandler implements HandlerInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
    ) {
    }

    /**
     * Handle response from BOG create-order API.
     * Store BOG order ID and payment URL in additional information.
     *
     * @param array<string, mixed> $handlingSubject
     * @param array<string, mixed> $response
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = $this->subjectReader->readPayment($handlingSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        if (isset($response['id'])) {
            $payment->setAdditionalInformation('bog_order_id', (string) $response['id']);
        }

        if (isset($response['payment_id'])) {
            $payment->setAdditionalInformation('bog_payment_id', (string) $response['payment_id']);
        }

        if (isset($response['links']['redirect']['href'])) {
            $payment->setAdditionalInformation(
                'bog_redirect_url',
                (string) $response['links']['redirect']['href']
            );
        }

        if (isset($response['_links']['redirect']['href'])) {
            $payment->setAdditionalInformation(
                'bog_redirect_url',
                (string) $response['_links']['redirect']['href']
            );
        }

        $payment->setAdditionalInformation('bog_status', (string) ($response['status'] ?? 'created'));
        $payment->setIsTransactionPending(true);
        $payment->setIsTransactionClosed(false);
    }
}
