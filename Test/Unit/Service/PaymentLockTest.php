<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Service\PaymentLock;

/**
 * Regression tests for BUG-BOG-6: named advisory-lock service used by the
 * Callback + ReturnAction + Cron paths to serialize concurrent capture
 * processing for the same bog_order_id.
 *
 * Implementation uses MySQL GET_LOCK(name, timeout) / RELEASE_LOCK(name).
 * - Happy path: GET_LOCK returns 1 → acquire() returns true.
 * - Double-acquire from an already-held key on the same connection still
 *   returns true (MySQL GET_LOCK is re-entrant per-connection) — but a
 *   second call from a DIFFERENT connection returns 0 → acquire() false.
 * - withLock always releases, even when the callable throws.
 * - A failed acquire returns false and never calls the wrapped callable.
 */
class PaymentLockTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $adapter;
    private LoggerInterface&MockObject $logger;
    private PaymentLock $lock;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->adapter);

        $this->lock = new PaymentLock(
            resourceConnection: $this->resourceConnection,
            logger: $this->logger,
        );
    }

    /**
     * Acquiring a free lock returns true. The SQL must invoke GET_LOCK with
     * the expected name format (`bog_<bogOrderId>`) and our configured
     * timeout (defaults to 10s).
     */
    public function testAcquireReturnsTrueWhenLockIsFree(): void
    {
        $this->adapter->expects(self::once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $binds) {
                self::assertStringContainsString('GET_LOCK', $sql);
                self::assertSame('bog_BOG-123', $binds['name'] ?? null);
                return '1';
            });

        self::assertTrue($this->lock->acquire('BOG-123'));
    }

    /**
     * When GET_LOCK returns 0 (another connection holds the lock), acquire
     * returns false.
     */
    public function testAcquireReturnsFalseWhenLockIsTaken(): void
    {
        $this->adapter->method('fetchOne')->willReturn('0');

        self::assertFalse($this->lock->acquire('BOG-OTHER'));
    }

    /**
     * A NULL return from GET_LOCK means an error occurred (timeout, killed
     * query). Treat as failure.
     */
    public function testAcquireReturnsFalseOnNullReturn(): void
    {
        $this->adapter->method('fetchOne')->willReturn(null);

        self::assertFalse($this->lock->acquire('BOG-ERR'));
    }

    /**
     * release() invokes RELEASE_LOCK with the same bog_ prefixed name.
     */
    public function testReleaseInvokesReleaseLock(): void
    {
        $this->adapter->method('fetchOne')->willReturn('1');
        $this->lock->acquire('BOG-REL');

        $this->adapter->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $binds) {
                self::assertStringContainsString('RELEASE_LOCK', $sql);
                self::assertSame('bog_BOG-REL', $binds['name'] ?? null);
                return $this->createMock(\Zend_Db_Statement_Interface::class);
            });

        $this->lock->release();
    }

    /**
     * withLock: happy path runs the callable once, returns its value, and
     * releases the lock.
     */
    public function testWithLockRunsCallableAndReleasesOnSuccess(): void
    {
        $this->adapter->method('fetchOne')->willReturn('1');
        // RELEASE_LOCK must fire.
        $this->adapter->expects(self::once())->method('query');

        $ran = false;
        $result = $this->lock->withLock('BOG-OK', function () use (&$ran) {
            $ran = true;
            return 'payload';
        });

        self::assertTrue($ran);
        self::assertSame('payload', $result);
    }

    /**
     * withLock: when the callable throws, the exception propagates but the
     * lock is ALWAYS released (try/finally).
     */
    public function testWithLockReleasesEvenWhenCallableThrows(): void
    {
        $this->adapter->method('fetchOne')->willReturn('1');
        $this->adapter->expects(self::once())->method('query');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $this->lock->withLock('BOG-THROW', function () {
                throw new \RuntimeException('boom');
            });
        } catch (\Throwable $e) {
            // Assert we still released before rethrowing.
            throw $e;
        }
    }

    /**
     * withLock: when acquire() fails, the callable is NOT invoked, and the
     * configured "on contention" return value (null) is returned.
     */
    public function testWithLockSkipsCallableWhenLockContended(): void
    {
        $this->adapter->method('fetchOne')->willReturn('0');
        // No RELEASE_LOCK because we never acquired.
        $this->adapter->expects(self::never())->method('query');

        $ran = false;
        $result = $this->lock->withLock('BOG-CONT', function () use (&$ran) {
            $ran = true;
            return 'should not run';
        });

        self::assertFalse($ran);
        self::assertNull($result);
    }

    /**
     * withLock: empty key throws — callers must supply a non-empty
     * bog_order_id. Silent-success on empty key would let concurrent captures
     * silently skip locking.
     */
    public function testWithLockRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->lock->withLock('', static fn() => 'never');
    }
}
