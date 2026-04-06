<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Request;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Api\Data\SplitPaymentDataInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

class SplitDataBuilder implements BuilderInterface
{
    public function __construct(
        private readonly SubjectReader $subjectReader,
        private readonly Config $config,
        private readonly EventManager $eventManager,
        private readonly SplitPaymentDataInterface $splitPaymentData,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build split payment data if enabled and populated by observers.
     *
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     */
    public function build(array $buildSubject): array
    {
        if (!$this->config->isSplitEnabled()) {
            return [];
        }

        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $order = $paymentDO->getOrder();

        // Reset any previous split data
        $this->splitPaymentData->reset();

        // Dispatch event so observers can populate split payment data
        $this->eventManager->dispatch('shubo_bog_payment_split_before', [
            'order' => $order,
            'payment' => $paymentDO->getPayment(),
            'split_payment_data' => $this->splitPaymentData,
        ]);

        if (!$this->splitPaymentData->hasSplitPayments()) {
            $this->logger->info('BOG split payments enabled but no split data provided for order', [
                'order_id' => $order->getOrderIncrementId(),
            ]);
            return [];
        }

        $splitPayments = [];
        foreach ($this->splitPaymentData->getSplitPayments() as $split) {
            $splitPayments[] = [
                'iban' => $split['iban'],
                'percent' => $split['percent'],
                'description' => $split['description'],
            ];
        }

        $this->logger->info('BOG split payment data added to request', [
            'order_id' => $order->getOrderIncrementId(),
            'split_count' => count($splitPayments),
        ]);

        return [
            'config' => [
                'split' => [
                    'split_payments' => $splitPayments,
                ],
            ],
        ];
    }
}
