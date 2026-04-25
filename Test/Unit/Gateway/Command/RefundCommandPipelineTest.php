<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Command\GatewayCommand;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;
use Shubo\BogPayment\Gateway\Http\TransferFactory;
use Shubo\BogPayment\Gateway\Request\RefundRequestBuilder;
use Shubo\BogPayment\Gateway\Response\RefundHandler;

/**
 * Session 8 Priority 1.1 — M-1 regression guard.
 *
 * Drives the actual {@see GatewayCommand} pipeline end-to-end using:
 *   - real {@see RefundRequestBuilder}
 *   - real {@see TransferFactory}
 *   - stub {@see ClientInterface} returning a BOG 400 envelope
 *     (`{"http_status": 400, "error_description": "..."}`)
 *   - real {@see RefundHandler} with a mocked {@see UserFacingErrorMapper}
 *
 * The pipeline MUST surface the friendly mapped {@see LocalizedException},
 * NOT Magento's generic `CommandException`. If a future change reintroduces
 * a validator on `ShuboBogRefundCommand` (etc/di.xml), the validator's
 * processErrors() call will short-circuit before reaching the handler and
 * this test will fail loudly.
 *
 * @see \Magento\Payment\Gateway\Command\GatewayCommand::execute()
 * @see app/code/Shubo/BogPayment/docs/online-refund-rca.md
 */
class RefundCommandPipelineTest extends TestCase
{
    private SubjectReader $subjectReader;
    private RefundHandler $handler;
    private UserFacingErrorMapper&MockObject $mapper;
    private LoggerInterface&MockObject $handlerLogger;
    private LoggerInterface&MockObject $gatewayLogger;

    protected function setUp(): void
    {
        $this->subjectReader = new SubjectReader();
        $this->mapper = $this->createMock(UserFacingErrorMapper::class);
        $this->handlerLogger = $this->createMock(LoggerInterface::class);
        $this->gatewayLogger = $this->createMock(LoggerInterface::class);

        $this->handler = new RefundHandler(
            subjectReader: $this->subjectReader,
            userFacingErrorMapper: $this->mapper,
            logger: $this->handlerLogger,
        );
    }

    /**
     * On a BOG HTTP 400 envelope, the friendly LocalizedException from
     * UserFacingErrorMapper MUST bubble up — not Magento's generic
     * CommandException "Transaction has been declined" default.
     */
    public function testHttp400SurfacesFriendlyMappedException(): void
    {
        $client = $this->stubClientReturning([
            'http_status' => 400,
            'bog_order_id' => 'BOG-XYZ-1',
            'error' => 'validation_failed',
            'error_description' => 'amount must be positive',
        ]);

        $friendly = new LocalizedException(__('Payment data is invalid. Please try again.'));
        $this->mapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(400, 'amount must be positive', 'validation_failed')
            ->willReturn($friendly);

        // The handler-side logger MUST log the raw triple at error level so
        // ops can correlate to BOG support.
        $this->handlerLogger->expects(self::once())
            ->method('error')
            ->with('BOG HTTP error mapped to user copy', self::callback(
                static fn (array $ctx): bool =>
                    ($ctx['http_status'] ?? null) === 400
                    && ($ctx['error_description'] ?? null) === 'amount must be positive'
                    && ($ctx['bog_order_id'] ?? null) === 'BOG-XYZ-1'
            ));

        $command = $this->buildCommand($client);

        $caught = null;
        try {
            $command->execute($this->buildSubject(grandTotal: 50.00, bogOrderId: 'BOG-XYZ-1'));
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Refund command must throw on BOG 400');
        self::assertNotInstanceOf(
            CommandException::class,
            $caught,
            'Pipeline must NOT raise Magento\'s generic CommandException — that '
            . 'means a validator preempted the handler. Drop the validator '
            . 'from ShuboBogRefundCommand (Session 8 Priority 1.1 / M-1).'
        );
        self::assertInstanceOf(
            LocalizedException::class,
            $caught,
            'Pipeline must surface a friendly LocalizedException from RefundHandler.',
        );
        self::assertSame(
            'Payment data is invalid. Please try again.',
            $caught->getMessage(),
            'Surfaced message must come from UserFacingErrorMapper, not Magento\'s default copy.',
        );
    }

    /**
     * Symmetric happy path — when BOG approves the refund, the pipeline
     * runs through the handler's persistence branch without throwing.
     */
    public function testHttp200RunsHandlerPersistenceWithoutThrow(): void
    {
        $client = $this->stubClientReturning([
            'http_status' => 200,
            'bog_order_id' => 'BOG-XYZ-2',
            'status' => 'Refunded',
            'refund_id' => 'r-pipeline-77',
        ]);

        $this->mapper->expects(self::never())->method('toLocalizedException');
        $this->handlerLogger->expects(self::never())->method('error');

        $command = $this->buildCommand($client);
        $subject = $this->buildSubject(grandTotal: 50.00, bogOrderId: 'BOG-XYZ-2');

        $command->execute($subject);
        // No exception = pass; persistence side-effects are covered by RefundHandlerTest.
        self::assertTrue(true);
    }

    private function buildCommand(ClientInterface $client): GatewayCommand
    {
        $config = $this->createMock(Config::class);
        $config->method('getEffectiveApiUrl')
            ->willReturn('https://api.bog.ge/payments/v1');

        $transferFactory = new TransferFactory(new TransferBuilder(), $config);
        $requestBuilder = new RefundRequestBuilder(subjectReader: $this->subjectReader);

        // M-1 regression contract: NO validator. RefundHandler is the sole gatekeeper.
        return new GatewayCommand(
            $requestBuilder,
            $transferFactory,
            $client,
            $this->gatewayLogger,
            $this->handler,
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function stubClientReturning(array $response): ClientInterface
    {
        return new class ($response) implements ClientInterface {
            /**
             * @param array<string, mixed> $response
             */
            public function __construct(private readonly array $response)
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function placeRequest(TransferInterface $transferObject): array
            {
                return $this->response;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSubject(float $grandTotal, string $bogOrderId): array
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAdditionalInformation',
                'setAdditionalInformation',
                'setTransactionId',
                'setIsTransactionClosed',
            ])
            ->getMock();
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn (string $key): ?string => $key === 'bog_order_id' ? $bogOrderId : null,
        );
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();

        $order = $this->createMock(OrderAdapterInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCurrencyCode')->willReturn('GEL');
        $order->method('getOrderIncrementId')->willReturn('000000042');

        $pdo = $this->createMock(PaymentDataObjectInterface::class);
        $pdo->method('getPayment')->willReturn($payment);
        $pdo->method('getOrder')->willReturn($order);

        return [
            'payment' => $pdo,
            'amount' => $grandTotal,
        ];
    }
}
