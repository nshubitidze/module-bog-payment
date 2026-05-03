<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Controller\Adminhtml\Order\Capture;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\CaptureClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Session 18 (BUG-BOG-CAPTURE-AFTER-AUTH): the admin "Capture Payment"
 * button must be mode-aware. Under `payment_action_mode=automatic` the
 * customer is already charged-and-captured at payment time via the BOG
 * create-order callback, so the controller must NOT call BOG (and must
 * NOT call registerCaptureNotification — the invoice already exists,
 * double-firing raises a duplicate-invoice exception). Under
 * `payment_action_mode=manual` (preauth), the controller calls BOG's
 * preauth-capture endpoint and registers a Magento capture notification.
 *
 * Failure-mode coverage (matches the design-doc taxonomy table):
 *   1. automatic mode → no BOG call, no registerCaptureNotification, admin
 *      sees informational "already captured at payment time" message
 *   2. manual mode + valid bog_order_id + BOG 200 → capture called,
 *      registerCaptureNotification fires once, capture_status set,
 *      status-history comment added, order saved
 *   3. manual mode + BOG "already captured" 4xx → benign: capture_status
 *      set idempotently, NO registerCaptureNotification (would double-
 *      invoice), admin sees success-style "already captured" message
 *   4. manual mode + missing bog_order_id → LocalizedException + admin
 *      error, no BOG call, no save
 *   5. manual mode + 5xx network error → fail-closed: no state change,
 *      no save, admin sees retry copy via UserFacingErrorMapper
 *   6. wrong payment method (BUG-BOG-4 regression) → guard 1 short-circuits
 *      before the mode check; no BOG call, no mapper call
 *   7. local idempotency: capture_status=captured already set → skip API
 */
class CaptureTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private MessageManagerInterface&MockObject $messageManager;
    private RedirectFactory&MockObject $redirectFactory;
    private RedirectResult&MockObject $redirectResult;
    private HttpRequest&MockObject $request;
    private LoggerInterface&MockObject $logger;
    private Context&MockObject $context;
    private Config&MockObject $config;
    private CaptureClient&MockObject $captureClient;
    private UserFacingErrorMapper&MockObject $userFacingErrorMapper;

    /** @var list<string> */
    private array $capturedErrors = [];
    /** @var list<string> */
    private array $capturedSuccess = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->messageManager = $this->createMock(MessageManagerInterface::class);
        $this->redirectResult = $this->createMock(RedirectResult::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->captureClient = $this->createMock(CaptureClient::class);
        $this->userFacingErrorMapper = $this->createMock(UserFacingErrorMapper::class);

        $this->redirectResult->method('setPath')->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($this->redirectResult);
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k): mixed => $k === 'order_id' ? 42 : null
        );

        $this->messageManager->method('addErrorMessage')->willReturnCallback(
            function (string $m): MessageManagerInterface {
                $this->capturedErrors[] = $m;
                return $this->messageManager;
            }
        );
        $this->messageManager->method('addSuccessMessage')->willReturnCallback(
            function (string $m): MessageManagerInterface {
                $this->capturedSuccess[] = $m;
                return $this->messageManager;
            }
        );

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);
        $this->context->method('getMessageManager')->willReturn($this->messageManager);
        $this->context->method('getResultRedirectFactory')->willReturn($this->redirectFactory);
    }

    public function testAutomaticModeSkipsApiAndShowsAlreadyCaptured(): void
    {
        // Default automatic mode: customer was already captured via the
        // create-order callback. Controller must NOT call BOG, NOT call
        // registerCaptureNotification, NOT change order state, NOT add a
        // status-history comment.
        $payment = $this->makePayment(method: ConfigProvider::CODE);
        $payment->expects(self::never())->method('setAdditionalInformation');
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->makeOrder($payment);
        $order->expects(self::never())->method('addCommentToStatusHistory');

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->config->method('getPaymentActionMode')->willReturn('automatic');

        // CRITICAL: CaptureClient must NOT be called under automatic mode.
        $this->captureClient->expects(self::never())->method('capture');
        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedSuccess);
        self::assertStringContainsString('already captured', strtolower($this->capturedSuccess[0]));
        self::assertEmpty($this->capturedErrors);
    }

    public function testManualModeWithValidBogOrderIdCallsCaptureAndRegistersNotification(): void
    {
        // Preauth happy path: BOG returns success → invoice created via
        // registerCaptureNotification (fires once), capture_status set,
        // preauth_approved cleared, status-history comment added.
        $writes = [];
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: [
                'bog_order_id' => 'BOG-CAP-1',
                'capture_status' => null,
            ],
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function (string $k, $v) use (&$writes, $payment): Payment {
                $writes[$k] = $v;
                return $payment;
            }
        );
        $payment->expects(self::once())
            ->method('registerCaptureNotification')
            ->with(self::callback(static fn (float $a): bool => abs($a - 10.50) < 0.001));

        $order = $this->makeOrder($payment, incrementId: '000000042', grandTotal: '10.50');
        $order->expects(self::once())
            ->method('addCommentToStatusHistory')
            ->with(self::stringContains('Payment captured by BOG'));

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->config->method('getPaymentActionMode')->with(1)->willReturn('manual');

        $this->captureClient->expects(self::once())
            ->method('capture')
            ->with(
                'BOG-CAP-1',
                1,
                self::callback(static fn (float $a): bool => abs($a - 10.50) < 0.001),
                self::stringContains('Capture for order'),
            )
            ->willReturn([
                'order_status' => ['key' => 'captured'],
            ]);

        $this->controller()->execute();

        self::assertSame(false, $writes['preauth_approved']);
        self::assertSame('captured', $writes['capture_status']);
        self::assertNotEmpty($this->capturedSuccess);
        self::assertStringContainsString('captured successfully', strtolower($this->capturedSuccess[0]));
        self::assertEmpty($this->capturedErrors);
    }

    public function testManualModeAlreadyCapturedIsBenign(): void
    {
        // BOG idempotency: a 4xx with "already captured" semantics means the
        // capture happened either way. The controller logs WARNING, sets
        // capture_status idempotently, saves the additional_information
        // change, and shows a success-style message. CRITICAL: NO
        // registerCaptureNotification call — the invoice already exists and
        // re-firing would raise a duplicate-invoice exception.
        $writes = [];
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: [
                'bog_order_id' => 'BOG-CAP-2',
                'capture_status' => null,
            ],
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function (string $k, $v) use (&$writes, $payment): Payment {
                $writes[$k] = $v;
                return $payment;
            }
        );
        // CRITICAL: must NOT double-invoice.
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->makeOrder($payment, grandTotal: '25.00');
        $order->expects(self::never())->method('addCommentToStatusHistory');

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->config->method('getPaymentActionMode')->willReturn('manual');

        $this->captureClient->expects(self::once())
            ->method('capture')
            ->willThrowException(new BogApiException(
                __('BOG capture API returned HTTP 409 for bog_order_id=BOG-CAP-2: Authorization already captured')
            ));

        // UserFacingErrorMapper MUST NOT be invoked on the benign branch.
        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');

        $this->controller()->execute();

        self::assertSame(false, $writes['preauth_approved']);
        self::assertSame('captured', $writes['capture_status']);
        self::assertNotEmpty($this->capturedSuccess);
        self::assertStringContainsString('already captured', strtolower($this->capturedSuccess[0]));
        self::assertEmpty($this->capturedErrors);
    }

    public function testManualModeMissingBogOrderIdShowsActionableError(): void
    {
        // Guard 3 — without bog_order_id we cannot call BOG; admin sees a
        // verbatim LocalizedException message so they know which order is
        // misconfigured.
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: [
                'bog_order_id' => '',
                'capture_status' => null,
            ],
        );
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->makeOrder($payment);

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->config->method('getPaymentActionMode')->willReturn('manual');

        // No capture call should be attempted.
        $this->captureClient->expects(self::never())->method('capture');
        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('No BOG order ID', $this->capturedErrors[0]);
        self::assertEmpty($this->capturedSuccess);
    }

    public function testManualModeNetworkErrorDoesNotMarkCaptured(): void
    {
        // Generic 5xx / transport / malformed: order state and capture_status
        // MUST stay untouched. Cron reconciler / next click will sort it out.
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: [
                'bog_order_id' => 'BOG-NET-500',
                'capture_status' => null,
            ],
        );
        // No state changes on the failure path — no setAdditionalInformation,
        // no registerCaptureNotification.
        $payment->expects(self::never())->method('setAdditionalInformation');
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->makeOrder($payment, grandTotal: '5.00');
        $order->expects(self::never())->method('addCommentToStatusHistory');

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->config->method('getPaymentActionMode')->willReturn('manual');

        $this->captureClient->expects(self::once())
            ->method('capture')
            ->willThrowException(new BogApiException(
                __('Unable to capture payment via BOG Payments API.')
            ));

        $this->userFacingErrorMapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(0, self::anything())
            ->willReturn(new LocalizedException(__('Capture failed — please retry.')));

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('retry', strtolower($this->capturedErrors[0]));
        self::assertEmpty($this->capturedSuccess);
    }

    public function testWrongPaymentMethodIsRejected(): void
    {
        // BUG-BOG-4 regression: the payment method guard is FIRST. A
        // PayPal order on a manual-mode store must be refused before any
        // mode check, BOG call, or mapper invocation.
        $payment = $this->makePayment(method: 'paypal_express');
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->makeOrder($payment);
        $order->expects(self::never())->method('addCommentToStatusHistory');

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        // Mode is irrelevant — guard 1 short-circuits before the mode check.
        $this->captureClient->expects(self::never())->method('capture');
        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('Invalid payment method', $this->capturedErrors[0]);
        self::assertEmpty($this->capturedSuccess);
    }

    public function testLocalIdempotencySkipsApiWhenCaptureStatusAlreadyCaptured(): void
    {
        // Admin clicked Capture twice. Second click sees capture_status=
        // 'captured' already set on payment additional information — skip
        // the BOG round-trip; admin sees the safe success message.
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: [
                'bog_order_id' => 'BOG-IDEM-1',
                'capture_status' => 'captured',
            ],
        );
        $payment->expects(self::never())->method('setAdditionalInformation');
        $payment->expects(self::never())->method('registerCaptureNotification');

        $order = $this->makeOrder($payment);
        $order->expects(self::never())->method('addCommentToStatusHistory');

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->config->method('getPaymentActionMode')->willReturn('manual');

        $this->captureClient->expects(self::never())->method('capture');
        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedSuccess);
        self::assertStringContainsString('already captured', strtolower($this->capturedSuccess[0]));
        self::assertEmpty($this->capturedErrors);
    }

    private function controller(): Capture
    {
        return new Capture(
            $this->context,
            $this->orderRepository,
            $this->logger,
            $this->config,
            $this->captureClient,
            $this->userFacingErrorMapper,
        );
    }

    /**
     * @param array<string, mixed>|null $additionalInfo Map of additional_information values
     */
    private function makePayment(?string $method, ?array $additionalInfo = null): Payment&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $k): mixed => $additionalInfo[$k] ?? null
        );
        return $payment;
    }

    private function makeOrder(
        ?Payment $payment,
        string $incrementId = '000000042',
        string $grandTotal = '10.00',
        string $currency = 'GEL',
    ): Order&MockObject {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn($currency);
        return $order;
    }
}
