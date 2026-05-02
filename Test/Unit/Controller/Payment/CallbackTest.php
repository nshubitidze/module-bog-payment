<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Controller\Payment;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Controller\Payment\Callback;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Validator\CallbackValidator;
use Shubo\BogPayment\Service\BogOrderResolver;
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
 *                missing from the callback, the controller delegates to
 *                BogOrderResolver, which JOINs sales_order_payment.additional_information
 *                LIKE '%"bog_order_id":"<id>"%' to resolve the order. The
 *                resolver's JSON-LIKE behaviour is asserted in BogOrderResolverTest.
 *
 *   BUG-BOG-11b — materialize the Magento order from a quote carrying
 *                 bog_order_id when the callback terminally confirms success
 *                 but no order exists yet. Resolver delegate; the orchestration
 *                 contract (terminal-success guard) is asserted here.
 */
class CallbackTest extends TestCase
{
    private HttpRequest&MockObject $request;
    private RawFactory&MockObject $rawFactory;
    private CallbackValidator&MockObject $callbackValidator;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private OrderSender&MockObject $orderSender;
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private PaymentLock&MockObject $paymentLock;
    private BogOrderResolver&MockObject $bogOrderResolver;
    private Raw&MockObject $rawResult;

    private string $lastContents = '';
    private int $lastStatus = 0;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->rawFactory = $this->createMock(RawFactory::class);
        $this->callbackValidator = $this->createMock(CallbackValidator::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->paymentLock = $this->createMock(PaymentLock::class);
        $this->bogOrderResolver = $this->createMock(BogOrderResolver::class);
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

        $this->bogOrderResolver->method('findOrder')->willReturn($order);

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

        // Resolver-side wiring: increment_id path misses, quote lookup hits
        // quote_id=55, materialise returns the fresh order. The placeOrder
        // contract (cartManagement->placeOrder(55)) is asserted in
        // BogOrderResolverTest::testMaterializeOrderFromQuoteCallsPlaceOrderWithQuoteId.
        $this->bogOrderResolver->method('findOrder')->willReturn(null);
        $this->bogOrderResolver->method('findQuoteIdByBogOrderId')->willReturn(55);
        $this->bogOrderResolver->expects(self::once())
            ->method('materializeOrderFromQuote')->with(55, 'BOG-MAT')->willReturn($order);

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

        // Resolver returns no order; controller must NOT ask the resolver to
        // materialise because status is non-terminal.
        $this->bogOrderResolver->method('findOrder')->willReturn(null);
        $this->bogOrderResolver->expects(self::never())->method('findQuoteIdByBogOrderId');
        $this->bogOrderResolver->expects(self::never())->method('materializeOrderFromQuote');

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

        $this->bogOrderResolver->method('findOrder')->willReturn($order);

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

        $this->bogOrderResolver->method('findOrder')->willReturn(null);

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

        $this->bogOrderResolver->method('findOrder')->willReturn($order);

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

        // Make the resolver blow up inside the lock to force the catch branch.
        $this->bogOrderResolver->method('findOrder')->willThrowException(
            new \RuntimeException('DB is on fire')
        );

        $this->buildController()->execute();

        self::assertSame('ERROR', $this->lastContents);
        self::assertSame(500, $this->lastStatus);
    }

    /**
     * Session 8 Priority 2.1 — edge case #6 (order amount changes mid-flow).
     *
     * If the BOG-reported total under purchase_units.total_amount disagrees
     * with the local Magento order's grand_total by more than 1 tetri, the
     * cart was edited (or the request was tampered) between initiation and
     * capture. Refuse with AMOUNT_MISMATCH (HTTP 400 — do not retry).
     */
    public function testAmountMismatchAbortsWithHttp400(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-AMNT',
                'external_order_id' => '000000301',
                'order_status' => ['key' => 'completed'],
                'purchase_units' => ['total_amount' => 50.00],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $orderPayment = $this->createMock(Payment::class);
        // Capture path must NOT run.
        $orderPayment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(301);
        $order->method('getStoreId')->willReturn(1);
        // Local order says 25.00 — BOG reports 50.00 → 2500 tetri diff.
        $order->method('getGrandTotal')->willReturn(25.00);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('getIncrementId')->willReturn('000000301');
        $order->method('getPayment')->willReturn($orderPayment);

        $this->bogOrderResolver->method('findOrder')->willReturn($order);

        // Pass-1 reviewer M-1 fix: validation['data'] carries the FULL
        // callback envelope on the signature path. amountMismatch() unwraps
        // 'body' before reading purchase_units; this mock shape matches
        // production exactly.
        $this->callbackValidator->method('validate')->willReturn([
            'valid' => true,
            'status' => 'completed',
            'data' => [
                'event' => 'order_payment',
                'body' => [
                    'order_id' => 'BOG-AMNT',
                    'order_status' => ['key' => 'completed'],
                    'purchase_units' => ['total_amount' => 50.00],
                ],
            ],
        ]);

        // The critical raw-context log line for ops correlation.
        $this->logger->expects(self::atLeastOnce())
            ->method('critical')
            ->with(
                self::stringContains('amount mismatch'),
                self::callback(static function (array $ctx): bool {
                    return ($ctx['order_id'] ?? null) === '000000301'
                        && ($ctx['bog_order_id'] ?? null) === 'BOG-AMNT'
                        && ($ctx['bog_amount_minor'] ?? null) === 5000
                        && ($ctx['order_amount_minor'] ?? null) === 2500;
                })
            );

        $this->orderRepository->expects(self::never())->method('save');

        $this->buildController()->execute();

        self::assertSame('AMOUNT_MISMATCH', $this->lastContents);
        self::assertSame(400, $this->lastStatus);
    }

    /**
     * Tolerance check: 1-tetri rounding drift between BOG and Magento must
     * NOT trip the mismatch guard (false-positive defence).
     */
    public function testAmountWithin1TetriToleranceProcessesNormally(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-EPSI',
                'external_order_id' => '000000302',
                'order_status' => ['key' => 'completed'],
                'purchase_units' => ['total_amount' => 25.01],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $orderPayment = $this->createMock(Payment::class);
        $orderPayment->method('setAdditionalInformation')->willReturnSelf();
        $orderPayment->method('setTransactionId')->willReturnSelf();
        $orderPayment->method('setIsTransactionPending')->willReturnSelf();
        $orderPayment->method('setIsTransactionClosed')->willReturnSelf();
        $orderPayment->method('registerCaptureNotification')->willReturnSelf();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(302);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(25.00);
        $order->method('getBaseGrandTotal')->willReturn(25.00);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('getIncrementId')->willReturn('000000302');
        $order->method('getPayment')->willReturn($orderPayment);
        $order->method('addCommentToStatusHistory')->willReturnSelf();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();

        $this->bogOrderResolver->method('findOrder')->willReturn($order);

        // Production shape: validation['data'] is the full envelope with
        // body wrapper (Pass-1 reviewer M-1 fix).
        $this->callbackValidator->method('validate')->willReturn([
            'valid' => true,
            'status' => 'completed',
            'data' => [
                'event' => 'order_payment',
                'body' => [
                    'order_id' => 'BOG-EPSI',
                    'purchase_units' => ['total_amount' => 25.01],
                ],
            ],
        ]);
        $this->config->method('isPreauth')->willReturn(false);

        // critical-log must NOT fire (no mismatch within tolerance).
        $this->logger->expects(self::never())->method('critical');

        $this->buildController()->execute();

        self::assertSame('OK', $this->lastContents);
    }

    /**
     * No amount in callback data → guard cannot evaluate, so it must NOT
     * block (defensive: BOG callbacks vary by status type).
     */
    public function testMissingAmountProcessesNormally(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-NOAM',
                'external_order_id' => '000000303',
                'order_status' => ['key' => 'completed'],
                // no purchase_units / amount
            ],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $orderPayment = $this->createMock(Payment::class);
        $orderPayment->method('setAdditionalInformation')->willReturnSelf();
        $orderPayment->method('setTransactionId')->willReturnSelf();
        $orderPayment->method('setIsTransactionPending')->willReturnSelf();
        $orderPayment->method('setIsTransactionClosed')->willReturnSelf();
        $orderPayment->method('registerCaptureNotification')->willReturnSelf();

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(303);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(99.99);
        $order->method('getBaseGrandTotal')->willReturn(99.99);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('getIncrementId')->willReturn('000000303');
        $order->method('getPayment')->willReturn($orderPayment);
        $order->method('addCommentToStatusHistory')->willReturnSelf();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();

        $this->bogOrderResolver->method('findOrder')->willReturn($order);

        $this->callbackValidator->method('validate')->willReturn([
            'valid' => true,
            'status' => 'completed',
            'data' => [],
        ]);
        $this->config->method('isPreauth')->willReturn(false);

        $this->logger->expects(self::never())->method('critical');

        $this->buildController()->execute();

        self::assertSame('OK', $this->lastContents);
    }

    /**
     * Pass-1 reviewer regression — exercise the EXACT BOG callback envelope
     * shape end-to-end so we can't get lulled by a pre-flattened mock again.
     *
     * The payload is what `signAndPostBogCallback()` posts in the lifecycle
     * specs: `{event, zoned_request_time, body: {order_status, purchase_units, ...}}`.
     * The validator's signature path returns the full envelope as
     * `validation['data']`; amountMismatch() must unwrap `body` before
     * reading purchase_units.
     */
    public function testAmountMismatchTriggersOnRealCallbackEnvelopeShape(): void
    {
        $envelopeBody = [
            'order_id' => 'BOG-ENVL',
            'external_order_id' => '000000400',
            'order_status' => ['key' => 'completed'],
            'purchase_units' => ['currency' => 'GEL', 'total_amount' => 75.00],
        ];
        $envelope = [
            'event' => 'order_payment',
            'zoned_request_time' => '2026-04-25T12:00:00Z',
            'body' => $envelopeBody,
        ];
        $rawBody = json_encode($envelope, JSON_THROW_ON_ERROR);

        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $orderPayment = $this->createMock(Payment::class);
        $orderPayment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(400);
        $order->method('getStoreId')->willReturn(1);
        // Local order says 30.00 — BOG envelope reports 75.00 → 4500 tetri diff.
        $order->method('getGrandTotal')->willReturn(30.00);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('getIncrementId')->willReturn('000000400');
        $order->method('getPayment')->willReturn($orderPayment);

        $this->bogOrderResolver->method('findOrder')->willReturn($order);

        // Validator returns the full envelope verbatim — exactly the
        // CallbackValidator signature path's behaviour.
        $this->callbackValidator->method('validate')->willReturn([
            'valid' => true,
            'status' => 'completed',
            'data' => $envelope,
        ]);

        $this->logger->expects(self::atLeastOnce())
            ->method('critical')
            ->with(
                self::stringContains('amount mismatch'),
                self::callback(static function (array $ctx): bool {
                    return ($ctx['order_id'] ?? null) === '000000400'
                        && ($ctx['bog_amount_minor'] ?? null) === 7500
                        && ($ctx['order_amount_minor'] ?? null) === 3000;
                })
            );

        $this->orderRepository->expects(self::never())->method('save');

        $this->buildController()->execute();

        self::assertSame('AMOUNT_MISMATCH', $this->lastContents);
        self::assertSame(400, $this->lastStatus);
    }

    /**
     * Status-API fallback path returns the receipt response directly (no
     * `body` wrapper). The mismatch guard must still work on this shape.
     */
    public function testAmountMismatchHandlesFlatStatusApiShape(): void
    {
        $rawBody = json_encode([
            'event' => 'order_payment',
            'body' => [
                'order_id' => 'BOG-FLAT',
                'external_order_id' => '000000401',
                'order_status' => ['key' => 'completed'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->request->method('getContent')->willReturn($rawBody);
        $this->request->method('getHeader')->willReturn(false);

        $orderPayment = $this->createMock(Payment::class);
        $orderPayment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(401);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(10.00);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('getIncrementId')->willReturn('000000401');
        $order->method('getPayment')->willReturn($orderPayment);

        $this->bogOrderResolver->method('findOrder')->willReturn($order);

        // Validator returns the receipt-shape directly (no `body` wrapper).
        $this->callbackValidator->method('validate')->willReturn([
            'valid' => true,
            'status' => 'completed',
            'data' => [
                'order_status' => ['key' => 'completed'],
                'purchase_units' => ['total_amount' => 99.99],
            ],
        ]);

        $this->logger->expects(self::atLeastOnce())->method('critical');

        $this->buildController()->execute();

        self::assertSame('AMOUNT_MISMATCH', $this->lastContents);
        self::assertSame(400, $this->lastStatus);
    }

    private function buildController(): Callback
    {
        return new Callback(
            request: $this->request,
            rawFactory: $this->rawFactory,
            callbackValidator: $this->callbackValidator,
            orderRepository: $this->orderRepository,
            orderSender: $this->orderSender,
            config: $this->config,
            logger: $this->logger,
            paymentLock: $this->paymentLock,
            bogOrderResolver: $this->bogOrderResolver,
        );
    }
}
