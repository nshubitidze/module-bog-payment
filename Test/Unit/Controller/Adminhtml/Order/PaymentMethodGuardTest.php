<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Controller\Adminhtml\Order\Capture;
use Shubo\BogPayment\Controller\Adminhtml\Order\CheckStatus;
use Shubo\BogPayment\Controller\Adminhtml\Order\VoidPayment;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Http\Client\CaptureClient;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Regression tests for BUG-BOG-4: every BOG admin action controller
 * (Capture, VoidPayment, CheckStatus) must refuse to operate on an order whose
 * payment method is not shubo_bog. Without this guard the admin buttons become
 * a generic void/capture backdoor for every order in the store -- pressing
 * "Capture BOG Payment" on a PayPal order would happily call BOG's capture API.
 *
 * The guard must:
 *   - raise a user-facing error when payment->getMethod() !== shubo_bog
 *   - raise the same error when the order has no payment at all
 *   - NOT touch the order or call any gateway client
 *
 * Mirrors Shubo\TbcPayment\Test\Unit\Controller\Adminhtml\Order\PaymentMethodGuardTest.
 */
class PaymentMethodGuardTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private MessageManagerInterface&MockObject $messageManager;
    private RedirectFactory&MockObject $redirectFactory;
    private RedirectResult&MockObject $redirectResult;
    private HttpRequest&MockObject $request;
    private LoggerInterface&MockObject $logger;
    private Context&MockObject $context;

    /** @var list<string> */
    private array $capturedErrors = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->messageManager  = $this->createMock(MessageManagerInterface::class);
        $this->redirectResult  = $this->createMock(RedirectResult::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->request         = $this->createMock(HttpRequest::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->redirectResult->method('setPath')->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($this->redirectResult);
        $this->request->method('getParam')->willReturnCallback(static fn (string $k): mixed
            => $k === 'order_id' ? 42 : null);

        $this->messageManager->method('addErrorMessage')
            ->willReturnCallback(function (string $m): MessageManagerInterface {
                $this->capturedErrors[] = $m;
                return $this->messageManager;
            });

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);
        $this->context->method('getMessageManager')->willReturn($this->messageManager);
        $this->context->method('getResultRedirectFactory')->willReturn($this->redirectFactory);
    }

    public function testCaptureRefusesWrongPaymentMethod(): void
    {
        $order = $this->makeOrder(method: 'checkmo');
        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $captureClient = $this->createMock(CaptureClient::class);
        $captureClient->expects(self::never())->method('capture');

        $controller = new Capture(
            $this->context,
            $this->orderRepository,
            $captureClient,
            $this->logger,
            $this->createMock(UserFacingErrorMapper::class),
        );

        $controller->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('Invalid payment method', $this->capturedErrors[0]);
    }

    public function testCaptureRefusesNullPayment(): void
    {
        $order = $this->makeOrder(method: null);
        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $captureClient = $this->createMock(CaptureClient::class);
        $captureClient->expects(self::never())->method('capture');

        $controller = new Capture(
            $this->context,
            $this->orderRepository,
            $captureClient,
            $this->logger,
            $this->createMock(UserFacingErrorMapper::class),
        );

        $controller->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('Invalid payment method', $this->capturedErrors[0]);
    }

    public function testVoidPaymentRefusesWrongPaymentMethod(): void
    {
        $order = $this->makeOrder(method: 'paypal_express');
        $order->expects(self::never())->method('cancel');
        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $controller = new VoidPayment(
            $this->context,
            $this->orderRepository,
            $this->logger,
        );

        $controller->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('Invalid payment method', $this->capturedErrors[0]);
    }

    public function testVoidPaymentRefusesNullPayment(): void
    {
        $order = $this->makeOrder(method: null);
        $order->expects(self::never())->method('cancel');
        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $controller = new VoidPayment(
            $this->context,
            $this->orderRepository,
            $this->logger,
        );

        $controller->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('Invalid payment method', $this->capturedErrors[0]);
    }

    public function testCheckStatusRefusesWrongPaymentMethod(): void
    {
        $order = $this->makeOrder(method: 'free');
        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $statusClient = $this->createMock(StatusClient::class);
        $statusClient->expects(self::never())->method('checkStatus');

        $controller = new CheckStatus(
            $this->context,
            $this->orderRepository,
            $statusClient,
            $this->createMock(Config::class),
            $this->createMock(OrderSender::class),
            $this->logger,
            $this->createMock(UserFacingErrorMapper::class),
        );

        $controller->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('Invalid payment method', $this->capturedErrors[0]);
    }

    public function testCheckStatusRefusesNullPayment(): void
    {
        $order = $this->makeOrder(method: null);
        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');

        $statusClient = $this->createMock(StatusClient::class);
        $statusClient->expects(self::never())->method('checkStatus');

        $controller = new CheckStatus(
            $this->context,
            $this->orderRepository,
            $statusClient,
            $this->createMock(Config::class),
            $this->createMock(OrderSender::class),
            $this->logger,
            $this->createMock(UserFacingErrorMapper::class),
        );

        $controller->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('Invalid payment method', $this->capturedErrors[0]);
    }

    public function testCorrectPaymentMethodPassesGuard(): void
    {
        // Positive case on Capture: method == shubo_bog + empty bog_order_id
        // triggers the next guard ("No BOG order ID"), proving the method
        // guard did NOT fire.
        $order = $this->makeOrder(method: ConfigProvider::CODE);
        $this->orderRepository->expects(self::once())->method('get')->with(42)->willReturn($order);

        $captureClient = $this->createMock(CaptureClient::class);
        $captureClient->expects(self::never())->method('capture');

        $controller = new Capture(
            $this->context,
            $this->orderRepository,
            $captureClient,
            $this->logger,
            $this->createMock(UserFacingErrorMapper::class),
        );

        $controller->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertStringContainsString('No BOG order ID', $this->capturedErrors[0]);
    }

    private function makeOrder(?string $method): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        if ($method === null) {
            $order->method('getPayment')->willReturn(null);
            return $order;
        }
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')->willReturn('');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        return $order;
    }
}
