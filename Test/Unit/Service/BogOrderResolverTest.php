<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Service\BogOrderResolver;

/**
 * Narrow unit tests for BogOrderResolver.
 *
 * Extracted from CallbackTest as part of the 2026-05-02 god-class split:
 *
 *   - JSON LIKE pin against sales_order_payment.additional_information
 *     (BUG-BOG-7) — moved verbatim from
 *     CallbackTest::testFindOrderByBogOrderIdQueriesPaymentAdditionalInformation.
 *   - JSON LIKE pin against quote_payment.additional_information
 *     (BUG-BOG-11b) — replaces previously implicit coverage exercised
 *     end-to-end inside testMaterializesOrderFromQuoteOnTerminalSuccess.
 *   - findOrder()'s increment_id-first branch (early return).
 *   - findOrder()'s fall-through path when increment_id misses.
 *   - materializeOrderFromQuote() -> cartManagement->placeOrder($quoteId)
 *     contract.
 */
class BogOrderResolverTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private OrderCollectionFactory&MockObject $orderCollectionFactory;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private CartManagementInterface&MockObject $cartManagement;
    private CartRepositoryInterface&MockObject $cartRepository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * BUG-BOG-7: when only `bog_order_id` is supplied (no external_order_id),
     * findOrder() must JOIN on sales_order_payment.additional_information via
     * a JSON LIKE pattern. Magento 2.4.8 stores additional_information as
     * JSON via Magento\Framework\Serialize\Serializer\Json; the LIKE pattern
     * `%"bog_order_id":"<id>"%` is what matches.
     *
     * Migrated verbatim from CallbackTest — adapted to instantiate the
     * resolver directly (no controller plumbing).
     */
    public function testFindOrderByBogOrderIdQueriesPaymentAdditionalInformation(): void
    {
        // No call expected: we skip the increment_id path when
        // external_order_id is empty.
        $this->orderCollectionFactory->expects(self::never())->method('create');

        // DB connection must be asked the JSON LIKE query.
        $adapter = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($adapter);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn(string $t): string => $t);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $adapter->method('select')->willReturn($select);

        // The crucial assertion: the query is bound with a LIKE pattern that
        // references the bog_order_id AND the sales_order_payment table.
        $adapter->expects(self::atLeastOnce())
            ->method('fetchOne')
            ->willReturnCallback(function (Select $s, array $binds) {
                self::assertArrayHasKey('needle', $binds);
                self::assertStringContainsString('BOG-XYZ', (string) $binds['needle']);
                self::assertStringStartsWith('%', (string) $binds['needle']);
                self::assertStringEndsWith('%', (string) $binds['needle']);
                return '777'; // parent_id (order entity_id)
            });

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(777);

        $this->orderRepository->expects(self::atLeastOnce())
            ->method('get')->with(777)->willReturn($order);

        $resolved = $this->buildResolver()->findOrder('', 'BOG-XYZ');

        self::assertSame($order, $resolved);
    }

    /**
     * BUG-BOG-11b: findQuoteIdByBogOrderId() pins the JSON LIKE pattern bound
     * against quote_payment.additional_information. Today this is exercised
     * end-to-end inside CallbackTest::testMaterializesOrderFromQuoteOnTerminalSuccess;
     * a narrow test pins the pattern explicitly so a future LIKE-shape
     * refactor can't go undetected.
     */
    public function testFindQuoteIdByBogOrderIdQueriesQuotePaymentJsonLike(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($adapter);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn(string $t): string => $t);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $adapter->method('select')->willReturn($select);

        $adapter->expects(self::atLeastOnce())
            ->method('fetchOne')
            ->willReturnCallback(function (Select $s, array $binds) {
                self::assertArrayHasKey('needle', $binds);
                self::assertSame('%"bog_order_id":"BOG-Q42"%', (string) $binds['needle']);
                return '55';
            });

        $quoteId = $this->buildResolver()->findQuoteIdByBogOrderId('BOG-Q42');

        self::assertSame(55, $quoteId);
    }

    /**
     * findOrder() prefers the increment_id path: when external_order_id
     * matches an existing order, the bog_order_id JSON LIKE is never
     * consulted. Today this is asserted only implicitly through
     * orchestration tests; the seam moved out of the controller, so a
     * narrow test stays here to keep the early-return verified.
     */
    public function testFindOrderUsesIncrementIdPathFirst(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(999);

        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->expects(self::once())
            ->method('create')->willReturn($collection);

        // No DB lookup must happen — increment_id path short-circuited.
        $this->resourceConnection->expects(self::never())->method('getConnection');
        $this->orderRepository->expects(self::never())->method('get');

        $resolved = $this->buildResolver()->findOrder('000000999', 'BOG-IGNORED');

        self::assertSame($order, $resolved);
    }

    /**
     * findOrder() falls through to the bog_order_id JSON LIKE path when the
     * increment_id lookup misses (returns a "first item" without an id).
     * Mirror of the early-return test, asserting the fall-through edge.
     */
    public function testFindOrderFallsBackToBogOrderIdWhenIncrementIdMisses(): void
    {
        // increment_id lookup returns an empty Order (no id).
        $emptyFirst = $this->createMock(Order::class);
        $emptyFirst->method('getId')->willReturn(null);
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($emptyFirst);
        $this->orderCollectionFactory->method('create')->willReturn($collection);

        // bog_order_id JSON LIKE then resolves to entity_id 555.
        $adapter = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($adapter);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn(string $t): string => $t);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $adapter->method('select')->willReturn($select);
        $adapter->method('fetchOne')->willReturn('555');

        $resolved = $this->createMock(Order::class);
        $resolved->method('getId')->willReturn(555);
        $this->orderRepository->expects(self::once())
            ->method('get')->with(555)->willReturn($resolved);

        $result = $this->buildResolver()->findOrder('000000555', 'BOG-FALLBACK');

        self::assertSame($resolved, $result);
    }

    /**
     * BUG-BOG-11b: materializeOrderFromQuote() must call
     * cartManagement->placeOrder($quoteId) with the resolved quoteId. This
     * is the load-bearing contract for the materialise path; the other
     * tests prove the surrounding lookup, this one pins the placeOrder call.
     */
    public function testMaterializeOrderFromQuoteCallsPlaceOrderWithQuoteId(): void
    {
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('setMethod')->willReturnSelf();
        $quotePayment->method('setAdditionalInformation')->willReturnSelf();

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(55);
        $quote->method('getPayment')->willReturn($quotePayment);

        $this->cartRepository->expects(self::once())
            ->method('get')->with(55)->willReturn($quote);
        $this->cartRepository->expects(self::once())
            ->method('save')->with($quote)->willReturn($quote);

        // The crucial assertion: placeOrder is called exactly once with
        // quote_id=55, returning a fresh order_id.
        $this->cartManagement->expects(self::once())
            ->method('placeOrder')->with(55)->willReturn(777);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000777');
        $this->orderRepository->expects(self::once())
            ->method('get')->with(777)->willReturn($order);

        $result = $this->buildResolver()->materializeOrderFromQuote(55, 'BOG-MAT');

        self::assertSame($order, $result);
    }

    private function buildResolver(): BogOrderResolver
    {
        return new BogOrderResolver(
            resourceConnection: $this->resourceConnection,
            orderCollectionFactory: $this->orderCollectionFactory,
            orderRepository: $this->orderRepository,
            cartManagement: $this->cartManagement,
            cartRepository: $this->cartRepository,
            logger: $this->logger,
        );
    }
}
