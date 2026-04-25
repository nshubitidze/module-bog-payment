<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;
use Shubo\BogPayment\Gateway\Request\RefundRequestBuilder;

class RefundRequestBuilderTest extends TestCase
{
    private RefundRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RefundRequestBuilder(new SubjectReader());
    }

    public function testBuildsBodyWithBogOrderIdAndBcmathAmount(): void
    {
        $payment = $this->buildPayment(bogOrderId: 'BOG-XYZ-1');

        $body = $this->builder->build([
            'payment' => $this->buildPaymentSubject($payment),
            'amount' => 10.5,
        ]);

        self::assertSame('BOG-XYZ-1', $body['order_id']);
        self::assertSame('10.50', $body['amount']);
    }

    public function testRoundingFloorsHalfDownNotHalfEven(): void
    {
        $payment = $this->buildPayment(bogOrderId: 'BOG-1');

        $body = $this->builder->build([
            'payment' => $this->buildPaymentSubject($payment),
            'amount' => 10.005,
        ]);

        // bcadd($x, '0', 2) truncates the third decimal — never rounds half-up.
        self::assertSame('10.00', $body['amount']);
    }

    public function testEmptyBogOrderIdThrowsActionableException(): void
    {
        $payment = $this->buildPayment(bogOrderId: '');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Cannot refund order 000000077');

        $this->builder->build([
            'payment' => $this->buildPaymentSubject($payment, orderIncrement: '000000077'),
            'amount' => 5.0,
        ]);
    }

    public function testNullBogOrderIdThrowsActionableException(): void
    {
        $payment = $this->buildPayment(bogOrderId: null);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('bog_order_id is missing');

        $this->builder->build([
            'payment' => $this->buildPaymentSubject($payment, orderIncrement: '000000099'),
            'amount' => 1.0,
        ]);
    }

    private function buildPayment(?string $bogOrderId): Payment&MockObject
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAdditionalInformation'])
            ->getMock();
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static function (string $key) use ($bogOrderId): ?string {
                return match ($key) {
                    'bog_order_id' => $bogOrderId,
                    default => null,
                };
            }
        );
        return $payment;
    }

    private function buildPaymentSubject(
        Payment $payment,
        string $orderIncrement = '000000042',
    ): PaymentDataObjectInterface {
        $order = $this->createMock(OrderAdapterInterface::class);
        $order->method('getOrderIncrementId')->willReturn($orderIncrement);

        $pdo = $this->createMock(PaymentDataObjectInterface::class);
        $pdo->method('getPayment')->willReturn($payment);
        $pdo->method('getOrder')->willReturn($order);
        return $pdo;
    }
}
