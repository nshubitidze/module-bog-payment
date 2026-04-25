<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Api\Data\SplitPaymentDataInterface;
use Shubo\BogPayment\Controller\Payment\Initiate;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\CreatePaymentClient;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Gateway\Http\TransferFactory;

/**
 * BUG-BOG-1: Initiate.php must dispatch `shubo_bog_payment_split_before_quote`
 * before the BOG create-order request, and if the observer populates the
 * SplitPaymentData container, merge the resulting split section into the
 * final payload. Without this, BOG orders fall back to offline settlement
 * on every single transaction — the core marketplace value prop is broken.
 *
 * Also verifies the three guard paths:
 *   - split disabled in config -> no event, no split merged
 *   - observer produces no data -> no split section in payload
 *   - observer throws -> event dispatch try/caught, payment still proceeds
 */
class InitiateTest extends TestCase
{
    private CheckoutSession&MockObject $checkoutSession;
    private JsonFactory&MockObject $jsonFactory;
    private CartRepositoryInterface&MockObject $cartRepository;
    private CreatePaymentClient&MockObject $createPaymentClient;
    private TransferFactory&MockObject $transferFactory;
    private Config&MockObject $config;
    private UrlInterface&MockObject $urlBuilder;
    private ResolverInterface&MockObject $localeResolver;
    private LoggerInterface&MockObject $logger;
    private EventManager&MockObject $eventManager;
    private SplitPaymentDataInterface&MockObject $splitPaymentData;
    private StatusClient&MockObject $statusClient;
    private FormKeyValidator&MockObject $formKeyValidator;
    private JsonResult&MockObject $jsonResult;

    /** @var array<string, mixed> */
    private array $lastResultData = [];

    /** @var array<string, mixed>|null */
    private ?array $capturedRequestBody = null;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->createPaymentClient = $this->createMock(CreatePaymentClient::class);
        $this->transferFactory = $this->createMock(TransferFactory::class);
        $this->config = $this->createMock(Config::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->localeResolver = $this->createMock(ResolverInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->splitPaymentData = $this->createMock(SplitPaymentDataInterface::class);
        $this->statusClient = $this->createMock(StatusClient::class);
        $this->formKeyValidator = $this->createMock(FormKeyValidator::class);
        $this->jsonResult = $this->createMock(JsonResult::class);

        $this->jsonFactory->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnCallback(function ($data) {
            $this->lastResultData = is_array($data) ? $data : [];
            return $this->jsonResult;
        });

        $this->urlBuilder->method('getUrl')->willReturn('https://example.test/route');
        $this->localeResolver->method('getLocale')->willReturn('en_US');

        $this->config->method('getPaymentActionMode')->willReturn('automatic');
        $this->config->method('getPaymentLifetime')->willReturn(15);
        $this->config->method('getAllowedPaymentMethods')->willReturn(['card']);

        // Capture the body passed to transferFactory so assertions can inspect
        // the merged split section.
        $this->transferFactory->method('create')->willReturnCallback(function (array $body) {
            $this->capturedRequestBody = $body;
            return $this->createMock(TransferInterface::class);
        });

        // BOG happy-path response.
        $this->createPaymentClient->method('placeRequest')->willReturn([
            'http_status' => 200,
            'id' => 'BOG-123',
            '_links' => [
                'redirect' => ['href' => 'https://payments.bog.ge/x'],
                'details' => ['href' => 'https://payments.bog.ge/details/x'],
            ],
        ]);
    }

    public function testDispatchesQuoteSplitEventWhenSplitEnabled(): void
    {
        $this->primeQuote(quoteId: 42, grandTotal: 100.0);
        $this->config->method('isSplitEnabled')->willReturn(true);

        $this->eventManager->expects(self::atLeastOnce())
            ->method('dispatch')
            ->with(
                'shubo_bog_payment_split_before_quote',
                self::callback(function (array $payload): bool {
                    self::assertArrayHasKey('quote', $payload);
                    self::assertArrayHasKey('payment', $payload);
                    self::assertArrayHasKey('split_payment_data', $payload);
                    return true;
                })
            );

        // Observer would populate: simulate hasSplitPayments returning true.
        $this->splitPaymentData->method('hasSplitPayments')->willReturn(true);
        $this->splitPaymentData->method('getSplitPayments')->willReturn([
            [
                'iban' => 'GE29NB0000000101904917',
                'percent' => 85.0,
                'description' => 'ShopX payout',
            ],
        ]);

        $this->buildController()->execute();

        self::assertIsArray($this->capturedRequestBody);
        self::assertTrue(($this->lastResultData['success'] ?? false));
    }

    public function testMergesObserverPopulatedSplitIntoBogPayload(): void
    {
        $this->primeQuote(quoteId: 42, grandTotal: 100.0);
        $this->config->method('isSplitEnabled')->willReturn(true);

        $this->splitPaymentData->method('hasSplitPayments')->willReturn(true);
        $this->splitPaymentData->method('getSplitPayments')->willReturn([
            [
                'iban' => 'GE29NB0000000101904917',
                'percent' => 85.0,
                'description' => 'ShopX payout',
            ],
        ]);

        $this->buildController()->execute();

        self::assertIsArray($this->capturedRequestBody);
        // Split section must be present in the exact shape SplitDataBuilder produces.
        self::assertArrayHasKey('config', $this->capturedRequestBody);
        self::assertArrayHasKey('split', $this->capturedRequestBody['config']);
        self::assertArrayHasKey('split_payments', $this->capturedRequestBody['config']['split']);

        $splits = $this->capturedRequestBody['config']['split']['split_payments'];
        self::assertCount(1, $splits);
        self::assertSame('GE29NB0000000101904917', $splits[0]['iban']);
        self::assertSame(85.0, $splits[0]['percent']);
        self::assertSame('ShopX payout', $splits[0]['description']);
    }

    public function testNoSplitSectionWhenSplitDisabled(): void
    {
        $this->primeQuote(quoteId: 42, grandTotal: 100.0);
        $this->config->method('isSplitEnabled')->willReturn(false);

        // Event MUST NOT fire when split is globally disabled.
        $this->eventManager->expects(self::never())
            ->method('dispatch')
            ->with('shubo_bog_payment_split_before_quote', self::anything());

        $this->buildController()->execute();

        self::assertIsArray($this->capturedRequestBody);
        // No config.split.split_payments key.
        self::assertArrayNotHasKey('config', $this->capturedRequestBody);
    }

    public function testNoSplitSectionWhenObserverProducesNoData(): void
    {
        $this->primeQuote(quoteId: 42, grandTotal: 100.0);
        $this->config->method('isSplitEnabled')->willReturn(true);

        // Observer fires but the SplitPaymentData container stays empty.
        $this->splitPaymentData->method('hasSplitPayments')->willReturn(false);
        $this->splitPaymentData->method('getSplitPayments')->willReturn([]);

        $this->buildController()->execute();

        self::assertIsArray($this->capturedRequestBody);
        self::assertArrayNotHasKey('config', $this->capturedRequestBody);
    }

    public function testSplitDispatchFailureDoesNotBlockPayment(): void
    {
        $this->primeQuote(quoteId: 42, grandTotal: 100.0);
        $this->config->method('isSplitEnabled')->willReturn(true);

        $this->eventManager->method('dispatch')->willThrowException(
            new \RuntimeException('observer blew up')
        );

        // Must still reach BOG and return success.
        $this->buildController()->execute();

        self::assertTrue(($this->lastResultData['success'] ?? false));
        self::assertIsArray($this->capturedRequestBody);
        // No split section: failure path clears/skips it.
        self::assertArrayNotHasKey('config', $this->capturedRequestBody);
    }

    /**
     * BUG-BOG-13b: before creating a brand-new BOG order, probe any
     * existing bog_order_id already on the quote. Three outcomes:
     *
     *   - terminal success (completed/captured)  -> short-circuit to the
     *     success flow: return redirect to checkout/onepage/success so
     *     the customer doesn't double-charge
     *   - pending (created/in_progress)          -> inform the customer
     *     that the bank is still processing; do NOT send a fresh order
     *   - terminal failure (expired/rejected/
     *     declined/error)                        -> clear the stale id
     *     from the quote and proceed with a fresh initiation
     *
     * Covers the stale-quote case where the watchdog (BUG-BOG-13) did not
     * fire and the customer navigated back to checkout with the prior
     * quote still carrying a live bog_order_id.
     */
    public function testNoExistingBogOrderIdProceedsToFreshInitiation(): void
    {
        $this->primeQuote(quoteId: 42, grandTotal: 100.0, existingBogOrderId: null);
        $this->config->method('isSplitEnabled')->willReturn(false);

        // StatusClient must not be hit when there's no existing id.
        $this->statusClient->expects(self::never())->method('checkStatus');

        $this->buildController()->execute();

        self::assertTrue(($this->lastResultData['success'] ?? false));
        self::assertSame('https://payments.bog.ge/x', $this->lastResultData['redirect_url'] ?? null);
    }

    public function testExistingBogOrderIdTerminalSuccessShortCircuits(): void
    {
        $this->primeQuote(quoteId: 42, grandTotal: 100.0, existingBogOrderId: 'BOG-DONE');
        $this->config->method('isSplitEnabled')->willReturn(false);

        // urlBuilder stubbed to return a distinct URL for the success route
        // so the test can assert the short-circuit targets the right path.
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->urlBuilder->method('getUrl')->willReturnCallback(
            static fn(string $route): string => 'https://example.test/' . $route
        );

        $this->statusClient->expects(self::once())
            ->method('checkStatus')
            ->with('BOG-DONE')
            ->willReturn(['order_status' => ['key' => 'completed']]);

        // Must NOT hit BOG create-order again.
        $this->createPaymentClient->expects(self::never())->method('placeRequest');

        $this->buildController()->execute();

        self::assertTrue(($this->lastResultData['success'] ?? false));
        // The response must signal "already paid" by pointing the frontend
        // at the success page rather than a new bog redirect URL.
        self::assertArrayHasKey('redirect_url', $this->lastResultData);
        self::assertStringContainsString(
            'checkout/onepage/success',
            (string) $this->lastResultData['redirect_url']
        );
        self::assertTrue(($this->lastResultData['already_paid'] ?? false));
    }

    public function testExistingBogOrderIdStalePendingReportsProcessing(): void
    {
        $this->primeQuote(quoteId: 42, grandTotal: 100.0, existingBogOrderId: 'BOG-WAIT');
        $this->config->method('isSplitEnabled')->willReturn(false);

        $this->statusClient->expects(self::once())
            ->method('checkStatus')
            ->with('BOG-WAIT')
            ->willReturn(['order_status' => ['key' => 'in_progress']]);

        // Must not create a new order while BOG still thinks one is live.
        $this->createPaymentClient->expects(self::never())->method('placeRequest');

        $this->buildController()->execute();

        self::assertFalse(($this->lastResultData['success'] ?? true));
        self::assertArrayHasKey('message', $this->lastResultData);
        self::assertStringContainsString(
            'processed',
            strtolower((string) $this->lastResultData['message'])
        );
    }

    public function testExistingBogOrderIdTerminalFailureClearsAndProceeds(): void
    {
        $this->primeQuote(quoteId: 42, grandTotal: 100.0, existingBogOrderId: 'BOG-DEAD');
        $this->config->method('isSplitEnabled')->willReturn(false);

        $this->statusClient->method('checkStatus')
            ->with('BOG-DEAD')
            ->willReturn(['order_status' => ['key' => 'expired']]);

        // A new create-order MUST still happen — stale id gets cleared.
        $this->createPaymentClient->expects(self::atLeastOnce())->method('placeRequest');

        $this->buildController()->execute();

        self::assertTrue(($this->lastResultData['success'] ?? false));
        self::assertSame('https://payments.bog.ge/x', $this->lastResultData['redirect_url'] ?? null);
    }

    public function testExistingBogOrderIdStatusApiErrorProceedsDefensively(): void
    {
        // If the status API itself fails, the only safe choice is to
        // treat the existing id as gone and proceed with a fresh order.
        // The watchdog + reconciler will clean up the orphan if needed.
        $this->primeQuote(quoteId: 42, grandTotal: 100.0, existingBogOrderId: 'BOG-UNK');
        $this->config->method('isSplitEnabled')->willReturn(false);

        $this->statusClient->method('checkStatus')->willThrowException(
            new BogApiException(__('bogus'))
        );

        $this->createPaymentClient->expects(self::atLeastOnce())->method('placeRequest');

        $this->buildController()->execute();

        self::assertTrue(($this->lastResultData['success'] ?? false));
    }

    /**
     * BUG-BOG-14: explicit CSRF guard. Initiate previously relied on the
     * default FormKeyValidator plugin applied to every POST route. Make
     * the check first-class and audit-visible by implementing
     * CsrfAwareActionInterface and using the injected FormKeyValidator
     * to reject requests without a valid form_key.
     *
     * Tests the three CSRF outcomes:
     *   valid form_key  -> proceed (default behaviour in other tests)
     *   missing form_key or invalid -> createCsrfValidationException()
     *     returns InvalidRequestException
     */
    public function testValidFormKeyAllowsRequest(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $this->formKeyValidator->expects(self::once())
            ->method('validate')
            ->with($request)
            ->willReturn(true);

        $exception = $this->buildController()->createCsrfValidationException($request);
        self::assertNull($exception);

        $allowed = $this->buildController()->validateForCsrf($request);
        // Second call to validateForCsrf — separate controller instance,
        // but both must return null (defer to Magento default behaviour
        // which then invokes createCsrfValidationException).
        self::assertNull($allowed);
    }

    public function testMissingFormKeyReturnsInvalidRequestException(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $this->formKeyValidator->method('validate')->with($request)->willReturn(false);

        $exception = $this->buildController()->createCsrfValidationException($request);
        self::assertInstanceOf(InvalidRequestException::class, $exception);
    }

    public function testBogusFormKeyReturnsInvalidRequestException(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $this->formKeyValidator->method('validate')->with($request)->willReturn(false);

        $exception = $this->buildController()->createCsrfValidationException($request);
        self::assertInstanceOf(InvalidRequestException::class, $exception);
    }

    private function buildController(): Initiate
    {
        return new Initiate(
            checkoutSession: $this->checkoutSession,
            jsonFactory: $this->jsonFactory,
            cartRepository: $this->cartRepository,
            createPaymentClient: $this->createPaymentClient,
            transferFactory: $this->transferFactory,
            config: $this->config,
            urlBuilder: $this->urlBuilder,
            localeResolver: $this->localeResolver,
            logger: $this->logger,
            eventManager: $this->eventManager,
            splitPaymentData: $this->splitPaymentData,
            statusClient: $this->statusClient,
            formKeyValidator: $this->formKeyValidator,
        );
    }

    private function primeQuote(int $quoteId, float $grandTotal, ?string $existingBogOrderId = null): void
    {
        $quotePayment = $this->createMock(QuotePayment::class);
        $quotePayment->method('setMethod')->willReturnSelf();
        $quotePayment->method('setAdditionalInformation')->willReturnSelf();
        $quotePayment->method('unsAdditionalInformation')->willReturnSelf();
        $quotePayment->method('getAdditionalInformation')->willReturnCallback(
            static fn(string $key): mixed => $key === 'bog_order_id' ? $existingBogOrderId : null
        );

        // Quote.php declares magic @method accessors, so createMock cannot
        // intercept getGrandTotal/getReservedOrderId/etc. Use addMethods for
        // the magic ones plus onlyMethods for the real ones.
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getGrandTotal', 'getQuoteCurrencyCode'])
            ->onlyMethods([
                'getId',
                'getItemsCount',
                'reserveOrderId',
                'getReservedOrderId',
                'getStoreId',
                'getPayment',
                'getAllVisibleItems',
            ])
            ->getMock();
        $quote->method('getId')->willReturn($quoteId);
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('getGrandTotal')->willReturn($grandTotal);
        $quote->method('getReservedOrderId')->willReturn('000000042');
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getQuoteCurrencyCode')->willReturn('GEL');
        $quote->method('getPayment')->willReturn($quotePayment);
        $quote->method('getAllVisibleItems')->willReturn([]);

        $this->checkoutSession->method('getQuote')->willReturn($quote);
    }
}
