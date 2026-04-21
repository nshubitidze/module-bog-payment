<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Config;

/**
 * BUG-BOG-15: resolve the BOG Payments API base URL from the configured
 * `environment` key, with an explicit admin `api_url` override taking
 * precedence.
 *
 * Previously `etc/config.xml` shipped a single hard-coded default pointing
 * at production, so a fresh install with `environment=test` silently
 * routed test traffic at the live endpoint. This resolver makes the
 * environment-to-URL mapping explicit:
 *
 *   environment=production -> https://api.bog.ge/payments/v1
 *   environment=test       -> BOG's test/sandbox endpoint
 *   environment=<unknown>  -> test URL (fail-closed: better to 404 a
 *                             misconfigured test store than to charge a
 *                             real card against prod by accident)
 *
 * An explicitly configured api_url (admin override) always wins so custom
 * staging endpoints or a BOG host rotation can be handled without a code
 * change.
 */
class ApiUrlResolver
{
    public const URL_PRODUCTION = 'https://api.bog.ge/payments/v1';
    // BOG's sandbox host as of 2026-04. Kept side-by-side with URL_PRODUCTION
    // so code references are explicit rather than string-composed.
    public const URL_TEST = 'https://api.sandbox.bog.ge/payments/v1';

    private const ENV_PRODUCTION = 'production';
    private const ENV_TEST = 'test';

    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * Return the BOG Payments API base URL for the given store.
     *
     * Precedence:
     *   1. explicit `payment/shubo_bog/api_url` configured by admin
     *   2. environment-derived default (production/test)
     *   3. fail-closed default (test URL) for unknown environment values
     */
    public function resolve(?int $storeId = null): string
    {
        $explicit = $this->config->getApiUrl($storeId);
        if ($explicit !== '') {
            return $explicit;
        }

        return match ($this->config->getEnvironment($storeId)) {
            self::ENV_PRODUCTION => self::URL_PRODUCTION,
            self::ENV_TEST => self::URL_TEST,
            default => self::URL_TEST,
        };
    }
}
