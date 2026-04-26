<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Model\Ui\ConfigProvider;
use Shubo\BogPayment\Observer\SetPendingPaymentState;

/**
 * Covers {@see SetPendingPaymentState} for the BOG payment method.
 *
 * Wired to `sales_order_place_after`. Because BOG does not authorize the card
 * during order placement (the actual payment happens externally via the BOG
 * REST API), we explicitly transition the order to `pending_payment` so the
 * rest of the pipeline (cron reconciler, callback, return controller) has a
 * stable state to advance from.
 *
 * Failure semantics: the observer MUST NOT throw. Order placement succeeds
 * regardless of whether the state mutation succeeds; the payment callback or
 * cron reconciler will correct any drift.
 */
class SetPendingPaymentStateTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private SetPendingPaymentState $observer;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new SetPendingPaymentState(logger: $this->logger);
    }

    public function testBogOrderTransitionsToPendingPayment(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->expects(self::once())->method('setState')->with(Order::STATE_PENDING_PAYMENT);
        $order->expects(self::once())->method('setStatus')->with('pending_payment');

        $this->logger->expects(self::never())->method(self::anything());

        $this->observer->execute($this->buildEventObserver($order));
    }

    public function testNoOpsWhenPaymentMethodIsNotBog(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('checkmo');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->expects(self::never())->method('setState');
        $order->expects(self::never())->method('setStatus');

        $this->observer->execute($this->buildEventObserver($order));
    }

    public function testNoOpsWhenOrderHasNoPayment(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn(null);
        $order->expects(self::never())->method('setState');
        $order->expects(self::never())->method('setStatus');

        $this->observer->execute($this->buildEventObserver($order));
    }

    public function testNoOpsWhenEventLacksOrder(): void
    {
        $event = new Event(['order' => null]);
        $eventObserver = new Observer();
        $eventObserver->setEvent($event);

        $this->logger->expects(self::never())->method(self::anything());

        $this->observer->execute($eventObserver);
    }

    public function testStateMutationFailureIsLoggedAndSwallowed(): void
    {
        // If anything goes wrong inside the state transition (e.g. the order
        // model rejects the new state, a behaviour observer downstream throws),
        // the error must be logged with order context and the exception MUST
        // NOT propagate -- order placement must succeed regardless.
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn(ConfigProvider::CODE);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('100000099');
        $order->method('setState')
            ->willThrowException(new \RuntimeException('locked'));

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('BOG: Failed to set pending_payment state'),
                self::callback(static function (array $ctx): bool {
                    return ($ctx['order_id'] ?? null) === '100000099'
                        && ($ctx['error'] ?? null) === 'locked';
                }),
            );

        // Must not throw.
        $this->observer->execute($this->buildEventObserver($order));
    }

    private function buildEventObserver(?Order $order): Observer
    {
        $event = new Event(['order' => $order]);
        $eventObserver = new Observer();
        $eventObserver->setEvent($event);
        return $eventObserver;
    }
}
