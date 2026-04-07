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
     * Handle response from BOG Payments create-order API.
     *
     * New API response format:
     * {
     *   "id": "...",
     *   "_links": {
     *     "redirect": {"href": "..."},
     *     "details": {"href": "..."}
     *   }
     * }
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

        // New API: _links.redirect.href
        if (isset($response['_links']['redirect']['href'])) {
            $payment->setAdditionalInformation(
                'bog_redirect_url',
                (string) $response['_links']['redirect']['href']
            );
        }

        // Store details link for status lookups
        if (isset($response['_links']['details']['href'])) {
            $payment->setAdditionalInformation(
                'bog_details_url',
                (string) $response['_links']['details']['href']
            );
        }

        $payment->setAdditionalInformation('bog_status', (string) ($response['status'] ?? 'created'));
        $payment->setIsTransactionPending(true);
        $payment->setIsTransactionClosed(false);
    }
}
