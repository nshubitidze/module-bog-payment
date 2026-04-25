<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Gateway\Response;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;
use Shubo\BogPayment\Gateway\Response\RefundHandler;

/**
 * Session 8 Priority 1.1 — RefundHandler must:
 *   - On 2xx: persist refund metadata + close the transaction.
 *   - On non-2xx: log the raw triple at ERROR + throw the friendly
 *     LocalizedException returned by UserFacingErrorMapper.
 */
class RefundHandlerTest extends TestCase
{
    private SubjectReader $subjectReader;
    private UserFacingErrorMapper&MockObject $mapper;
    private LoggerInterface&MockObject $logger;
    private RefundHandler $handler;

    protected function setUp(): void
    {
        $this->subjectReader = new SubjectReader();
        $this->mapper = $this->createMock(UserFacingErrorMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new RefundHandler(
            subjectReader: $this->subjectReader,
            userFacingErrorMapper: $this->mapper,
            logger: $this->logger,
        );
    }

    public function testHttp200PersistsRefundMetadata(): void
    {
        $payment = $this->buildPayment();
        // Two setAdditionalInformation calls: bog_refund_status + bog_refund_id.
        $captured = [];
        $payment->expects(self::exactly(2))
            ->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, mixed $value) use ($payment, &$captured): Payment {
                $captured[$key] = $value;
                return $payment;
            });
        $payment->expects(self::once())
            ->method('setTransactionId')
            ->with('refund-123')
            ->willReturnSelf();
        $payment->expects(self::once())
            ->method('setIsTransactionClosed')
            ->with(true)
            ->willReturnSelf();

        // Mapper must NEVER be invoked on success.
        $this->mapper->expects(self::never())->method('toLocalizedException');
        // Logger must NEVER write the error line on success.
        $this->logger->expects(self::never())->method('error');

        $subject = $this->buildSubject($payment);
        $response = [
            'http_status' => 200,
            'bog_order_id' => 'BOG-XYZ',
            'status' => 'Refunded',
            'refund_id' => 'refund-123',
        ];

        $this->handler->handle($subject, $response);

        self::assertSame('Refunded', $captured['bog_refund_status'] ?? null);
        self::assertSame('refund-123', $captured['bog_refund_id'] ?? null);
    }

    public function testHttp4xxLogsRawTripleAndThrowsMappedException(): void
    {
        $payment = $this->buildPayment();
        $payment->expects(self::never())->method('setAdditionalInformation');
        $payment->expects(self::never())->method('setIsTransactionClosed');

        $friendly = new LocalizedException(__('Payment data is invalid. Please try again.'));
        $this->mapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(400, 'amount exceeds available balance', null)
            ->willReturn($friendly);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'BOG HTTP error mapped to user copy',
                self::callback(static function (array $ctx): bool {
                    return ($ctx['context'] ?? null) === 'refund.handler'
                        && ($ctx['http_status'] ?? null) === 400
                        && ($ctx['error_description'] ?? null) === 'amount exceeds available balance'
                        && ($ctx['bog_order_id'] ?? null) === 'BOG-XYZ'
                        && ($ctx['order_increment_id'] ?? null) === '000000042';
                })
            );

        $subject = $this->buildSubject($payment);
        $response = [
            'http_status' => 400,
            'bog_order_id' => 'BOG-XYZ',
            'error_description' => 'amount exceeds available balance',
        ];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Payment data is invalid. Please try again.');
        $this->handler->handle($subject, $response);
    }

    public function testHttp500RoutesErrorCodeToMapper(): void
    {
        $payment = $this->buildPayment();
        $friendly = new LocalizedException(__('Bank payment system temporarily unavailable.'));
        $this->mapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(503, 'upstream timeout', 'gateway_unavailable')
            ->willReturn($friendly);
        $this->logger->expects(self::once())->method('error');

        $subject = $this->buildSubject($payment);
        $response = [
            'http_status' => 503,
            'bog_order_id' => 'BOG-XYZ',
            'message' => 'upstream timeout',
            'error' => 'gateway_unavailable',
        ];

        $this->expectException(LocalizedException::class);
        $this->handler->handle($subject, $response);
    }

    public function testZeroHttpStatusRoutesAsNetworkError(): void
    {
        $payment = $this->buildPayment();
        $friendly = new LocalizedException(__('Could not reach the payment system.'));
        $this->mapper->expects(self::once())
            ->method('toLocalizedException')
            ->with(0, '', null)
            ->willReturn($friendly);
        $this->logger->expects(self::once())->method('error');

        $subject = $this->buildSubject($payment);
        $response = [
            'http_status' => 0,
            'bog_order_id' => 'BOG-XYZ',
            'raw_body' => '',
        ];

        $this->expectException(LocalizedException::class);
        $this->handler->handle($subject, $response);
    }

    public function testIdFieldFallsBackWhenRefundIdMissing(): void
    {
        $payment = $this->buildPayment();
        $payment->expects(self::once())
            ->method('setTransactionId')
            ->with('alt-id-456')
            ->willReturnSelf();
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();

        $this->mapper->expects(self::never())->method('toLocalizedException');
        $this->logger->expects(self::never())->method('error');

        $subject = $this->buildSubject($payment);
        $response = [
            'http_status' => 200,
            'bog_order_id' => 'BOG-XYZ',
            'status' => 'Refunded',
            'id' => 'alt-id-456',
        ];

        $this->handler->handle($subject, $response);
    }

    /**
     * Regression guard for Session 8 P3.1 — handler must NEVER call
     * setParentTransactionId. Direct refund creates a clean root entry.
     */
    public function testHandlerDoesNotSetParentTransactionId(): void
    {
        $payment = $this->buildPayment();
        $payment->method('setAdditionalInformation')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();
        $payment->expects(self::never())->method('setParentTransactionId');

        $subject = $this->buildSubject($payment);
        $response = [
            'http_status' => 200,
            'bog_order_id' => 'BOG-XYZ',
            'status' => 'Refunded',
            'refund_id' => 'r-1',
        ];

        $this->handler->handle($subject, $response);
    }

    /**
     * @return Payment&MockObject
     */
    private function buildPayment(): Payment&MockObject
    {
        return $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setAdditionalInformation',
                'setTransactionId',
                'setIsTransactionClosed',
                'setParentTransactionId',
            ])
            ->getMock();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSubject(Payment $payment): array
    {
        $order = $this->createMock(OrderAdapterInterface::class);
        $order->method('getOrderIncrementId')->willReturn('000000042');

        $pdo = $this->createMock(PaymentDataObjectInterface::class);
        $pdo->method('getPayment')->willReturn($payment);
        $pdo->method('getOrder')->willReturn($order);

        return ['payment' => $pdo, 'amount' => 10.50];
    }
}
