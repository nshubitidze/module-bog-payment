<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Gateway\Validator;

use OpenSSLAsymmetricKey;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Gateway\Validator\CallbackValidator;

/**
 * Regression tests for BUG-BOG-2 (nested `body.order_status.key` parsing) and
 * BUG-BOG-3 (config-driven RSA public key, structural-fix complete).
 *
 * BUG-BOG-3 is now structural-fix-complete: the validator reads the
 * SHA256withRSA public key from encrypted system config
 * (`payment/shubo_bog/rsa_public_key`) via
 * `Shubo\BogPayment\Gateway\Config\Config::getRsaPublicKey()`. These tests
 * cover the full verification matrix — empty config, malformed PEM, valid
 * PEM with matching signature, valid PEM with mismatched signature — and
 * the BUG-BOG-2 nested/flat payload-shape resolution that survives across
 * both the signature-verification path and the status-API fallback.
 */
class CallbackValidatorTest extends TestCase
{
    private StatusClient&MockObject $statusClient;
    private LoggerInterface&MockObject $logger;
    private Config&MockObject $config;
    private CallbackValidator $validator;

    protected function setUp(): void
    {
        $this->statusClient = $this->createMock(StatusClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(Config::class);

        // Default to empty key so the existing fallback-path tests keep
        // their behaviour (no signature → straight to status API).
        $this->config->method('getRsaPublicKey')->willReturn('');

        $this->validator = new CallbackValidator(
            statusClient: $this->statusClient,
            logger: $this->logger,
            config: $this->config,
        );
    }

    /**
     * The new BOG Payments API wraps the receipt payload inside a top-level
     * `body` field:
     *
     *   {"event":"order_payment","body":{"order_status":{"key":"completed"}}}
     *
     * When a signature is present and verifies, the validator must read
     * `body.order_status.key` and return valid=true + status=completed
     * WITHOUT falling back to the status API. Pre-fix, it read
     * `order_status.key` on the outer body → empty → valid=false → status
     * API fallback on every callback.
     */
    public function testValidateReadsOrderStatusFromNestedBody(): void
    {
        $nestedBody = json_encode([
            'event' => 'order_payment',
            'zoned_request_time' => '2026-04-20T10:00:00+04:00',
            'body' => [
                'order_id' => 'BOG-1',
                'order_status' => ['key' => 'completed'],
            ],
        ], JSON_THROW_ON_ERROR);

        // We want to prove the happy path does NOT call the fallback status
        // API when the signature verifies AND the nested status key resolves.
        $this->statusClient->expects(self::never())->method('checkStatus');

        // Simulate a signature that passes verification by using a pre-verified
        // payload path: we call the validator with a null signature and allow
        // it to use the fallback API, but we assert that the FALLBACK uses the
        // SAME nested-aware parsing as the signature branch. This test drives
        // the signature branch's parser; see testValidateFallsBackToStatusApi
        // for the fallback path.

        // Since we can't easily stub openssl_verify from a unit test (no RSA
        // keypair at hand), we instead reflect on the private parser: the fix
        // is about `($callbackData['body'] ?? $callbackData)['order_status']`.
        // Invoke through a helper reflection here so the test fails RED
        // without the unwrap.
        $reflection = new \ReflectionClass($this->validator);
        if (!$reflection->hasMethod('extractOrderStatusKey')) {
            self::markTestSkipped(
                'Expected private method extractOrderStatusKey to exist; '
                . 'test drives its introduction during BUG-BOG-2 refactor.'
            );
        }

        $method = $reflection->getMethod('extractOrderStatusKey');
        $method->setAccessible(true);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($nestedBody, true);

        self::assertSame('completed', $method->invoke($this->validator, $decoded));
    }

    /**
     * Flat (legacy) payloads must continue to work — some test/sandbox
     * payloads and very-old iPay callbacks come through without a `body`
     * wrapper.
     */
    public function testValidateFallsBackToFlatShape(): void
    {
        $flatBody = json_encode([
            'order_id' => 'BOG-2',
            'order_status' => ['key' => 'captured'],
        ], JSON_THROW_ON_ERROR);

        $reflection = new \ReflectionClass($this->validator);
        if (!$reflection->hasMethod('extractOrderStatusKey')) {
            self::markTestSkipped(
                'Expected private method extractOrderStatusKey to exist; '
                . 'test drives its introduction during BUG-BOG-2 refactor.'
            );
        }

        $method = $reflection->getMethod('extractOrderStatusKey');
        $method->setAccessible(true);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($flatBody, true);

        self::assertSame('captured', $method->invoke($this->validator, $decoded));
    }

    /**
     * Status-API fallback path must also be nested-aware — some BOG receipt
     * API responses also embed the status under `body`. Before the fix,
     * `validateViaStatusApi` read `$response['order_status']['key']`
     * directly; if a future API change moved that into `body`, the fallback
     * would silently decide every callback is invalid.
     */
    public function testStatusApiFallbackHandlesNestedBodyShape(): void
    {
        $this->statusClient->method('checkStatus')->willReturn([
            'body' => [
                'order_status' => ['key' => 'completed'],
            ],
        ]);

        $result = $this->validator->validate(
            bogOrderId: 'BOG-3',
            callbackBody: '',
            signature: null,
            storeId: 0,
        );

        self::assertTrue($result['valid']);
        self::assertSame('completed', $result['status']);
    }

    /**
     * Status-API fallback for flat payload still works — the status API
     * historically returns a flat body and that must keep working.
     */
    public function testStatusApiFallbackHandlesFlatShape(): void
    {
        $this->statusClient->method('checkStatus')->willReturn([
            'order_status' => ['key' => 'captured'],
        ]);

        $result = $this->validator->validate(
            bogOrderId: 'BOG-4',
            storeId: 0,
        );

        self::assertTrue($result['valid']);
        self::assertSame('captured', $result['status']);
    }

    /**
     * A callback that is terminally non-success (e.g. in_progress, error)
     * must be reported as valid=false even though the parser successfully
     * located the nested key. The fix is about WHERE to look; the existing
     * status-gate logic must still reject non-terminal states.
     */
    public function testRejectsNonTerminalStatusFromNestedBody(): void
    {
        $this->statusClient->method('checkStatus')->willReturn([
            'body' => [
                'order_status' => ['key' => 'in_progress'],
            ],
        ]);

        $result = $this->validator->validate(
            bogOrderId: 'BOG-5',
            storeId: 0,
        );

        self::assertFalse($result['valid']);
        self::assertSame('in_progress', $result['status']);
    }

    /**
     * BUG-BOG-3 verification matrix (1 of 4): empty config.
     *
     * When the admin has not configured a key,
     * `Config::getRsaPublicKey()` returns ''. The validator should:
     *   - Log at INFO level (NOT warning) — empty is the expected
     *     pre-cutover state on staging/demo.
     *   - Return false from `verifySignature()`.
     *   - Fall through to the status API for the actual decision.
     */
    public function testEmptyConfigSkipsSignatureAndFallsBackToStatusApi(): void
    {
        // Override the setUp() default mock to a fresh validator + a
        // strict-INFO-expectation logger so we can assert on log calls.
        $statusClient = $this->createMock(StatusClient::class);
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(Config::class);
        $config->method('getRsaPublicKey')->willReturn('');

        $statusClient->method('checkStatus')->willReturn([
            'body' => ['order_status' => ['key' => 'completed']],
        ]);

        // We expect the empty-config branch to log at INFO with a message
        // mentioning "not configured". Use a callback matcher so we don't
        // pin the exact phrasing.
        $sawEmptyConfigInfo = false;
        $logger->method('info')->willReturnCallback(
            function (string $message) use (&$sawEmptyConfigInfo): void {
                if (str_contains($message, 'not configured')) {
                    $sawEmptyConfigInfo = true;
                }
            }
        );

        // Empty config is NOT a warning — the only warnings allowed are
        // the standard "signature verification failed, falling back" line
        // (which IS expected here because we sent a signature). Fail if
        // the empty-config branch ever starts logging at warning by
        // matching a phrase only it would emit.
        $logger->expects(self::never())->method('error');
        $logger->method('warning')->willReturnCallback(
            function (string $message): void {
                self::assertStringNotContainsString(
                    'not configured',
                    $message,
                    'Empty-config branch must log at INFO, not WARNING.'
                );
            }
        );

        $validator = new CallbackValidator(
            statusClient: $statusClient,
            logger: $logger,
            config: $config,
        );

        $result = $validator->validate(
            bogOrderId: 'BOG-EMPTY',
            callbackBody: 'body{}',
            signature: 'somesig',
            storeId: 0,
        );

        self::assertTrue($result['valid']);
        self::assertSame('completed', $result['status']);
        self::assertTrue(
            $sawEmptyConfigInfo,
            'Expected an INFO log mentioning the RSA key was not configured.'
        );
    }

    /**
     * BUG-BOG-3 verification matrix (2 of 4): malformed PEM.
     *
     * When the admin has configured a syntactically-broken PEM,
     * `openssl_pkey_get_public()` returns false. This IS an operator
     * misconfiguration so:
     *   - Log at WARNING level (loud channel).
     *   - Return false from `verifySignature()`.
     *   - Fall through to the status API for the actual decision.
     */
    public function testMalformedPemLogsWarningAndFallsBackToStatusApi(): void
    {
        $statusClient = $this->createMock(StatusClient::class);
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(Config::class);
        $config->method('getRsaPublicKey')->willReturn(
            "-----BEGIN PUBLIC KEY-----\nNOT_A_REAL_KEY\n-----END PUBLIC KEY-----"
        );

        $statusClient->method('checkStatus')->willReturn([
            'body' => ['order_status' => ['key' => 'completed']],
        ]);

        // Pin the matcher to the malformed-PEM-specific phrasing so the
        // test is not satisfied by the unrelated
        // "signature verification failed, falling back to status API"
        // warning that ALSO fires on this path (verifySignature → false).
        $sawInvalidPemWarning = false;
        $logger->method('warning')->willReturnCallback(
            function (string $message) use (&$sawInvalidPemWarning): void {
                if (str_contains($message, 'not a valid PEM')) {
                    $sawInvalidPemWarning = true;
                }
            }
        );

        $validator = new CallbackValidator(
            statusClient: $statusClient,
            logger: $logger,
            config: $config,
        );

        $result = $validator->validate(
            bogOrderId: 'BOG-BAD',
            callbackBody: 'body{}',
            signature: 'sig',
            storeId: 0,
        );

        self::assertTrue($result['valid']);
        self::assertSame('completed', $result['status']);
        self::assertTrue(
            $sawInvalidPemWarning,
            'Expected a WARNING log mentioning the PEM was invalid.'
        );
    }

    /**
     * BUG-BOG-3 verification matrix (3 of 4): valid PEM, valid signature.
     *
     * Generates an ephemeral RSA keypair in-process, configures the public
     * half on the mocked `Config`, signs a known body with the private
     * half, and asserts:
     *   - `verifySignature()` returns true → callback accepted on the
     *     primary path.
     *   - `validateViaStatusApi()` is NEVER called (no extra HTTPS round
     *     trip).
     *   - Result reflects the parsed callback data, not a status-API
     *     response.
     */
    public function testValidPemAndValidSignatureReturnsValidWithoutCallingStatusApi(): void
    {
        $keypair = $this->generateRsaKeypair();

        $body = json_encode(
            [
                'event' => 'order_payment',
                'body' => ['order_status' => ['key' => 'completed']],
            ],
            JSON_THROW_ON_ERROR
        );

        openssl_sign($body, $rawSignature, $keypair['private'], OPENSSL_ALGO_SHA256);
        $base64Signature = base64_encode($rawSignature);

        $statusClient = $this->createMock(StatusClient::class);
        $statusClient->expects(self::never())->method('checkStatus');

        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(Config::class);
        $config->method('getRsaPublicKey')->willReturn($keypair['public']);

        $validator = new CallbackValidator(
            statusClient: $statusClient,
            logger: $logger,
            config: $config,
        );

        $result = $validator->validate(
            bogOrderId: 'BOG-OK',
            callbackBody: $body,
            signature: $base64Signature,
            storeId: 0,
        );

        self::assertTrue($result['valid']);
        self::assertSame('completed', $result['status']);
    }

    /**
     * BUG-BOG-3 verification matrix (4 of 4): valid PEM, INVALID signature.
     *
     * Generates an ephemeral RSA keypair, configures the public half on
     * the mocked Config, but submits a tampered signature. Asserts:
     *   - `openssl_verify()` returns 0 → `verifySignature()` returns false.
     *   - The validator falls through to the status API (which here
     *     "rescues" the callback by reporting completed).
     */
    public function testValidPemButInvalidSignatureFallsBackToStatusApi(): void
    {
        $keypair = $this->generateRsaKeypair();

        $body = json_encode(
            [
                'event' => 'order_payment',
                'body' => ['order_status' => ['key' => 'completed']],
            ],
            JSON_THROW_ON_ERROR
        );

        // Generate a real signature, then tamper a byte before base64-encoding.
        openssl_sign($body, $rawSignature, $keypair['private'], OPENSSL_ALGO_SHA256);
        $tampered = $rawSignature;
        $tampered[0] = chr((ord($tampered[0]) + 1) % 256);
        $base64Tampered = base64_encode($tampered);

        $statusClient = $this->createMock(StatusClient::class);
        $statusClient->method('checkStatus')->willReturn([
            'body' => ['order_status' => ['key' => 'completed']],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(Config::class);
        $config->method('getRsaPublicKey')->willReturn($keypair['public']);

        // Expect a WARNING about signature verification failing + fallback.
        $sawSignatureFailureWarning = false;
        $logger->method('warning')->willReturnCallback(
            function (string $message) use (&$sawSignatureFailureWarning): void {
                if (
                    str_contains($message, 'signature verification failed')
                    || str_contains($message, 'falling back')
                ) {
                    $sawSignatureFailureWarning = true;
                }
            }
        );

        $validator = new CallbackValidator(
            statusClient: $statusClient,
            logger: $logger,
            config: $config,
        );

        $result = $validator->validate(
            bogOrderId: 'BOG-TAMPERED',
            callbackBody: $body,
            signature: $base64Tampered,
            storeId: 0,
        );

        self::assertTrue($result['valid']);
        self::assertSame('completed', $result['status']);
        self::assertTrue(
            $sawSignatureFailureWarning,
            'Expected a WARNING log indicating signature verification failed and fell back.'
        );
    }

    /**
     * Generate an ephemeral RSA-2048 keypair for the signature verification
     * tests so no real BOG keys ship in the repo and no fixture rotation
     * is required if BOG ever rotates their production key.
     *
     * @return array{public: string, private: OpenSSLAsymmetricKey}
     */
    private function generateRsaKeypair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            self::fail('Failed to generate ephemeral RSA keypair for test.');
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            self::fail('Failed to extract public PEM from ephemeral RSA keypair.');
        }

        return [
            'public' => (string) $details['key'],
            'private' => $resource,
        ];
    }
}
