<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Controller\Payment\ReturnAction;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Service\PaymentLock;

/**
 * Regression tests for BUG-BOG-11: the ReturnAction controller MUST NOT
 * place a Magento order while BOG still reports `in_progress` / `created`.
 *
 * Before the fix, handlePending() called CartManagementInterface::placeOrder
 * and sent the customer to `checkout/onepage/success` even when the bank
 * had not yet confirmed the payment. If BOG later terminated the session as
 * failed, a real Magento order was left behind without any matching capture
 * — a ghost order that appeared "successful" to the customer.
 *
 * After the fix:
 *   - No order is placed while BOG is non-terminal.
 *   - The quote remains intact, so cron / callback / confirm can still
 *     materialize the Magento order when BOG finally confirms success.
 *   - The customer is shown a notice and redirected back to checkout.
 */
class ReturnActionTest extends TestCase
{
    private CheckoutSession&MockObject $checkoutSession;
    private RedirectFactory&MockObject $redirectFactory;
    private CartManagementInterface&MockObject $cartManagement;
    private CartRepositoryInterface&MockObject $cartRepository;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private StatusClient&MockObject $statusClient;
    private OrderSender&MockObject $orderSender;
    private MessageManager&MockObject $messageManager;
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private PaymentLock&MockObject $paymentLock;
    private Redirect&MockObject $redirectResult;

    /** @var string|null */
    private ?string $lastRedirectPath = null;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->statusClient = $this->createMock(StatusClient::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->messageManager = $this->createMock(MessageManager::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->paymentLock = $this->createMock(PaymentLock::class);
        $this->redirectResult = $this->createMock(Redirect::class);

        // Default: withLock simply runs the callable and returns its result,
        // so existing tests that don't care about concurrency semantics keep
        // behaving as before.
        $this->paymentLock->method('withLock')->willReturnCallback(
            static fn(string $key, callable $fn): mixed => $fn()
        );

        $this->redirectFactory->method('create')->willReturn($this->redirectResult);
        $this->redirectResult->method('setPath')->willReturnCallback(function (string $path) {
            $this->lastRedirectPath = $path;
            return $this->redirectResult;
        });
    }

    /**
     * When BOG reports `in_progress`, ReturnAction MUST NOT call placeOrder.
     * The quote must be saved with the in-progress marker, and the customer
     * must be redirected to /checkout (not to the success page).
     */
    public function testInProgressStatusDoesNotPlaceMagentoOrder(): void
    {
        $this->primeQuoteInSession(quoteId: 42, bogOrderId: 'BOG-ABC');
        $this->primeBogStatus('in_progress');

        // The bug: placeOrder() used to be called here. Assert it is NOT.
        $this->cartManagement->expects(self::never())->method('placeOrder');
        $this->orderRepository->expects(self::never())->method('get');
        $this->orderRepository->expects(self::never())->method('save');

        // The customer must be shown a notice and redirected to /checkout.
        $this->messageManager->expects(self::once())->method('addNoticeMessage');

        $this->buildController()->execute();

        self::assertSame('checkout', $this->lastRedirectPath);
    }

    /**
     * `created` status is semantically identical to `in_progress` for this
     * controller — the payment has not reached a terminal state. Same
     * guarantees apply.
     */
    public function testCreatedStatusDoesNotPlaceMagentoOrder(): void
    {
        $this->primeQuoteInSession(quoteId: 42, bogOrderId: 'BOG-ABC');
        $this->primeBogStatus('created');

        $this->cartManagement->expects(self::never())->method('placeOrder');
        $this->messageManager->expects(self::once())->method('addNoticeMessage');

        $this->buildController()->execute();

        self::assertSame('checkout', $this->lastRedirectPath);
    }

    /**
     * Even when handlePending does not place an order, it MUST persist the
     * bog_order_id / status on the quote so subsequent callback/confirm
     * cycles can finalize the flow.
     */
    public function testInProgressStatusPersistsBogOrderIdOnQuote(): void
    {
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('getAdditionalInformation')->willReturnCallback(
            static fn(string $key): ?string => $key === 'bog_order_id' ? 'BOG-XYZ' : null
        );

        // Assert that the three pieces of state we need to preserve are set.
        $setCalls = [];
        $quotePayment->method('setAdditionalInformation')->willReturnCallback(
            function (string $key, mixed $value) use (&$setCalls, $quotePayment) {
                $setCalls[$key] = $value;
                return $quotePayment;
            }
        );
        $quotePayment->method('setMethod')->willReturn($quotePayment);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(42);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getReservedOrderId')->willReturn('000000042');
        $quote->method('getPayment')->willReturn($quotePayment);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->primeBogStatus('in_progress');

        $this->cartRepository->expects(self::atLeastOnce())->method('save')->with($quote);

        $this->buildController()->execute();

        self::assertSame('BOG-XYZ', $setCalls['bog_order_id']);
        self::assertSame('in_progress', $setCalls['bog_status']);
    }

    /**
     * Sanity check the happy path still works — `completed` MUST place the
     * order, otherwise the fix to handlePending broke handleSuccess too.
     */
    public function testCompletedStatusStillPlacesMagentoOrder(): void
    {
        $this->primeQuoteInSession(quoteId: 42, bogOrderId: 'BOG-ABC');
        $this->primeBogStatus('completed');

        // Stub the happy-path downstream: order placement + retrieval + save.
        $this->cartManagement->expects(self::once())
            ->method('placeOrder')
            ->with(42)
            ->willReturn(777);

        $orderPayment = $this->getMockBuilder(\Magento\Sales\Model\Order\Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderPayment->method('setAdditionalInformation')->willReturnSelf();
        $orderPayment->method('setTransactionId')->willReturnSelf();
        $orderPayment->method('setIsTransactionPending')->willReturnSelf();
        $orderPayment->method('setIsTransactionClosed')->willReturnSelf();

        $order = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $order->method('getPayment')->willReturn($orderPayment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('addCommentToStatusHistory')->willReturnSelf();
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();

        $this->orderRepository->method('get')->willReturn($order);
        $this->config->method('isPreauth')->willReturn(false);
        $orderPayment->method('registerCaptureNotification')->willReturnSelf();

        $this->buildController()->execute();

        self::assertSame('checkout/onepage/success', $this->lastRedirectPath);
    }

    /**
     * When BOG reports a clearly terminal-failure status, ReturnAction must
     * clear BOG data from the quote (allowing retry) and redirect to
     * /checkout with an error. No order gets placed.
     */
    public function testFailedStatusClearsQuoteAndRedirectsToCheckout(): void
    {
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('getAdditionalInformation')->willReturnCallback(
            static fn(string $key): ?string => $key === 'bog_order_id' ? 'BOG-FAIL' : null
        );
        $quotePayment->expects(self::atLeastOnce())->method('unsAdditionalInformation');

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(42);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getPayment')->willReturn($quotePayment);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->primeBogStatus('rejected');

        $this->cartManagement->expects(self::never())->method('placeOrder');
        $this->cartRepository->expects(self::once())->method('save')->with($quote);
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $this->buildController()->execute();

        self::assertSame('checkout', $this->lastRedirectPath);
    }

    /**
     * No quote in session → redirect to cart with friendly message, no order
     * placed.
     */
    public function testNoQuoteInSessionRedirectsToCart(): void
    {
        $emptyQuote = $this->createMock(Quote::class);
        $emptyQuote->method('getId')->willReturn(null);

        $this->checkoutSession->method('getQuote')->willReturn($emptyQuote);

        $this->cartManagement->expects(self::never())->method('placeOrder');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $this->buildController()->execute();

        self::assertSame('checkout/cart', $this->lastRedirectPath);
    }

    private function buildController(): ReturnAction
    {
        return new ReturnAction(
            checkoutSession: $this->checkoutSession,
            redirectFactory: $this->redirectFactory,
            cartManagement: $this->cartManagement,
            cartRepository: $this->cartRepository,
            orderRepository: $this->orderRepository,
            statusClient: $this->statusClient,
            orderSender: $this->orderSender,
            messageManager: $this->messageManager,
            config: $this->config,
            logger: $this->logger,
            paymentLock: $this->paymentLock,
        );
    }

    private function primeQuoteInSession(int $quoteId, string $bogOrderId): void
    {
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('getAdditionalInformation')->willReturnCallback(
            static fn(string $key): ?string => $key === 'bog_order_id' ? $bogOrderId : null
        );
        $quotePayment->method('setMethod')->willReturnSelf();
        $quotePayment->method('setAdditionalInformation')->willReturnSelf();
        $quotePayment->method('unsAdditionalInformation')->willReturnSelf();

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($quoteId);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getReservedOrderId')->willReturn('000000042');
        $quote->method('getPayment')->willReturn($quotePayment);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
    }

    /**
     * Prime StatusClient to return a response with the given order_status.key.
     */
    private function primeBogStatus(string $statusKey): void
    {
        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => $statusKey],
            'id' => 'BOG-ABC',
        ]);
    }
}
