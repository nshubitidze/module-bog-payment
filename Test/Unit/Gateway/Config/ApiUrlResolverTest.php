<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Gateway\Config;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\BogPayment\Gateway\Config\ApiUrlResolver;
use Shubo\BogPayment\Gateway\Config\Config;

/**
 * BUG-BOG-15: etc/config.xml shipped `api_url=https://api.bog.ge/payments/v1`
 * as the only default, independent of the `environment` value. Deploying to
 * a fresh install with `environment=test` silently pointed all traffic at
 * production — the admin had to know to override api_url for every store.
 *
 * ApiUrlResolver centralises the environment-to-URL mapping:
 *   environment=production -> production API URL
 *   environment=test       -> test/sandbox API URL
 *   environment=<unknown>  -> test URL (fail-closed — safer than charging a
 *                             live card on a misconfigured store)
 *
 * An explicit admin-configured api_url still wins (supports custom staging
 * URLs or BOG rotating their endpoints).
 */
class ApiUrlResolverTest extends TestCase
{
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
    }

    public function testEnvironmentTestResolvesToSandboxUrl(): void
    {
        $this->config->method('getEnvironment')->willReturn('test');
        $this->config->method('getApiUrl')->willReturn(''); // no explicit override

        $resolver = new ApiUrlResolver($this->config);

        self::assertSame(ApiUrlResolver::URL_TEST, $resolver->resolve(1));
    }

    public function testEnvironmentProductionResolvesToProdUrl(): void
    {
        $this->config->method('getEnvironment')->willReturn('production');
        $this->config->method('getApiUrl')->willReturn('');

        $resolver = new ApiUrlResolver($this->config);

        self::assertSame(ApiUrlResolver::URL_PRODUCTION, $resolver->resolve(1));
    }

    public function testExplicitAdminUrlOverridesEnvironmentDefault(): void
    {
        // An admin with a custom staging endpoint sets api_url directly —
        // resolver must honour the override even when the environment key
        // would have picked a different URL.
        $this->config->method('getEnvironment')->willReturn('production');
        $this->config->method('getApiUrl')->willReturn('https://custom-staging.bog.internal/payments/v1');

        $resolver = new ApiUrlResolver($this->config);

        self::assertSame(
            'https://custom-staging.bog.internal/payments/v1',
            $resolver->resolve(1)
        );
    }

    public function testUnknownEnvironmentDefaultsToTestUrlFailClosed(): void
    {
        // Misconfigured store (typo in environment key) must NOT route a
        // live card at production by accident. Fail closed == test URL.
        $this->config->method('getEnvironment')->willReturn('stagign'); // typo
        $this->config->method('getApiUrl')->willReturn('');

        $resolver = new ApiUrlResolver($this->config);

        self::assertSame(ApiUrlResolver::URL_TEST, $resolver->resolve(1));
    }

    public function testDifferentStoreIdsGetDistinctResolution(): void
    {
        // Resolver consults Config per storeId — each store can have its
        // own environment (e.g. store 1 prod, store 2 sandbox).
        $this->config->method('getEnvironment')->willReturnMap([
            [1, 'production'],
            [2, 'test'],
        ]);
        $this->config->method('getApiUrl')->willReturn('');

        $resolver = new ApiUrlResolver($this->config);

        self::assertSame(ApiUrlResolver::URL_PRODUCTION, $resolver->resolve(1));
        self::assertSame(ApiUrlResolver::URL_TEST, $resolver->resolve(2));
    }
}
