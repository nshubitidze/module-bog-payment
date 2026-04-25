<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Model;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Model\OAuthTokenProvider;

/**
 * BUG-BOG-9: the OAuth2 token cache must be keyed per storeId and persisted in
 * Magento's cache.static pool so multi-storefront deployments (and in the
 * future multi-merchant BOG credentials) do not cross-contaminate tokens.
 *
 * Cache contract:
 *   - Key: `bog_oauth_token_{storeId}` (storeId 0 for default scope).
 *   - Payload: JSON `{ "access_token": "...", "expires_at": <unix epoch> }`.
 *   - TTL on save = `expires_in - TOKEN_TTL_BUFFER_SECONDS` (60 s).
 *   - Cache read failures are non-fatal — fall through to a fresh token fetch
 *     and log at WARN.
 *
 * Fetch contract (unchanged from pre-fix):
 *   - HTTP 200 + access_token → store + return.
 *   - Empty creds → LocalizedException.
 *   - Non-200 → LocalizedException.
 *   - Missing access_token → LocalizedException.
 */
class OAuthTokenProviderTest extends TestCase
{
    private Config&MockObject $config;
    private CurlFactory&MockObject $curlFactory;
    private Curl&MockObject $curl;
    private FrontendInterface&MockObject $cache;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curl = $this->createMock(Curl::class);
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->curlFactory->method('create')->willReturn($this->curl);
        $this->curl->method('setCredentials')->willReturn($this->curl);
        $this->curl->method('setOptions')->willReturn($this->curl);

        $this->config->method('getClientId')->willReturn('client123');
        $this->config->method('getClientSecret')->willReturn('secret456');
        $this->config->method('getOAuthTokenUrl')->willReturn('https://oauth2.bog.ge/token');
    }

    public function testCacheMissFetchesAndStores(): void
    {
        $this->cache->expects(self::once())
            ->method('load')
            ->with('bog_oauth_token_1')
            ->willReturn(false);

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            json_encode(['access_token' => 'tok_1', 'expires_in' => 3600], JSON_THROW_ON_ERROR)
        );

        $savedPayload = null;
        $savedTtl = null;
        $savedKey = null;
        $this->cache->expects(self::once())
            ->method('save')
            ->willReturnCallback(
                function ($payload, $key, $tags, $ttl) use (&$savedPayload, &$savedKey, &$savedTtl) {
                    $savedPayload = $payload;
                    $savedKey = $key;
                    $savedTtl = $ttl;
                    return true;
                }
            );

        $provider = $this->buildProvider();
        self::assertSame('tok_1', $provider->getAccessToken(1));

        self::assertSame('bog_oauth_token_1', $savedKey);
        self::assertIsString($savedPayload);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($savedPayload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tok_1', $decoded['access_token']);
        self::assertIsInt($decoded['expires_at']);
        // TTL = 3600 - 60 safety buffer
        self::assertSame(3540, $savedTtl);
    }

    public function testCacheHitReturnsWithoutHttpCall(): void
    {
        $cachedPayload = json_encode([
            'access_token' => 'cached_tok',
            'expires_at' => time() + 1800,
        ], JSON_THROW_ON_ERROR);

        $this->cache->expects(self::once())
            ->method('load')
            ->with('bog_oauth_token_7')
            ->willReturn($cachedPayload);

        // No HTTP call, no save back.
        $this->curl->expects(self::never())->method('post');
        $this->cache->expects(self::never())->method('save');

        $provider = $this->buildProvider();
        self::assertSame('cached_tok', $provider->getAccessToken(7));
    }

    public function testDifferentStoreIdsGetDifferentTokens(): void
    {
        $calls = [];
        $this->cache->method('load')->willReturnCallback(
            function (string $key) use (&$calls): bool {
                $calls[] = $key;
                return false; // always miss so we hit HTTP
            }
        );

        $bodies = [
            json_encode(['access_token' => 'tok_s1', 'expires_in' => 3600], JSON_THROW_ON_ERROR),
            json_encode(['access_token' => 'tok_s2', 'expires_in' => 3600], JSON_THROW_ON_ERROR),
        ];
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(...$bodies);

        $this->cache->method('save')->willReturn(true);

        $provider = $this->buildProvider();
        self::assertSame('tok_s1', $provider->getAccessToken(1));
        self::assertSame('tok_s2', $provider->getAccessToken(2));

        self::assertContains('bog_oauth_token_1', $calls);
        self::assertContains('bog_oauth_token_2', $calls);
    }

    public function testExpiredCachedTokenTriggersRefetch(): void
    {
        // Cached token is already past expires_at → ignore and refetch.
        $expired = json_encode([
            'access_token' => 'stale_tok',
            'expires_at' => time() - 10,
        ], JSON_THROW_ON_ERROR);

        $this->cache->method('load')->willReturn($expired);

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            json_encode(['access_token' => 'fresh_tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR)
        );

        $this->cache->expects(self::once())->method('save')->willReturn(true);

        $provider = $this->buildProvider();
        self::assertSame('fresh_tok', $provider->getAccessToken(1));
    }

    public function testCacheReadErrorFallsBackToFreshFetch(): void
    {
        // Malformed JSON in the cache = broken state. Do not explode;
        // log WARN and proceed with the HTTP path.
        $this->cache->method('load')->willReturn('not valid json {');

        $this->logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(self::stringContains('BOG OAuth cache read'));

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            json_encode(['access_token' => 'recover_tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR)
        );

        $this->cache->method('save')->willReturn(true);

        $provider = $this->buildProvider();
        self::assertSame('recover_tok', $provider->getAccessToken(1));
    }

    public function testNullStoreIdUsesZeroCacheKey(): void
    {
        // A cron that forgets storeId still has to land in a deterministic
        // cache slot; storeId 0 is Magento's default scope.
        $this->cache->expects(self::once())
            ->method('load')
            ->with('bog_oauth_token_0')
            ->willReturn(false);

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            json_encode(['access_token' => 'def_tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR)
        );
        $this->cache->method('save')->willReturn(true);

        $provider = $this->buildProvider();
        self::assertSame('def_tok', $provider->getAccessToken(null));
    }

    private function buildProvider(): OAuthTokenProvider
    {
        return new OAuthTokenProvider(
            config: $this->config,
            curlFactory: $this->curlFactory,
            logger: $this->logger,
            cache: $this->cache,
        );
    }
}
