<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Controller\Payment\AbortRedirect;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Regression tests for BUG-BOG-13: the AbortRedirect controller must reset
 * a stalled BOG checkout so the customer can retry.
 *
 * Unlike TBC's AbortRedirect, this endpoint is normally order-less: the BOG
 * flow defers Magento order placement until terminal payment confirmation,
 * so on a failed redirect there is usually only a quote to clean. The
 * controller handles both:
 *   - Always: wipe bog_order_id / bog_redirect_url / bog_status / details
 *     from the quote so the next attempt starts clean.
 *   - When an increment_id is supplied AND it points to a genuine orphan
 *     pending_payment shubo_bog order with zero invoices: cancel that
 *     order as well.
 *
 * Guards against misuse as a generic order-cancel backdoor are identical
 * to TBC's AbortRedirect: wrong state / wrong method / has invoices / not
 * found all yield a graceful no-op with success=true (quote cleared,
 * order_cancelled=false).
 */
class AbortRedirectTest extends TestCase
{
    private JsonFactory&MockObject $jsonFactory;
    private HttpRequest&MockObject $request;
    private CheckoutSession&MockObject $checkoutSession;
    private CartRepositoryInterface&MockObject $cartRepository;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private LoggerInterface&MockObject $logger;
    private JsonResult&MockObject $jsonResult;

    /** @var array<string, mixed> */
    private array $lastResultData = [];

    protected function setUp(): void
    {
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->jsonResult = $this->createMock(JsonResult::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnCallback(function ($data) {
            $this->lastResultData = $data;
            return $this->jsonResult;
        });
    }

    public function testClearsQuoteBogDataWhenNoIncrementId(): void
    {
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('getAdditionalInformation')->willReturnCallback(
            static fn(string $key): ?string => match ($key) {
                'bog_order_id' => 'BOG-ABC',
                'bog_redirect_url' => 'https://example/pay/abc',
                'bog_status' => 'created',
                default => null,
            }
        );
        $quotePayment->expects(self::exactly(3))
            ->method('unsAdditionalInformation')
            ->with(self::logicalOr(
                self::equalTo('bog_order_id'),
                self::equalTo('bog_redirect_url'),
                self::equalTo('bog_status'),
            ));

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(42);
        $quote->method('getPayment')->willReturn($quotePayment);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->cartRepository->expects(self::once())->method('save')->with($quote);

        $this->request->method('getParam')->willReturn('');

        // With no increment_id, the order repo is never consulted.
        $this->orderRepository->expects(self::never())->method('getList');

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertTrue($this->lastResultData['quote_cleared']);
        self::assertFalse($this->lastResultData['order_cancelled']);
    }

    public function testNoopWhenQuoteHasNoBogData(): void
    {
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('getAdditionalInformation')->willReturn(null);
        $quotePayment->expects(self::never())->method('unsAdditionalInformation');

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(42);
        $quote->method('getPayment')->willReturn($quotePayment);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
        $this->cartRepository->expects(self::never())->method('save');

        $this->request->method('getParam')->willReturn('');

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertFalse($this->lastResultData['quote_cleared']);
        self::assertFalse($this->lastResultData['order_cancelled']);
    }

    public function testCancelsOrphanShuboBogPendingOrder(): void
    {
        $this->stubEmptyQuote();

        $order = $this->makeOrder(
            state: Order::STATE_PENDING_PAYMENT,
            method: ConfigProvider::CODE,
            hasInvoices: false,
        );
        $order->expects(self::once())->method('cancel');
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->primeOrderLookup('000000042', $order);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertTrue($this->lastResultData['order_cancelled']);
        self::assertSame('000000042', $this->lastResultData['increment_id']);
    }

    public function testRefusesToCancelWrongPaymentMethod(): void
    {
        $this->stubEmptyQuote();

        $order = $this->makeOrder(
            state: Order::STATE_PENDING_PAYMENT,
            method: 'checkmo',
            hasInvoices: false,
        );
        $order->expects(self::never())->method('cancel');
        $this->orderRepository->expects(self::never())->method('save');

        $this->primeOrderLookup('000000042', $order);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        // Still success (quote cleanup path), but order was NOT cancelled.
        self::assertTrue($this->lastResultData['success']);
        self::assertFalse($this->lastResultData['order_cancelled']);
    }

    public function testRefusesToCancelOrderNotInPendingPayment(): void
    {
        $this->stubEmptyQuote();

        $order = $this->makeOrder(
            state: Order::STATE_PROCESSING,
            method: ConfigProvider::CODE,
            hasInvoices: false,
        );
        $order->expects(self::never())->method('cancel');
        $this->orderRepository->expects(self::never())->method('save');

        $this->primeOrderLookup('000000042', $order);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertFalse($this->lastResultData['order_cancelled']);
    }

    public function testRefusesToCancelOrderWithInvoices(): void
    {
        $this->stubEmptyQuote();

        $order = $this->makeOrder(
            state: Order::STATE_PENDING_PAYMENT,
            method: ConfigProvider::CODE,
            hasInvoices: true,
        );
        $order->expects(self::never())->method('cancel');
        $this->orderRepository->expects(self::never())->method('save');

        $this->primeOrderLookup('000000042', $order);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertFalse($this->lastResultData['order_cancelled']);
    }

    public function testGracefulWhenIncrementIdGivenButOrderNotFound(): void
    {
        $this->stubEmptyQuote();

        $this->primeOrderLookup('000000042', null);
        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->orderRepository->expects(self::never())->method('save');

        $this->buildController()->execute();

        self::assertTrue($this->lastResultData['success']);
        self::assertFalse($this->lastResultData['order_cancelled']);
    }

    public function testReturnsSuccessFalseOnUnexpectedException(): void
    {
        // Quote getter blows up with a non-LocalizedException — the top-level
        // catch must return {success:false} with a friendly message instead
        // of leaking the exception to the customer.
        $this->checkoutSession->method('getQuote')->willReturnCallback(static function (): void {
            throw new \RuntimeException('boom');
        });

        // `clearQuoteBogData` catches its own exception, so the outer try
        // still has to reach a non-throwing path. Force the order lookup to
        // blow up by making getList throw.
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $this->orderRepository->method('getList')->willThrowException(new \RuntimeException('db down'));

        $this->request->method('getParam')->willReturnCallback(static function (string $key) {
            return $key === 'increment_id' ? '000000042' : null;
        });

        $this->buildController()->execute();

        self::assertFalse($this->lastResultData['success']);
        self::assertStringContainsStringIgnoringCase('reset', (string) $this->lastResultData['message']);
    }

    /**
     * Controller under test, wired with all mocked dependencies.
     */
    private function buildController(): AbortRedirect
    {
        return new AbortRedirect(
            jsonFactory: $this->jsonFactory,
            request: $this->request,
            checkoutSession: $this->checkoutSession,
            cartRepository: $this->cartRepository,
            orderRepository: $this->orderRepository,
            searchCriteriaBuilder: $this->searchCriteriaBuilder,
            logger: $this->logger,
        );
    }

    /**
     * Stub a "no-op" quote: present but with no BOG data to clear. Tests that
     * focus on the order-cancel path shouldn't have the quote path muddying
     * assertions.
     */
    private function stubEmptyQuote(): void
    {
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('getAdditionalInformation')->willReturn(null);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(42);
        $quote->method('getPayment')->willReturn($quotePayment);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
    }

    /**
     * Build an Order mock with the given state + payment method + invoice
     * presence so each test can tune just the attribute it cares about.
     */
    private function makeOrder(
        string $state,
        string $method,
        bool $hasInvoices,
    ): Order&MockObject {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod'])
            ->getMock();
        $payment->method('getMethod')->willReturn($method);

        $invoiceCollection = $this->getMockBuilder(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSize'])
            ->getMock();
        $invoiceCollection->method('getSize')->willReturn($hasInvoices ? 1 : 0);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getEntityId', 'getIncrementId', 'getState', 'getPayment',
                'cancel', 'addCommentToStatusHistory', 'getInvoiceCollection',
            ])
            ->getMock();
        $order->method('getEntityId')->willReturn(11);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getState')->willReturn($state);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);
        $order->method('cancel')->willReturnSelf();
        $order->method('addCommentToStatusHistory')->willReturnSelf();

        return $order;
    }

    /**
     * Prime the repository to return (or not) a given order for a given
     * increment_id lookup.
     */
    private function primeOrderLookup(string $incrementId, ?Order $order): void
    {
        $searchCriteria = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($order === null ? [] : [$order]);
        $this->orderRepository->method('getList')->willReturn($searchResult);
    }
}
