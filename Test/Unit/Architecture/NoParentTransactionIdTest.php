<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Session 8 — Priority 3.1 regression guard.
 *
 * BOG never creates a fake authorization parent for direct-sale captures.
 * The capture flow uses `registerCaptureNotification($amount)` directly,
 * which makes the capture transaction a root entry (clean transaction tree
 * in admin Sales > Order > Transactions).
 *
 * If anyone reintroduces `setParentTransactionId()` on a Payment object in
 * the production code, this test fails — and the orphan-auth-row bug class
 * that bit TBC pre-Session 3 will not regress here.
 *
 * Scoped to production code; tests under Test/Unit/ are intentionally
 * excluded so a regression-guard mock setter does not trip the assertion.
 */
class NoParentTransactionIdTest extends TestCase
{
    public function testProductionCodeNeverCallsSetParentTransactionId(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $hits = $this->grepProductionTree($moduleRoot, 'setParentTransactionId');

        self::assertSame(
            [],
            $hits,
            "BOG production code must never call setParentTransactionId(). "
            . "Direct-sale captures should call registerCaptureNotification() "
            . "with no parent so the capture row is a clean root in the admin "
            . "Sales > Order > Transactions tree. Hits:\n" . implode("\n", $hits)
        );
    }

    /**
     * Walk the module tree, return every absolute path:line where the needle
     * appears in a `.php` file outside Test/.
     *
     * @return list<string>
     */
    private function grepProductionTree(string $root, string $needle): array
    {
        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // Exclude test tree — regression guards may legitimately mock the setter.
            if (str_contains($path, '/Test/')) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (!str_contains($contents, $needle)) {
                continue;
            }
            foreach (explode("\n", $contents) as $lineNo => $line) {
                if (str_contains($line, $needle)) {
                    $results[] = sprintf('%s:%d', $path, $lineNo + 1);
                }
            }
        }

        return $results;
    }
}
