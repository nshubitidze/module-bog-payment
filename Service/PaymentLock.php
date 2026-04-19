<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Service;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * BUG-BOG-6 — concurrency guard for BOG capture paths.
 *
 * Callback, ReturnAction::handleSuccess, Confirm and Cron/PendingOrderReconciler
 * can all race to call registerCaptureNotification() on the same order. Without
 * serialization this causes:
 *   - duplicate invoices
 *   - duplicate commission rows (Payout ledger double-credit)
 *   - duplicate settlement rows
 *
 * Design choice: **MySQL named advisory locks** (`GET_LOCK` / `RELEASE_LOCK`).
 *
 * Why not a dedicated lock table with INSERT IGNORE?
 *   - Zero schema change (no db_schema.xml migration + tests)
 *   - Auto-cleanup on connection drop (no stale rows to sweep)
 *   - Re-entrant per-connection (a single handler that happens to acquire
 *     twice within one request doesn't deadlock itself)
 *
 * Why not row-level FOR UPDATE on sales_order?
 *   - The quote-materialization path (BUG-BOG-11b) runs BEFORE any
 *     sales_order row exists; we'd have nothing to lock.
 *
 * MySQL caveat: the lock is scoped to a single SESSION (connection). Magento's
 * default resource uses a single connection per PHP request, so our three
 * handlers share the lock within a request but compete across requests —
 * which is exactly what we want.
 *
 * Timeout: 10 seconds. Registered capture + invoice creation typically runs in
 * 100–500 ms. A 10 s timeout gives the legitimate holder ample time while
 * preventing callback retries from piling up.
 */
class PaymentLock
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;
    public const NAME_PREFIX = 'bog_';

    /** @var list<string> Keys currently held by this instance, for cleanup. */
    private array $heldKeys = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * Attempt to acquire the advisory lock for $bogOrderId.
     *
     * Returns true if the lock was granted within $timeoutSeconds, false if
     * another session holds it or on error.
     */
    public function acquire(string $bogOrderId): bool
    {
        if ($bogOrderId === '') {
            throw new \InvalidArgumentException('bog_order_id must not be empty');
        }

        $name = $this->lockName($bogOrderId);
        $connection = $this->resourceConnection->getConnection();

        $result = $connection->fetchOne(
            'SELECT GET_LOCK(:name, :timeout)',
            ['name' => $name, 'timeout' => $this->timeoutSeconds]
        );

        if ($result === '1' || $result === 1) {
            $this->heldKeys[] = $bogOrderId;
            return true;
        }

        $this->logger->warning('BOG payment lock: acquire failed', [
            'name' => $name,
            'result' => $result,
        ]);

        return false;
    }

    /**
     * Release the most recently acquired lock (if any).
     *
     * If a specific $bogOrderId is supplied, release that one; otherwise
     * release the top of the held-keys stack. Safe to call on an empty stack.
     */
    public function release(?string $bogOrderId = null): void
    {
        if ($bogOrderId === null) {
            $bogOrderId = array_pop($this->heldKeys);
            if ($bogOrderId === null) {
                return;
            }
        } else {
            $index = array_search($bogOrderId, $this->heldKeys, true);
            if ($index !== false) {
                array_splice($this->heldKeys, $index, 1);
            }
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->query(
            'SELECT RELEASE_LOCK(:name)',
            ['name' => $this->lockName($bogOrderId)]
        );
    }

    /**
     * Run $callable while holding the lock. On contention, the callable is
     * NOT invoked and null is returned. Any exception thrown by $callable
     * propagates after the lock is released.
     *
     * @template T
     * @param callable():T $callable
     * @return T|null
     */
    public function withLock(string $bogOrderId, callable $callable): mixed
    {
        if ($bogOrderId === '') {
            throw new \InvalidArgumentException('bog_order_id must not be empty');
        }

        if (!$this->acquire($bogOrderId)) {
            return null;
        }

        try {
            return $callable();
        } finally {
            $this->release($bogOrderId);
        }
    }

    private function lockName(string $bogOrderId): string
    {
        return self::NAME_PREFIX . $bogOrderId;
    }
}
