<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Controller\Adminhtml\Order\VoidPayment;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\ReversalClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * BUG-BOG-5: VoidPayment must call BOG's reversal API when
 * `payment_action_mode=manual` (preauth) and skip it under automatic capture.
 *
 * Coverage:
 *   1. automatic mode → no API call, success message, order cancelled
 *   2. manual mode + valid bog_order_id + BOG 200 → reversal called, success
 *   3a. manual mode + BOG "already cancelled" 4xx → benign: cancel order
 *       locally, log warning, admin sees "already released" success message
 *   3b. manual mode + BOG "already captured" 4xx → fail-closed with the
 *       actionable "refund instead" message; order NOT cancelled
 *   4. manual mode + missing bog_order_id → LocalizedException + admin error
 *   5. manual mode + 5xx network error → fail-closed, no order cancel,
 *      friendly retry message via UserFacingErrorMapper
 *   6. local idempotency: cancel_status=cancelled already set → skip API
 */
class VoidPaymentTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private MessageManagerInterface&MockObject $messageManager;
    private RedirectFactory&MockObject $redirectFactory;
    private RedirectResult&MockObject $redirectResult;
    private HttpRequest&MockObject $request;
    private LoggerInterface&MockObject $logger;
    private Context&MockObject $context;
    private Config&MockObject $config;
    private ReversalClient&MockObject $reversalClient;
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
        $this->reversalClient = $this->createMock(ReversalClient::class);
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

    public function testAutomaticModeSkipsApiAndCancelsOrder(): void
    {
        $payment = $this->makePayment(method: ConfigProvider::CODE);
        $payment->expects(self::once())
            ->method('setAdditionalInformation')
            ->with('preauth_approved', false);

        $order = $this->makeOrder($payment);
        $order->expects(self::once())->method('cancel');
        $order->expects(self::once())->method('addCommentToStatusHistory')
            ->with(self::stringContains('Card was not charged'));

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->config->method('getPaymentActionMode')->willReturn('automatic');

        // CRITICAL: ReversalClient must NOT be called under automatic mode.
        $this->reversalClient->expects(self::never())->method('reverse');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedSuccess);
        self::assertStringContainsString('voided', strtolower($this->capturedSuccess[0]));
        self::assertEmpty($this->capturedErrors);
    }

    public function testManualModeWithValidBogOrderIdCallsReversalAndCancels(): void
    {
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: [
                'bog_order_id' => 'BOG-XYZ-123',
                'cancel_status' => null,
            ],
        );
        $writes = [];
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function (string $k, $v) use (&$writes, $payment): Payment {
                $writes[$k] = $v;
                return $payment;
            }
        );

        $order = $this->makeOrder($payment, incrementId: '000000042');
        $order->expects(self::once())->method('cancel');
        $order->expects(self::once())->method('addCommentToStatusHistory')
            ->with(self::stringContains('action_id=action-uuid-aaa'));

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->config->method('getPaymentActionMode')->with(1)->willReturn('manual');

        $this->reversalClient->expects(self::once())
            ->method('reverse')
            ->with('BOG-XYZ-123', 1, self::stringContains('Void by admin'))
            ->willReturn([
                'key' => 'request_received',
                'message' => 'OK',
                'action_id' => 'action-uuid-aaa',
                'http_status' => 200,
                'bog_order_id' => 'BOG-XYZ-123',
            ]);

        $this->controller()->execute();

        self::assertSame(false, $writes['preauth_approved']);
        self::assertSame('cancelled', $writes['cancel_status']);
        self::assertSame('action-uuid-aaa', $writes['bog_cancel_action_id']);
        self::assertNotEmpty($this->capturedSuccess);
        self::assertStringContainsString('Authorization released', $this->capturedSuccess[0]);
    }

    public function testManualModeAlreadyCancelledIsBenign(): void
    {
        // BOG idempotency: a 4xx with "already cancelled" semantics means the
        // hold is gone either way. The controller logs WARNING, cancels the
        // Magento order, and shows a success-style message to the admin.
        $writes = [];
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: ['bog_order_id' => 'BOG-XYZ-456', 'cancel_status' => null],
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function (string $k, $v) use (&$writes, $payment): Payment {
                $writes[$k] = $v;
                return $payment;
            }
        );

        $order = $this->makeOrder($payment);
        $order->expects(self::once())->method('cancel');
        $order->expects(self::once())->method('addCommentToStatusHistory')
            ->with(self::stringContains('already released'));

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        $this->config->method('getPaymentActionMode')->willReturn('manual');

        $this->reversalClient->expects(self::once())
            ->method('reverse')
            ->willThrowException(new BogApiException(
                __('BOG reversal API returned HTTP 409 for bog_order_id=BOG-XYZ-456: Authorization already cancelled')
            ));

        // UserFacingErrorMapper MUST NOT be invoked on the benign branch.
        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');

        $this->controller()->execute();

        self::assertSame(false, $writes['preauth_approved']);
        self::assertSame('cancelled', $writes['cancel_status']);
        self::assertNotEmpty($this->capturedSuccess);
        self::assertStringContainsString('already released', $this->capturedSuccess[0]);
        self::assertEmpty($this->capturedErrors);
    }

    public function testManualModeAlreadyCapturedShowsRefundActionableError(): void
    {
        // "Already captured" is the dangerous 4xx — voiding it would lose
        // money that was already debited. Order MUST stay un-cancelled and
        // the admin MUST see the actionable "refund instead" copy.
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: ['bog_order_id' => 'BOG-CAP-1', 'cancel_status' => null],
        );
        $payment->expects(self::never())->method('setAdditionalInformation');

        $order = $this->makeOrder($payment);
        $order->expects(self::never())->method('cancel');

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->config->method('getPaymentActionMode')->willReturn('manual');

        $this->reversalClient->expects(self::once())
            ->method('reverse')
            ->willThrowException(new BogApiException(
                __('BOG reversal API returned HTTP 409 for bog_order_id=BOG-CAP-1: Authorization already captured')
            ));

        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('refund instead', strtolower($this->capturedErrors[0]));
        self::assertEmpty($this->capturedSuccess);
    }

    public function testManualModeMissingBogOrderIdShowsActionableError(): void
    {
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: ['bog_order_id' => '', 'cancel_status' => null],
        );

        $order = $this->makeOrder($payment);
        $order->expects(self::never())->method('cancel');

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->config->method('getPaymentActionMode')->willReturn('manual');

        // No reversal call should be attempted.
        $this->reversalClient->expects(self::never())->method('reverse');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('No BOG order ID', $this->capturedErrors[0]);
        self::assertEmpty($this->capturedSuccess);
    }

    public function testManualModeNetworkErrorDoesNotCancelOrder(): void
    {
        // 5xx / transport failure from the client comes through as
        // BogApiException too — same admin-facing taxonomy.
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: ['bog_order_id' => 'BOG-NET-500', 'cancel_status' => null],
        );
        $order = $this->makeOrder($payment);
        $order->expects(self::never())->method('cancel');

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->config->method('getPaymentActionMode')->willReturn('manual');

        $this->reversalClient->expects(self::once())
            ->method('reverse')
            ->willThrowException(new BogApiException(
                __('Unable to reverse payment via BOG Payments API.')
            ));

        $this->userFacingErrorMapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(0, self::anything())
            ->willReturn(new LocalizedException(__('Reversal failed — please retry.')));

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('retry', strtolower($this->capturedErrors[0]));
    }

    public function testLocalIdempotencySkipsApiWhenAlreadyCancelled(): void
    {
        // Admin clicked Void twice. Second click sees cancel_status=cancelled
        // already set on payment additional information — skip the BOG round
        // trip; admin sees the safe success message.
        $payment = $this->makePayment(
            method: ConfigProvider::CODE,
            additionalInfo: [
                'bog_order_id' => 'BOG-IDEM-1',
                'cancel_status' => 'cancelled',
            ],
        );
        $payment->expects(self::never())->method('setAdditionalInformation');

        $order = $this->makeOrder($payment);
        $order->expects(self::never())->method('cancel');

        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $this->config->method('getPaymentActionMode')->willReturn('manual');

        $this->reversalClient->expects(self::never())->method('reverse');

        $this->controller()->execute();

        self::assertNotEmpty($this->capturedSuccess);
        self::assertStringContainsString('already', strtolower($this->capturedSuccess[0]));
        self::assertEmpty($this->capturedErrors);
    }

    private function controller(): VoidPayment
    {
        return new VoidPayment(
            $this->context,
            $this->orderRepository,
            $this->logger,
            $this->config,
            $this->reversalClient,
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

    private function makeOrder(?Payment $payment, string $incrementId = '000000042'): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn($incrementId);
        return $order;
    }
}
