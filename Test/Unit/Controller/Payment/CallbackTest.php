<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Controller\Payment;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Controller\Payment\Callback;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Validator\CallbackValidator;
use Shubo\BogPayment\Service\PaymentLock;

/**
 * Tests covering three BOG demo-blocker bugs that converge in Callback.php:
 *
 *   BUG-BOG-6  — idempotency under concurrent Callback + ReturnAction + Cron.
 *                Wrap capture in a PaymentLock and re-check order state after
 *                lock acquisition; a second invocation must see `processing`
 *                and return ALREADY_PROCESSED without re-capturing.
 *
 *   BUG-BOG-7  — lookup by bog_order_id alone. When `external_order_id` is
 *                missing from the callback, the controller must join
 *                sales_order_payment.additional_information LIKE
 *                '%"bog_order_id":"<id>"%' to resolve the order.
 *
 *   BUG-BOG-11b — materialize the Magento order from a quote carrying
 *                 bog_order_id when the callback terminally confirms success
 *                 but no order exists yet.
 */
class CallbackTest extends TestCase
{
    private HttpRequest&MockObject $request;
    private RawFactory&MockObject $rawFactory;
    private CallbackValidator&MockObject $callbackValidator;
    private OrderCollectionFactory&MockObject $orderCollectionFactory;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private OrderSender&MockObject $orderSender;
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private ResourceConnection&MockObject $resourceConnection;
    private CartManagementInterface&MockObject $cartManagement;
    private CartRepositoryInterface&MockObject $cartRepository;
    private PaymentLock&MockObject $paymentLock;
    private Raw&MockObject $rawResult;

    private string $lastContents = '';
    private int $lastStatus = 0;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->rawFactory = $this->createMock(RawFactory::class);
        $this->callbackValidator = $this->createMock(CallbackValidator::class);
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->paymentLock = $this->createMock(PaymentLock::class);
        $this->rawResult = $this->createMock(Raw::class);

        $this->rawFactory->method('create')->willReturn($this->rawResult);
        $this->rawResult->method('setHttpResponseCode')->willReturnCallback(function ($code) {
            $this->lastStatus = (int) $code;
            return $this->rawResult;
        });
        $this->rawResult->method('setContents')->willReturnCallback(function ($c) {
            $this->lastContents = (string) $c;
            return $this->rawResult;
        });

        // Default lock behaviour: withLock executes the callable and returns
        // its result; acquire/release pair is no-op success. Override per-test
        // when proving specific lock semantics.
        $this->paymentLock->method('withLock')->willReturnCallback(
            static fn(string $key, callable $fn): mixed => $fn()
        );
    }

    /**
     * BUG-BOG-7: when the BOG callback arrives with only `order_id` (no
     * `external_order_id`), the controller must JOIN on
     * sales_order_payment.additional_information to find the Magento order.
     * Before the fix this was a stub that only logged and returned null.
     *
     * Magento 2.4.8 stores additional_information as JSON via
     * Magento\Framework\Serialize\Serializer\Json; the JSON LIKE pattern
     * `%"bog_order_id":"<id>"%` is what matches.
     */
    public function testFindOrderByBogOrderIdQueriesPaymentAdditionalInformation(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-XYZ',
                'order_status' => ['key' => 'completed'],
                // no external_order_id
            ],
        ], JSON_THROW_ON_ERROR);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        // No hit on increment_id path:
        $emptyCollection = $this->createMock(OrderCollection::class);
        $emptyCollection->method('addFieldToFilter')->willReturnSelf();
        $emptyCollection->method('setPageSize')->willReturnSelf();
        $emptyFirst = $this->createMock(Order::class);
        $emptyFirst->method('getId')->willReturn(null);
        $emptyCollection->method('getFirstItem')->willReturn($emptyFirst);
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

        // OrderRepository::get returns a fresh pending order.
        $orderPayment = $this->createMock(Payment::class);
        $orderPayment->method('setAdditionalInformation')->willReturnSelf();
        $orderPayment->method('setTransactionId')->willReturnSelf();
        $orderPayment->method('setIsTransactionPending')->willReturnSelf();
        $orderPayment->method('setIsTransactionClosed')->willReturnSelf();
        $orderPayment->method('registerCaptureNotification')->willReturnSelf();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(777);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getBaseGrandTotal')->willReturn(100.0);
        $order->method('getPayment')->willReturn($orderPayment);
        $order->method('addCommentToStatusHistory')->willReturnSelf();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();

        $this->orderRepository->expects(self::atLeastOnce())->method('get')->with(777)->willReturn($order);

        $this->callbackValidator->method('validate')->willReturn([
            'valid' => true,
            'status' => 'completed',
            'data' => [],
        ]);
        $this->config->method('isPreauth')->willReturn(false);

        $this->buildController()->execute();

        self::assertSame('OK', $this->lastContents);
    }

    /**
     * BUG-BOG-6: a second callback arriving for the same bog_order_id while
     * the first is still holding the row must obtain the lock, re-read the
     * order state, see STATE_PROCESSING, and short-circuit — it must NOT
     * call registerCaptureNotification a second time.
     */
    public function testConcurrentCallbackShortCircuitsWhenOrderAlreadyProcessing(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-DUP',
                'external_order_id' => '000000099',
                'order_status' => ['key' => 'completed'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        // First lookup returns an order that is already in processing.
        $orderPayment = $this->createMock(Payment::class);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getIncrementId')->willReturn('000000099');
        $order->method('getPayment')->willReturn($orderPayment);

        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($collection);

        // The capture path must never be exercised.
        $orderPayment->expects(self::never())->method('registerCaptureNotification');
        $this->orderRepository->expects(self::never())->method('save');

        $this->buildController()->execute();

        self::assertSame('ALREADY_PROCESSED', $this->lastContents);
    }

    /**
     * BUG-BOG-11b: a terminal-success callback arriving while no Magento
     * order exists yet (only the quote with reserved_order_id + bog_order_id
     * in payment.additional_information) must materialize the order via
     * CartManagementInterface::placeOrder and then run the normal success
     * flow. Currently the controller returns ORDER_PENDING and defers to
     * cron — which also fails to materialize quote-only state.
     *
     * Guard: the placeOrder path runs INSIDE the PaymentLock, so a concurrent
     * return from the customer's browser cannot race.
     */
    public function testMaterializesOrderFromQuoteOnTerminalSuccess(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-MAT',
                'external_order_id' => '000000101',
                'order_status' => ['key' => 'completed'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        // increment_id lookup returns an empty row → no order.
        $emptyFirst = $this->createMock(Order::class);
        $emptyFirst->method('getId')->willReturn(null);
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($emptyFirst);
        $this->orderCollectionFactory->method('create')->willReturn($collection);

        // Adapter returns no order_id for the bog_order_id JSON LIKE → fall
        // through to the quote lookup, which DOES find a pending quote.
        $adapter = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($adapter);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn(string $t): string => $t);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $adapter->method('select')->willReturn($select);

        // Stage the two lookups the controller performs: first the payment
        // table for an order id (returns false), then the quote+payment
        // table for a quote id (returns '55').
        $adapter->method('fetchOne')->willReturnOnConsecutiveCalls(false, '55');

        // cartRepository::get is consulted to re-assert method + bog keys on
        // the quote before placeOrder runs.
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('setMethod')->willReturnSelf();
        $quotePayment->method('setAdditionalInformation')->willReturnSelf();
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(55);
        $quote->method('getPayment')->willReturn($quotePayment);
        $this->cartRepository->method('get')->with(55)->willReturn($quote);
        $this->cartRepository->method('save')->willReturn($quote);

        // placeOrder must be called exactly once with quote_id=55.
        $this->cartManagement->expects(self::once())->method('placeOrder')
            ->with(55)->willReturn(777);

        // After placeOrder, the normal success path: load the fresh order.
        $orderPayment = $this->createMock(Payment::class);
        $orderPayment->method('setAdditionalInformation')->willReturnSelf();
        $orderPayment->method('setTransactionId')->willReturnSelf();
        $orderPayment->method('setIsTransactionPending')->willReturnSelf();
        $orderPayment->method('setIsTransactionClosed')->willReturnSelf();
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

        $this->callbackValidator->method('validate')->willReturn([
            'valid' => true,
            'status' => 'completed',
            'data' => [],
        ]);
        $this->config->method('isPreauth')->willReturn(false);

        $this->buildController()->execute();

        self::assertSame('OK', $this->lastContents);
    }

    /**
     * BUG-BOG-11b edge: if BOG says `in_progress` and no order exists, we
     * must NOT materialize (same reasoning as ReturnAction::handlePending).
     * Cron will pick it up if the quote transitions to terminal success.
     */
    public function testSkipsQuoteMaterializationForPendingStatus(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-INPROG',
                'external_order_id' => '000000102',
                'order_status' => ['key' => 'in_progress'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        // increment_id lookup returns empty.
        $emptyFirst = $this->createMock(Order::class);
        $emptyFirst->method('getId')->willReturn(null);
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($emptyFirst);
        $this->orderCollectionFactory->method('create')->willReturn($collection);

        // Adapter fetchOne returns false (no order, quote lookup irrelevant).
        $adapter = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($adapter);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn(string $t): string => $t);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $adapter->method('select')->willReturn($select);
        $adapter->method('fetchOne')->willReturn(false);

        // No placeOrder for non-terminal status.
        $this->cartManagement->expects(self::never())->method('placeOrder');

        $this->buildController()->execute();

        self::assertSame('ORDER_PENDING', $this->lastContents);
    }

    /**
     * BUG-BOG-10: response-code differentiation — matrix of input -> HTTP status.
     *
     *   INVALID_BODY / MISSING_ORDER_ID / VALIDATION_FAILED -> 400
     *      tells BOG to stop retrying a broken payload
     *   ORDER_PENDING / ALREADY_PROCESSED / LOCK_CONTENDED  -> 200
     *      idempotent; no retry needed, handler (or cron) will finalize
     *   ERROR (unexpected exception / DB failure)           -> 500
     *      BOG's exponential-backoff retry is safe
     *   (unknown-order terminal-success that materializes   -> 200 OK
     *    already covered by testMaterializesOrderFromQuote)
     *
     * LOCK_CONTENDED carved out in BUG-BOG-6 — must stay 200.
     */
    public function testInvalidJsonBodyReturnsHttp400(): void
    {
        $this->request->method('getContent')->willReturn('not json {');
        $this->request->method('getHeader')->willReturn(false);

        $this->buildController()->execute();

        self::assertSame('INVALID_BODY', $this->lastContents);
        self::assertSame(400, $this->lastStatus);
    }

    public function testMissingOrderIdReturnsHttp400(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $this->buildController()->execute();

        self::assertSame('MISSING_ORDER_ID', $this->lastContents);
        self::assertSame(400, $this->lastStatus);
    }

    public function testValidationFailedReturnsHttp400(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-VF',
                'external_order_id' => '000000200',
                'order_status' => ['key' => 'completed'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $orderPayment = $this->createMock(Payment::class);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getIncrementId')->willReturn('000000200');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getPayment')->willReturn($orderPayment);

        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($collection);

        $this->callbackValidator->method('validate')->willReturn([
            'valid' => false,
            'status' => 'rejected',
            'data' => [],
        ]);

        $this->buildController()->execute();

        self::assertSame('VALIDATION_FAILED', $this->lastContents);
        self::assertSame(400, $this->lastStatus);
    }

    public function testOrderPendingReturnsHttp200(): void
    {
        // Status non-terminal and no order yet -> ORDER_PENDING, retry-safe.
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-WAIT',
                'external_order_id' => '000000201',
                'order_status' => ['key' => 'in_progress'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $emptyFirst = $this->createMock(Order::class);
        $emptyFirst->method('getId')->willReturn(null);
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($emptyFirst);
        $this->orderCollectionFactory->method('create')->willReturn($collection);

        $adapter = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($adapter);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn(string $t): string => $t);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $adapter->method('select')->willReturn($select);
        $adapter->method('fetchOne')->willReturn(false);

        $this->buildController()->execute();

        self::assertSame('ORDER_PENDING', $this->lastContents);
        self::assertSame(200, $this->lastStatus);
    }

    public function testAlreadyProcessedReturnsHttp200(): void
    {
        // Already in STATE_PROCESSING -> idempotent, 200 OK.
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-DONE',
                'external_order_id' => '000000202',
                'order_status' => ['key' => 'completed'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $orderPayment = $this->createMock(Payment::class);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getIncrementId')->willReturn('000000202');
        $order->method('getPayment')->willReturn($orderPayment);

        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($collection);

        $this->buildController()->execute();

        self::assertSame('ALREADY_PROCESSED', $this->lastContents);
        self::assertSame(200, $this->lastStatus);
    }

    public function testLockContendedReturnsHttp200(): void
    {
        // Lock contention stays 200 — established by BUG-BOG-6.
        // Override default withLock to simulate contention (returns null).
        $this->paymentLock = $this->createMock(PaymentLock::class);
        $this->paymentLock->method('withLock')->willReturn(null);

        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-LOCK',
                'external_order_id' => '000000203',
                'order_status' => ['key' => 'completed'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $this->buildController()->execute();

        self::assertSame('LOCK_CONTENDED', $this->lastContents);
        self::assertSame(200, $this->lastStatus);
    }

    public function testUnexpectedExceptionReturnsHttp500(): void
    {
        // An internal failure mid-processing must yield 500 so BOG
        // exponential-backoff retries; the work is idempotent-safe.
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-BOOM',
                'external_order_id' => '000000204',
                'order_status' => ['key' => 'completed'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        // Make orderCollectionFactory blow up to force the catch branch.
        $this->orderCollectionFactory->method('create')->willThrowException(
            new \RuntimeException('DB is on fire')
        );

        $this->buildController()->execute();

        self::assertSame('ERROR', $this->lastContents);
        self::assertSame(500, $this->lastStatus);
    }

    private function buildController(): Callback
    {
        return new Callback(
            request: $this->request,
            rawFactory: $this->rawFactory,
            callbackValidator: $this->callbackValidator,
            orderCollectionFactory: $this->orderCollectionFactory,
            orderRepository: $this->orderRepository,
            orderSender: $this->orderSender,
            config: $this->config,
            logger: $this->logger,
            resourceConnection: $this->resourceConnection,
            cartManagement: $this->cartManagement,
            cartRepository: $this->cartRepository,
            paymentLock: $this->paymentLock,
        );
    }
}
