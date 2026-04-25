<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Cron;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Collection as CreditmemoCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Cron\PendingOrderReconciler;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Service\PaymentLock;

/**
 * BUG-BOG-11b: the cron reconciler must cover quote-only state, not just
 * stuck Magento orders. When a customer returns from BOG while the bank
 * says `in_progress`, ReturnAction::handlePending persists bog_order_id
 * on the quote and does NOT place a Magento order. If BOG then terminates
 * the payment successfully in the background, neither Callback (no
 * Magento order to find) nor the prior reconciler (no Magento order to
 * reconcile) can recover — this test locks in the new quote-scan path.
 *
 * BUG-BOG-6 is also exercised here: the cron's reconcile path runs inside
 * the PaymentLock.
 */
class PendingOrderReconcilerTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private SortOrderBuilder&MockObject $sortOrderBuilder;
    private StatusClient&MockObject $statusClient;
    private Config&MockObject $config;
    private OrderSender&MockObject $orderSender;
    private LoggerInterface&MockObject $logger;
    private ResourceConnection&MockObject $resourceConnection;
    private AppState&MockObject $appState;
    private PaymentLock&MockObject $paymentLock;
    private CartManagementInterface&MockObject $cartManagement;
    private CartRepositoryInterface&MockObject $cartRepository;
    private CreditmemoFactory&MockObject $creditmemoFactory;
    private CreditmemoManagementInterface&MockObject $creditmemoManagement;

    private AdapterInterface&MockObject $adapter;

    /** @var list<Order> */
    private array $orderSearchReturns = [];

    protected function setUp(): void
    {
        $this->orderSearchReturns = [];
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $this->statusClient = $this->createMock(StatusClient::class);
        $this->config = $this->createMock(Config::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->appState = $this->createMock(AppState::class);
        $this->paymentLock = $this->createMock(PaymentLock::class);
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->creditmemoFactory = $this->createMock(CreditmemoFactory::class);
        $this->creditmemoManagement = $this->createMock(CreditmemoManagementInterface::class);

        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->adapter);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn(string $t): string => $t);

        // Default: withLock runs the callable and returns its result.
        $this->paymentLock->method('withLock')->willReturnCallback(
            static fn(string $key, callable $fn): mixed => $fn()
        );

        // Default: no stuck Magento orders — order path is short-circuited.
        $this->primeOrderSearch(returns: []);
        $this->installOrderSearchStub();

        $this->sortOrderBuilder->method('setField')->willReturnSelf();
        $this->sortOrderBuilder->method('setAscendingDirection')->willReturnSelf();
        $this->sortOrderBuilder->method('create')->willReturn($this->createMock(SortOrder::class));
    }

    /**
     * BUG-BOG-11b happy path: a pending quote carrying bog_order_id, BOG
     * says `completed`, reconciler places the order and runs the approved
     * capture flow.
     */
    public function testMaterializesOrderFromStaleQuoteOnTerminalSuccess(): void
    {
        // Stage quote scan → one row with bog_order_id in serialized JSON.
        $row = [
            'quote_id' => 55,
            'updated_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'additional_information' => json_encode(
                ['method_title' => 'BOG', 'bog_order_id' => 'BOG-MAT'],
                JSON_THROW_ON_ERROR
            ),
        ];
        $this->primeQuoteScan([$row]);

        $this->statusClient->method('checkStatus')->with('BOG-MAT')->willReturn([
            'order_status' => ['key' => 'completed'],
        ]);

        // Quote + quote payment must be re-saved + placeOrder invoked.
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('setMethod')->willReturnSelf();
        $quotePayment->method('setAdditionalInformation')->willReturnSelf();
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(55);
        $quote->method('getPayment')->willReturn($quotePayment);
        $this->cartRepository->method('get')->with(55)->willReturn($quote);
        $this->cartRepository->method('save')->willReturn($quote);

        $this->cartManagement->expects(self::once())->method('placeOrder')
            ->with(55)->willReturn(777);

        // The new Magento order goes into handleApproved (capture path).
        $orderPayment = $this->createMock(Payment::class);
        $orderPayment->method('setAdditionalInformation')->willReturnSelf();
        $orderPayment->method('setTransactionId')->willReturnSelf();
        $orderPayment->method('setIsTransactionPending')->willReturnSelf();
        $orderPayment->method('setIsTransactionClosed')->willReturnSelf();
        $orderPayment->method('getAdditionalInformation')->willReturn('BOG-MAT');
        $orderPayment->method('registerCaptureNotification')->willReturnSelf();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(777);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getIncrementId')->willReturn('000000101');
        $order->method('getGrandTotal')->willReturn(50.0);
        $order->method('getBaseGrandTotal')->willReturn(50.0);
        $order->method('getPayment')->willReturn($orderPayment);
        $order->method('addCommentToStatusHistory')->willReturnSelf();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();

        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects(self::atLeastOnce())->method('save');
        $this->config->method('isPreauth')->willReturn(false);

        $this->adapter->expects(self::never())
            ->method('update');

        $this->buildReconciler()->execute();
    }

    /**
     * BUG-BOG-11b failure-terminal: BOG says `expired` for a pending quote —
     * reconciler deactivates the quote so the customer can retry.
     */
    public function testDeactivatesQuoteWhenBogSaysExpired(): void
    {
        $row = [
            'quote_id' => 66,
            'updated_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'additional_information' => json_encode(
                ['bog_order_id' => 'BOG-EXP'],
                JSON_THROW_ON_ERROR
            ),
        ];
        $this->primeQuoteScan([$row]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'expired'],
        ]);

        $this->cartManagement->expects(self::never())->method('placeOrder');

        // Deactivation is an UPDATE to quote.is_active = 0.
        $updates = [];
        $this->adapter->method('update')->willReturnCallback(
            function (string $table, array $data, array $where) use (&$updates) {
                $updates[] = compact('table', 'data', 'where');
                return 1;
            }
        );

        $this->buildReconciler()->execute();

        self::assertNotEmpty($updates, 'Expected at least one UPDATE call to deactivate the quote');
        self::assertSame(0, $updates[0]['data']['is_active']);
        self::assertSame(66, $updates[0]['where']['entity_id = ?']);
    }

    /**
     * BUG-BOG-11b TTL guard: a quote that has been hanging around for
     * longer than the configured TTL gets deactivated even if BOG is still
     * in_progress — orphan quotes should not live forever.
     */
    public function testDeactivatesQuoteWhenTtlExceeded(): void
    {
        $row = [
            'quote_id' => 77,
            // 48 h old; default TTL is 24 h.
            'updated_at' => (new \DateTimeImmutable('-48 hours'))->format('Y-m-d H:i:s'),
            'additional_information' => json_encode(
                ['bog_order_id' => 'BOG-OLD'],
                JSON_THROW_ON_ERROR
            ),
        ];
        $this->primeQuoteScan([$row]);

        // TTL guard runs BEFORE the status API call; never hits BOG.
        $this->statusClient->expects(self::never())->method('checkStatus');

        $updates = [];
        $this->adapter->method('update')->willReturnCallback(
            function (string $table, array $data, array $where) use (&$updates) {
                $updates[] = compact('table', 'data', 'where');
                return 1;
            }
        );

        $this->buildReconciler()->execute();

        self::assertCount(1, $updates);
        self::assertSame(77, $updates[0]['where']['entity_id = ?']);
    }

    /**
     * BUG-BOG-11b non-terminal: BOG still says in_progress for a fresh
     * pending quote — reconciler logs and continues (no placeOrder, no
     * deactivate). The next tick will retry.
     */
    public function testSkipsQuoteThatIsStillInProgress(): void
    {
        $row = [
            'quote_id' => 88,
            'updated_at' => (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
            'additional_information' => json_encode(
                ['bog_order_id' => 'BOG-PROG'],
                JSON_THROW_ON_ERROR
            ),
        ];
        $this->primeQuoteScan([$row]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'in_progress'],
        ]);

        $this->cartManagement->expects(self::never())->method('placeOrder');
        $this->adapter->expects(self::never())->method('update');

        $this->buildReconciler()->execute();
    }

    /**
     * BUG-BOG-12: when a BOG order is reported as `refunded`, reconciler
     * creates an offline creditmemo for the refund amount and moves the
     * order into a closed/refunded state. Idempotent: if a creditmemo
     * already exists with the matching txn reference, skip.
     *
     * Matrix tested here mirrors TBC BUG-6 handleReversed state machine:
     *
     *   bog_status | magento_state                | expected action
     *   -----------+------------------------------+-----------------------
     *   refunded   | processing (full)            | creditmemo via factory
     *   refunded   | closed                       | idempotent skip
     *   reversed   | pending_payment              | $order->cancel()
     *   reversed   | processing (full)            | STATE_CLOSED + comment
     *   reversed   | processing (partial)         | comment only, no state
     *   chargeback | processing (full)            | STATE_CLOSED + tag comment
     *   rejected   | pending_payment              | $order->cancel()
     *
     * The cron scan is extended to pick up post-capture orders that still
     * carry a bog_order_id (so an out-of-band BOG refund can reach us).
     */
    public function testRefundedProcessingOrderCreatesCreditmemo(): void
    {
        $order = $this->primeCapturedOrder(
            incrementId: '000000301',
            bogOrderId: 'BOG-REF',
            state: Order::STATE_PROCESSING,
            grandTotalMinor: 5000,
            hasCreditmemos: false,
        );
        $this->primeOrderSearch(returns: [$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'refunded'],
            'refund_amount' => '50.00',
        ]);

        // CreditmemoFactory::createByOrder must be called; refund() must run.
        // setAutomaticallyCreated is a magic setter — must use addMethods.
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->addMethods(['setAutomaticallyCreated'])
            ->onlyMethods(['addComment'])
            ->getMock();
        $this->creditmemoFactory->expects(self::once())
            ->method('createByOrder')
            ->with($order)
            ->willReturn($creditmemo);
        $creditmemo->expects(self::atLeastOnce())->method('setAutomaticallyCreated')->willReturnSelf();
        $creditmemo->expects(self::atLeastOnce())->method('addComment')->willReturnSelf();

        $this->creditmemoManagement->expects(self::once())
            ->method('refund')
            ->with($creditmemo, true)
            ->willReturn($creditmemo);

        $this->buildReconciler()->execute();
    }

    public function testRefundedAlreadyHasCreditmemoIsIdempotent(): void
    {
        $order = $this->primeCapturedOrder(
            incrementId: '000000302',
            bogOrderId: 'BOG-REF2',
            state: Order::STATE_PROCESSING,
            grandTotalMinor: 5000,
            hasCreditmemos: true,
        );
        $this->primeOrderSearch(returns: [$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'refunded'],
            'refund_amount' => '50.00',
        ]);

        $this->creditmemoFactory->expects(self::never())->method('createByOrder');
        $this->creditmemoManagement->expects(self::never())->method('refund');

        $this->buildReconciler()->execute();
    }

    public function testReversedPendingPaymentOrderIsCancelled(): void
    {
        $order = $this->primeCapturedOrder(
            incrementId: '000000303',
            bogOrderId: 'BOG-REV1',
            state: Order::STATE_PENDING_PAYMENT,
            grandTotalMinor: 5000,
            hasCreditmemos: false,
        );
        $order->expects(self::atLeastOnce())->method('cancel');
        $this->primeOrderSearch(returns: [$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'reversed'],
        ]);

        $this->creditmemoFactory->expects(self::never())->method('createByOrder');
        $this->buildReconciler()->execute();
    }

    public function testReversedProcessingFullClosesOrder(): void
    {
        $order = $this->primeCapturedOrder(
            incrementId: '000000304',
            bogOrderId: 'BOG-REV2',
            state: Order::STATE_PROCESSING,
            grandTotalMinor: 5000,
            hasCreditmemos: false,
        );
        $order->expects(self::atLeastOnce())->method('setState')->willReturnSelf();
        $order->expects(self::atLeastOnce())->method('setStatus')->willReturnSelf();
        $order->expects(self::never())->method('cancel');
        $this->primeOrderSearch(returns: [$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'reversed'],
            // full amount equal to grand total — integer tetri comparison.
            'reverse_amount' => '50.00',
        ]);

        $this->creditmemoFactory->expects(self::never())->method('createByOrder');
        $this->buildReconciler()->execute();
    }

    public function testReversedProcessingPartialLeavesStateUnchanged(): void
    {
        $order = $this->primeCapturedOrder(
            incrementId: '000000305',
            bogOrderId: 'BOG-REV3',
            state: Order::STATE_PROCESSING,
            grandTotalMinor: 5000,
            hasCreditmemos: false,
        );
        // State MUST NOT change on partial reversal.
        $order->expects(self::never())->method('setState');
        $order->expects(self::never())->method('cancel');
        $this->primeOrderSearch(returns: [$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'reversed'],
            // 25.00 < 50.00 grand total → partial
            'reverse_amount' => '25.00',
        ]);

        $this->buildReconciler()->execute();
    }

    public function testChargebackClosesOrderWithTaggedComment(): void
    {
        $order = $this->primeCapturedOrder(
            incrementId: '000000306',
            bogOrderId: 'BOG-CHG',
            state: Order::STATE_PROCESSING,
            grandTotalMinor: 5000,
            hasCreditmemos: false,
        );
        $order->expects(self::atLeastOnce())->method('setState')->willReturnSelf();
        $capturedComments = [];
        $order->method('addCommentToStatusHistory')
            ->willReturnCallback(function ($c) use (&$capturedComments, $order) {
                $capturedComments[] = (string) $c;
                return $order;
            });
        $this->primeOrderSearch(returns: [$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'chargeback'],
        ]);

        $this->buildReconciler()->execute();

        $joined = implode(' ', $capturedComments);
        self::assertStringContainsString('chargeback', strtolower($joined));
    }

    public function testRejectedPendingPaymentOrderIsCancelled(): void
    {
        // Pre-BOG-BOG-12 behaviour was already cancel-on-rejected for the
        // pending-payment scan; add a regression guard so the refactor doesn't
        // lose it.
        $order = $this->primeCapturedOrder(
            incrementId: '000000307',
            bogOrderId: 'BOG-REJ',
            state: Order::STATE_PENDING_PAYMENT,
            grandTotalMinor: 5000,
            hasCreditmemos: false,
        );
        $order->expects(self::atLeastOnce())->method('cancel');
        $this->primeOrderSearch(returns: [$order]);

        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'rejected'],
        ]);

        $this->buildReconciler()->execute();
    }

    /**
     * Build an order that carries a bog_order_id and has a given state +
     * grand_total (in minor units for integer-only comparison).
     */
    private function primeCapturedOrder(
        string $incrementId,
        string $bogOrderId,
        string $state,
        int $grandTotalMinor,
        bool $hasCreditmemos,
    ): Order&MockObject {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn(string $key): mixed => $key === 'bog_order_id' ? $bogOrderId : null
        );
        $payment->method('getMethod')->willReturn('shubo_bog');

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getId')->willReturn(100);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn($state);
        $order->method('getGrandTotal')->willReturn($grandTotalMinor / 100);
        $order->method('getOrderCurrencyCode')->willReturn('GEL');
        $order->method('getPayment')->willReturn($payment);
        $order->method('hasCreditmemos')->willReturn($hasCreditmemos);
        $order->method('addCommentToStatusHistory')->willReturnSelf();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();

        $creditmemoCollection = $this->createMock(CreditmemoCollection::class);
        $creditmemoCollection->method('getSize')->willReturn($hasCreditmemos ? 1 : 0);
        $order->method('getCreditmemosCollection')->willReturn($creditmemoCollection);

        return $order;
    }

    private function buildReconciler(): PendingOrderReconciler
    {
        return new PendingOrderReconciler(
            orderRepository: $this->orderRepository,
            searchCriteriaBuilder: $this->searchCriteriaBuilder,
            sortOrderBuilder: $this->sortOrderBuilder,
            statusClient: $this->statusClient,
            config: $this->config,
            orderSender: $this->orderSender,
            logger: $this->logger,
            resourceConnection: $this->resourceConnection,
            appState: $this->appState,
            paymentLock: $this->paymentLock,
            cartManagement: $this->cartManagement,
            cartRepository: $this->cartRepository,
            creditmemoFactory: $this->creditmemoFactory,
            creditmemoManagement: $this->creditmemoManagement,
        );
    }

    /**
     * Stub findPendingOrders to return a specific list (or empty).
     * Subsequent calls in the same test override the prior set via a
     * shared $this->orderSearchReturns property so PHPUnit's
     * willReturn-first-wins doesn't pin us to setUp's empty default.
     *
     * @param list<Order> $returns
     */
    private function primeOrderSearch(array $returns): void
    {
        $this->orderSearchReturns = $returns;
    }

    /**
     * Called once from setUp to install a single willReturnCallback that
     * routes getItems() back to the per-test property.
     */
    private function installOrderSearchStub(): void
    {
        $searchCriteria = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setSortOrders')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $list = $this->createMock(OrderSearchResultInterface::class);
        $list->method('getItems')->willReturnCallback(fn(): array => $this->orderSearchReturns);
        $this->orderRepository->method('getList')->willReturn($list);
    }

    /**
     * Stub the quote-scan SQL path to return specific rows.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function primeQuoteScan(array $rows): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $this->adapter->method('select')->willReturn($select);
        $this->adapter->method('fetchAll')->willReturn($rows);
    }
}
