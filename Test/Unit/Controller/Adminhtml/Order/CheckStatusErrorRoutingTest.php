<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Controller\Adminhtml\Order\CheckStatus;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Model\Ui\ConfigProvider;

/**
 * Session 8 Pass-1 reviewer S-1 regression guard.
 *
 * Mirrors TBC Session 3 Pass-4 S-4 close-out (admin must NEVER see raw
 * exception text from the generic catch). Three branches:
 *   1. BogApiException → routed through UserFacingErrorMapper.
 *   2. LocalizedException → message flows through (Magento author-safe).
 *   3. Generic \Exception → bland message, raw text logged only.
 */
class CheckStatusErrorRoutingTest extends TestCase
{
    /** @var list<string> */
    private array $capturedErrors = [];
    private Context&MockObject $context;
    private RequestInterface&MockObject $request;
    private MessageManager&MockObject $messageManager;
    private RedirectFactory&MockObject $redirectFactory;
    private Redirect&MockObject $redirectResult;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private StatusClient&MockObject $statusClient;
    private Config&MockObject $config;
    private OrderSender&MockObject $orderSender;
    private LoggerInterface&MockObject $logger;
    private UserFacingErrorMapper&MockObject $userFacingErrorMapper;

    protected function setUp(): void
    {
        $this->capturedErrors = [];
        $this->context = $this->createMock(Context::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->messageManager = $this->createMock(MessageManager::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->redirectResult = $this->createMock(Redirect::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->statusClient = $this->createMock(StatusClient::class);
        $this->config = $this->createMock(Config::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userFacingErrorMapper = $this->createMock(UserFacingErrorMapper::class);

        $this->request->method('getParam')->with('order_id')->willReturn('42');
        $this->context->method('getRequest')->willReturn($this->request);
        $this->context->method('getMessageManager')->willReturn($this->messageManager);
        $this->context->method('getResultRedirectFactory')->willReturn($this->redirectFactory);
        $this->redirectFactory->method('create')->willReturn($this->redirectResult);
        $this->redirectResult->method('setPath')->willReturnSelf();

        $this->messageManager->method('addErrorMessage')->willReturnCallback(
            function (string $msg): MessageManager {
                $this->capturedErrors[] = $msg;
                return $this->messageManager;
            }
        );
        // Other addXxxMessage variants — no-op pass-through.
        $this->messageManager->method('addSuccessMessage')->willReturnSelf();
        $this->messageManager->method('addWarningMessage')->willReturnSelf();
    }

    public function testBogApiExceptionRoutesThroughMapper(): void
    {
        $order = $this->makeOrderWithBogPayment();
        $this->orderRepository->method('get')->willReturn($order);
        $this->statusClient->method('checkStatus')
            ->willThrowException(new BogApiException(__('Sandbox unreachable')));

        $friendly = new LocalizedException(__('Could not reach the payment system. Please try again in a moment.'));
        $this->userFacingErrorMapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(0, 'Sandbox unreachable')
            ->willReturn($friendly);

        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->with('BOG HTTP error mapped to user copy', self::callback(
                static fn (array $ctx): bool =>
                    ($ctx['context'] ?? null) === 'admin.checkstatus'
                    && ($ctx['raw_message'] ?? null) === 'Sandbox unreachable'
            ));

        $this->buildController()->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertSame(
            'Could not reach the payment system. Please try again in a moment.',
            $this->capturedErrors[0],
        );
        // Critical: the raw "Sandbox unreachable" string must NOT appear.
        self::assertStringNotContainsString('Sandbox unreachable', $this->capturedErrors[0]);
    }

    public function testLocalizedExceptionFlowsThroughVerbatim(): void
    {
        $order = $this->makeOrderWithBogPayment();
        $this->orderRepository->method('get')->willReturn($order);
        $this->statusClient->method('checkStatus')->willThrowException(
            new LocalizedException(__('Order is in an invalid state.'))
        );

        // Mapper must NOT be invoked for LocalizedException.
        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');

        $this->buildController()->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertSame('Order is in an invalid state.', $this->capturedErrors[0]);
    }

    public function testGenericExceptionDoesNotLeakRawMessage(): void
    {
        $order = $this->makeOrderWithBogPayment();
        $this->orderRepository->method('get')->willReturn($order);
        // Generic exception with a sensitive-looking message.
        $secretLeak = 'Internal trace: secret_token=abc123, db_password=xyz';
        $this->statusClient->method('checkStatus')
            ->willThrowException(new \RuntimeException($secretLeak));

        // Mapper is NOT used for generic Exception (logged + bland message).
        $this->userFacingErrorMapper->expects(self::never())->method('toLocalizedException');

        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->with('BOG admin status check failed', self::callback(
                static fn (array $ctx): bool =>
                    ($ctx['error'] ?? null) === 'Internal trace: secret_token=abc123, db_password=xyz'
                    && ($ctx['exception_class'] ?? null) === \RuntimeException::class
            ));

        $this->buildController()->execute();

        self::assertNotEmpty($this->capturedErrors);
        self::assertSame(
            'Status check failed. See shubo_bog_payment.log for details.',
            $this->capturedErrors[0],
        );
        self::assertStringNotContainsString('secret_token', $this->capturedErrors[0]);
        self::assertStringNotContainsString('abc123', $this->capturedErrors[0]);
        self::assertStringNotContainsString('db_password', $this->capturedErrors[0]);
    }

    private function makeOrderWithBogPayment(): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);
        $payment->method('getAdditionalInformation')->willReturn('BOG-XYZ');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000042');
        return $order;
    }

    private function buildController(): CheckStatus
    {
        return new CheckStatus(
            $this->context,
            $this->orderRepository,
            $this->statusClient,
            $this->config,
            $this->orderSender,
            $this->logger,
            $this->userFacingErrorMapper,
        );
    }
}
