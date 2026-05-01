<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Cron\Reconciler;

/**
 * Reconciler-side money helpers shared by RefundedHandler + ReversedHandler.
 *
 * Kept separate from {@see \Shubo\BogPayment\Service\MoneyCaster} (which lives
 * at the gateway boundary and converts minor → Magento float). This helper does
 * the inverse plus candidate-key fallback that's specific to the reconciler's
 * BOG-response shape.
 */
class MoneyHelpers
{
    /**
     * Extract a monetary amount in minor units from a BOG response.
     * Walks a list of candidate keys; falls back to $defaultMinor when none
     * are present or positive. String/float input is rounded to integer
     * tetri to defeat float `==` / `>=` precision bugs (CLAUDE.md #6).
     *
     * @param array<string, mixed> $response
     * @param list<string> $candidateKeys
     */
    public function extractMinorAmount(array $response, array $candidateKeys, int $defaultMinor): int
    {
        foreach ($candidateKeys as $key) {
            if (!isset($response[$key])) {
                continue;
            }
            $raw = $response[$key];
            if (!is_numeric($raw)) {
                continue;
            }
            $minor = (int) round(((float) $raw) * 100);
            if ($minor > 0) {
                return $minor;
            }
        }
        return $defaultMinor;
    }
}
