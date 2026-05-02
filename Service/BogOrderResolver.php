<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;

/**
 * Resolves Magento orders from BOG callback identifiers.
 *
 * Encapsulates three intertwined lookup paths extracted from
 * Shubo\BogPayment\Controller\Payment\Callback (2026-05-02 god-class split):
 *
 *   - findOrder(): increment_id-first, fall back to bog_order_id JSON LIKE on
 *     sales_order_payment.additional_information (BUG-BOG-7).
 *   - findQuoteIdByBogOrderId(): bog_order_id JSON LIKE on
 *     quote_payment.additional_information, restricted to active quotes with
 *     this payment method (BUG-BOG-11b).
 *   - materializeOrderFromQuote(): place an Order from a pending quote that
 *     ReturnAction::handlePending left behind. Caller MUST hold PaymentLock.
 *
 * Log prefixes stay 'BOG callback: ...' because they identify the request
 * entry point, not the class — operators grep by entry point, not classname.
 */
class BogOrderResolver
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Find order by increment ID (external_order_id) or BOG order ID.
     */
    public function findOrder(string $externalOrderId, string $bogOrderId): ?Order
    {
        if ($externalOrderId !== '') {
            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToFilter('increment_id', $externalOrderId);
            $collection->setPageSize(1);

            /** @var Order|null $order */
            $order = $collection->getFirstItem();
            if ($order && $order->getId()) {
                return $order;
            }
        }

        if ($bogOrderId !== '') {
            $orderId = $this->findOrderIdByBogOrderId($bogOrderId);
            if ($orderId !== null) {
                try {
                    $resolved = $this->orderRepository->get($orderId);
                    if ($resolved instanceof Order) {
                        return $resolved;
                    }
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    $this->logger->warning('BOG callback: order_id in payment.additional_information did not resolve', [
                        'bog_order_id' => $bogOrderId,
                        'order_id' => $orderId,
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * BUG-BOG-7: resolve sales_order.entity_id by the bog_order_id embedded in
     * sales_order_payment.additional_information.
     *
     * Magento 2.4.8 stores this column as JSON via
     * Magento\Framework\Serialize\Serializer\Json, so the LIKE pattern targets
     * the JSON key/value representation. Example stored blob:
     *   {"method_title":"BOG","bog_order_id":"BOG-XYZ","bog_status":"created"}
     */
    private function findOrderIdByBogOrderId(string $bogOrderId): ?int
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('sales_order_payment');

            $select = $connection->select()
                ->from($table, ['parent_id'])
                ->where('additional_information LIKE :needle')
                ->limit(1);

            $needle = '%"bog_order_id":"' . $bogOrderId . '"%';
            $result = $connection->fetchOne($select, ['needle' => $needle]);

            if ($result === false || $result === null || $result === '') {
                return null;
            }

            return (int) $result;
        } catch (\Throwable $e) {
            $this->logger->error('BOG callback: failed to resolve order by bog_order_id', [
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * BUG-BOG-11b: resolve quote_id by the bog_order_id embedded in
     * quote_payment.additional_information, restricted to active quotes with
     * our payment method. Returns null on miss.
     */
    public function findQuoteIdByBogOrderId(string $bogOrderId): ?int
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $quoteTable = $this->resourceConnection->getTableName('quote');
            $paymentTable = $this->resourceConnection->getTableName('quote_payment');

            $select = $connection->select()
                ->from(['q' => $quoteTable], ['entity_id'])
                ->join(
                    ['qp' => $paymentTable],
                    'qp.quote_id = q.entity_id',
                    []
                )
                ->where('q.is_active = ?', 1)
                ->where('qp.method = ?', Config::METHOD_CODE)
                ->where('qp.additional_information LIKE :needle')
                ->limit(1);

            $needle = '%"bog_order_id":"' . $bogOrderId . '"%';
            $result = $connection->fetchOne($select, ['needle' => $needle]);

            if ($result === false || $result === null || $result === '') {
                return null;
            }

            return (int) $result;
        } catch (\Throwable $e) {
            $this->logger->error('BOG callback: failed to resolve quote by bog_order_id', [
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * BUG-BOG-11b: materialize a Magento order from a quote that was left
     * pending by ReturnAction::handlePending. Runs INSIDE the PaymentLock
     * so a concurrent customer-return cannot race.
     */
    public function materializeOrderFromQuote(int $quoteId, string $bogOrderId): ?Order
    {
        try {
            $quote = $this->cartRepository->get($quoteId);

            // CartInterface does not declare getPayment(), but the concrete
            // Quote model does. Narrow only when the concrete model is in
            // play so PHPStan stays happy and alternative implementations
            // don't blow up at runtime.
            if ($quote instanceof \Magento\Quote\Model\Quote) {
                $quotePayment = $quote->getPayment();
                if ($quotePayment !== null) {
                    $quotePayment->setMethod(Config::METHOD_CODE);
                    $quotePayment->setAdditionalInformation('bog_order_id', $bogOrderId);
                    $quotePayment->setAdditionalInformation('bog_status', 'completed');
                }
            }
            $this->cartRepository->save($quote);

            $orderId = $this->cartManagement->placeOrder((int) $quote->getId());
            $order = $this->orderRepository->get((int) $orderId);
            if (!$order instanceof Order) {
                return null;
            }

            $this->logger->info('BOG callback: materialized order from pending quote', [
                'quote_id' => $quoteId,
                'order_id' => $order->getIncrementId(),
                'bog_order_id' => $bogOrderId,
            ]);

            return $order;
        } catch (\Throwable $e) {
            $this->logger->error('BOG callback: failed to materialize order from quote', [
                'quote_id' => $quoteId,
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
