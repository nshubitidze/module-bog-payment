<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron\Reconciler;

use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Cron\Reconciler\StatusHandler\ApprovedHandler;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;
use Shubo\BogPayment\Service\PaymentLock;

/**
 * BUG-BOG-11b quote-recovery service. Extracted verbatim from
 * {@see \Shubo\BogPayment\Cron\PendingOrderReconciler}'s findPendingQuotes /
 * reconcileQuote / materializeQuote / deactivateQuote methods during the
 * 2026-05-01 god-class split.
 *
 * Owns the BUG-BOG-11b path end-to-end: scans `quote` for pending
 * BOG-method quotes carrying a bog_order_id, polls BOG, and either
 * materializes the quote into a Magento order (calling the shared
 * ApprovedHandler) or deactivates the quote on TTL/terminal-failure.
 *
 * Wraps the BUG-BOG-6 PaymentLock around the materialize path; the
 * transaction boundary at materializeQuote is preserved.
 */
class QuoteReconciler
{
    private const MAX_QUOTES_PER_RUN = 50;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StatusClient $statusClient,
        private readonly CartManagementInterface $cartManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly PaymentLock $paymentLock,
        private readonly ApprovedHandler $approved,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly int $quoteTtlHours,
    ) {
    }

    /**
     * BUG-BOG-11b: find pending quotes for our payment method that carry a
     * bog_order_id — these are customers who returned from BOG while the
     * bank still said `in_progress` (no Magento order placed yet).
     *
     * @return array<int, array{quote_id: int, bog_order_id: string, age_hours: float}>
     */
    public function findPendingQuotes(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $quoteTable = $this->resourceConnection->getTableName('quote');
            $paymentTable = $this->resourceConnection->getTableName('quote_payment');

            $select = $connection->select()
                ->from(
                    ['q' => $quoteTable],
                    ['quote_id' => 'entity_id', 'updated_at' => 'updated_at']
                )
                ->join(
                    ['qp' => $paymentTable],
                    'qp.quote_id = q.entity_id',
                    ['additional_information']
                )
                ->where('q.is_active = ?', 1)
                ->where('qp.method = ?', ConfigProvider::CODE)
                ->where('qp.additional_information LIKE ?', '%"bog_order_id":"%')
                ->limit(self::MAX_QUOTES_PER_RUN);

            $rows = $connection->fetchAll($select);
        } catch (\Throwable $e) {
            $this->logger->error('BOG reconciler: quote scan failed', [
                'exception' => $e->getMessage(),
            ]);
            return [];
        }

        $now = new \DateTimeImmutable();
        $payloads = [];

        foreach ($rows as $row) {
            $additional = is_string($row['additional_information'])
                ? json_decode($row['additional_information'], true)
                : null;
            if (!is_array($additional) || empty($additional['bog_order_id'])) {
                continue;
            }

            $updatedAt = isset($row['updated_at']) && is_string($row['updated_at'])
                ? new \DateTimeImmutable($row['updated_at'])
                : $now;
            $ageSeconds = $now->getTimestamp() - $updatedAt->getTimestamp();
            $ageHours = $ageSeconds / 3600;

            $payloads[] = [
                'quote_id' => (int) $row['quote_id'],
                'bog_order_id' => (string) $additional['bog_order_id'],
                'age_hours' => (float) $ageHours,
            ];
        }

        return $payloads;
    }

    /**
     * Reconcile a single quote: check BOG status; on terminal success call
     * CartManagementInterface::placeOrder + run the approved flow. On
     * terminal failure or TTL expiry, deactivate the quote so the customer
     * starts fresh next time.
     *
     * @param array{quote_id: int, bog_order_id: string, age_hours: float} $payload
     */
    public function reconcileQuote(array $payload): void
    {
        $quoteId = $payload['quote_id'];
        $bogOrderId = $payload['bog_order_id'];

        // TTL cleanup first — don't burn BOG API quota on stale quotes.
        if ($payload['age_hours'] >= (float) $this->quoteTtlHours) {
            $this->deactivateQuote($quoteId, 'ttl_exceeded');
            return;
        }

        try {
            $response = $this->statusClient->checkStatus($bogOrderId, 0);
        } catch (BogApiException $e) {
            $this->logger->error('BOG reconciler: quote status API error', [
                'quote_id' => $quoteId,
                'bog_order_id' => $bogOrderId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $orderStatusKey = strtolower(
            (string) ($response['order_status']['key'] ?? ($response['status'] ?? ''))
        );

        $this->paymentLock->withLock($bogOrderId, function () use (
            $quoteId,
            $bogOrderId,
            $orderStatusKey,
            $response
        ): void {
            match ($orderStatusKey) {
                'completed', 'captured' => $this->materializeQuote($quoteId, $bogOrderId, $response),
                'expired' => $this->deactivateQuote($quoteId, 'bog_expired'),
                'error', 'rejected', 'declined' => $this->deactivateQuote($quoteId, 'bog_' . $orderStatusKey),
                'created', 'in_progress' => $this->logger->info(
                    'BOG reconciler: quote still in progress, will retry',
                    ['quote_id' => $quoteId, 'bog_order_id' => $bogOrderId]
                ),
                default => $this->logger->warning(
                    'BOG reconciler: unknown quote status',
                    ['quote_id' => $quoteId, 'bog_status' => $orderStatusKey]
                ),
            };
        });
    }

    /**
     * Materialize a quote → Magento order and run the approved capture flow.
     *
     * @param array<string, mixed> $response
     */
    private function materializeQuote(int $quoteId, string $bogOrderId, array $response): void
    {
        try {
            $quote = $this->cartRepository->get($quoteId);
            if ($quote instanceof \Magento\Quote\Model\Quote) {
                $quotePayment = $quote->getPayment();
                if ($quotePayment !== null) {
                    $quotePayment->setMethod(ConfigProvider::CODE);
                    $quotePayment->setAdditionalInformation('bog_order_id', $bogOrderId);
                    $quotePayment->setAdditionalInformation('bog_status', 'completed');
                }
            }
            $this->cartRepository->save($quote);

            $orderId = $this->cartManagement->placeOrder((int) $quote->getId());
            $order = $this->orderRepository->get((int) $orderId);
            if (!$order instanceof Order) {
                $this->logger->error('BOG reconciler: materialized entity is not a concrete Order', [
                    'quote_id' => $quoteId,
                    'bog_order_id' => $bogOrderId,
                ]);
                return;
            }
            /** @var Payment $payment */
            $payment = $order->getPayment();
            if ($payment === null) {
                $this->logger->error('BOG reconciler: materialized order has no payment', [
                    'order_id' => $order->getIncrementId(),
                ]);
                return;
            }

            // Store the bog_order_id before handleApproved reads it.
            $payment->setAdditionalInformation('bog_order_id', $bogOrderId);

            $connection = $this->resourceConnection->getConnection();
            $connection->beginTransaction();
            try {
                $this->approved->handle($order, $payment, $response);
                $connection->commit();
            } catch (\Exception $e) {
                $connection->rollBack();
                throw $e;
            }

            $this->logger->info('BOG reconciler: materialized order from pending quote', [
                'quote_id' => $quoteId,
                'order_id' => $order->getIncrementId(),
                'bog_order_id' => $bogOrderId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('BOG reconciler: failed to materialize order from quote', [
                'quote_id' => $quoteId,
                'bog_order_id' => $bogOrderId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deactivate a stale/failed pending quote. Uses direct DB to avoid
     * touching full quote totals/items in a cron context.
     */
    private function deactivateQuote(int $quoteId, string $reason): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $quoteTable = $this->resourceConnection->getTableName('quote');
            $connection->update(
                $quoteTable,
                ['is_active' => 0],
                ['entity_id = ?' => $quoteId]
            );

            $this->logger->info('BOG reconciler: deactivated stale quote', [
                'quote_id' => $quoteId,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('BOG reconciler: failed to deactivate quote', [
                'quote_id' => $quoteId,
                'reason' => $reason,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
