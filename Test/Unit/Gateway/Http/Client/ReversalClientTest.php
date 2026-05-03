<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\ReversalClient;
use Shubo\BogPayment\Model\OAuthTokenProvider;

/**
 * BUG-BOG-5: tests for the ReversalClient that talks to BOG's
 * `/payment/authorization/cancel/{order_id}` endpoint.
 *
 * Coverage:
 *   - HTTP 200 success → parsed array w/ http_status + bog_order_id carried
 *   - Description forwarded into request body when non-empty
 *   - HTTP 4xx business reject → BogApiException with BOG message in text
 *   - HTTP 5xx server error → BogApiException
 *   - Empty bog_order_id input → BogApiException (programming-error guard)
 *   - Malformed JSON response on 2xx → BogApiException
 */
class ReversalClientTest extends TestCase
{
    private Config&MockObject $config;
    private OAuthTokenProvider&MockObject $tokenProvider;
    private CurlFactory&MockObject $curlFactory;
    private Curl&MockObject $curl;
    private Json&MockObject $json;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->tokenProvider = $this->createMock(OAuthTokenProvider::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curl = $this->createMock(Curl::class);
        $this->json = $this->createMock(Json::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->curlFactory->method('create')->willReturn($this->curl);
        $this->tokenProvider->method('getAccessToken')->willReturn('tok_123');
        $this->config->method('getCancelAuthorizationUrl')
            ->willReturnCallback(static fn (string $id, ?int $sid): string
                => "https://api.bog.ge/payments/v1/payment/authorization/cancel/{$id}");
        $this->config->method('isDebugEnabled')->willReturn(false);

        // Default JSON serialization echoes back so the HTTP body assertion
        // reflects the input array verbatim.
        $this->json->method('serialize')->willReturnCallback(
            static fn ($v): string => json_encode($v, JSON_THROW_ON_ERROR) ?: ''
        );
        $this->json->method('unserialize')->willReturnCallback(
            static fn (string $s): mixed => json_decode($s, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testSuccessfulReversalReturnsParsedResponseWithMetadata(): void
    {
        $capturedBody = null;
        $this->curl->expects(self::once())
            ->method('post')
            ->with(
                'https://api.bog.ge/payments/v1/payment/authorization/cancel/BOG-XYZ-123',
                self::callback(function (string $body) use (&$capturedBody): bool {
                    $capturedBody = $body;
                    return true;
                })
            );
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'key' => 'request_received',
            'message' => 'Pre-authorization payment cancellation request received',
            'action_id' => 'aa9478c7-f82f-45a9-8a30-e7b4275b1224',
        ], JSON_THROW_ON_ERROR));

        $result = $this->client()->reverse('BOG-XYZ-123', 1, 'Void by admin for order 000000042');

        self::assertSame('request_received', $result['key']);
        self::assertSame('aa9478c7-f82f-45a9-8a30-e7b4275b1224', $result['action_id']);
        self::assertSame(200, $result['http_status']);
        self::assertSame('BOG-XYZ-123', $result['bog_order_id']);

        // Description was forwarded into the request body.
        self::assertNotNull($capturedBody);
        self::assertStringContainsString('Void by admin', (string) $capturedBody);
    }

    public function testEmptyDescriptionSendsEmptyBody(): void
    {
        $capturedBody = null;
        $this->curl->expects(self::once())
            ->method('post')
            ->with(self::anything(), self::callback(function (string $body) use (&$capturedBody): bool {
                $capturedBody = $body;
                return true;
            }));
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'key' => 'request_received',
            'action_id' => 'uuid',
        ], JSON_THROW_ON_ERROR));

        $this->client()->reverse('BOG-1', 1, '');

        // Empty body when no description.
        self::assertSame('[]', (string) $capturedBody);
    }

    public function testEmptyBogOrderIdThrowsImmediately(): void
    {
        // No HTTP call should be issued.
        $this->curl->expects(self::never())->method('post');

        $this->expectException(BogApiException::class);
        $this->expectExceptionMessage('BOG reversal requires a non-empty bog_order_id.');

        $this->client()->reverse('', 1, '');
    }

    public function testBusinessRejectThrowsWithBogMessage(): void
    {
        // Already-cancelled / already-captured come back as 4xx with a BOG
        // `message` field. The exception text should carry that message so
        // the controller's UserFacingErrorMapper can keyword-route on it.
        $this->curl->expects(self::once())->method('post');
        $this->curl->method('getStatus')->willReturn(409);
        $this->curl->method('getBody')->willReturn(json_encode([
            'message' => 'Authorization already captured',
            'error' => 'invalid_state',
        ], JSON_THROW_ON_ERROR));

        try {
            $this->client()->reverse('BOG-2', 1, '');
            self::fail('Expected BogApiException');
        } catch (BogApiException $e) {
            self::assertStringContainsString('HTTP 409', $e->getMessage());
            self::assertStringContainsString('BOG-2', $e->getMessage());
            self::assertStringContainsString('Authorization already captured', $e->getMessage());
        }
    }

    public function testServerErrorThrowsBogApiException(): void
    {
        $this->curl->expects(self::once())->method('post');
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('');

        $this->expectException(BogApiException::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->client()->reverse('BOG-3', 1, '');
    }

    public function testTwoHundredWithMalformedJsonThrows(): void
    {
        $this->curl->expects(self::once())->method('post');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('<html>not json</html>');

        $this->expectException(BogApiException::class);
        $this->expectExceptionMessage('Invalid reversal response');

        $this->client()->reverse('BOG-4', 1, '');
    }

    private function client(): ReversalClient
    {
        return new ReversalClient(
            $this->config,
            $this->tokenProvider,
            $this->curlFactory,
            $this->json,
            $this->logger,
        );
    }
}
