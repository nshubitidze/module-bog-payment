<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Console\Command;

use Magento\Config\Model\ResourceModel\Config as ConfigResourceModel;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filesystem\Driver\File as FilesystemDriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Console\Command\SwitchToProdCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Launch-day cutover CLI guardrails for BOG.
 *
 * Covers GO_LIVE_CHECKLIST.md §3.2 and the two known-issue closeouts it
 * depends on:
 *   - BUG-BOG-3: the real RSA public key replaces the malformed hardcoded
 *     const at callback-validation time.
 *   - BUG-BOG-15: any explicit api_url override is cleared so
 *     ApiUrlResolver picks api.bog.ge/payments/v1 from environment=production.
 */
class SwitchToProdCommandTest extends TestCase
{
    /**
     * Valid RSA-2048 public key PEM. Generated fresh for test fixtures —
     * not the real BOG key, which only lives in encrypted prod config.
     */
    private const VALID_PEM = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA4f5wg5l2hKsTeNem/V41
fGnJm6gOdrj8ym3rFkEU/wT8RDtnSgFEZOQpHEgQ7JL38xUfU0Y3g6aYw9QT0hJ7
mCpz9Er5qLaMXJwZxzHzAahlfA0icqabvJOMvQtzD6uQv6wPEyZtDTWiQi9AXwBp
HssPnpYGIn20ZZuNlX2BrClciHhCPUIIZOQn/MmqTD31jSyjoQoV7MhhMTATKJx2
XrHhR+1DcKJzQBSTAGnpYVaqpsARap+nwRipr3nUTuxyGohBTSmjJ2usSeQXHI3b
ODIRe1AuTyHceAbewn8b462yEWKARdpd9AjQW5SIVPfdsz5B6GlYQ5LdYKtznTuy
7wIDAQAB
-----END PUBLIC KEY-----
PEM;

    private ScopeConfigInterface&MockObject $scopeConfig;
    private ConfigResourceModel&MockObject $configResourceModel;
    private EncryptorInterface&MockObject $encryptor;
    private TypeListInterface&MockObject $cacheTypeList;
    private FilesystemDriver&MockObject $filesystemDriver;
    private LoggerInterface&MockObject $cutoverLogger;
    private SwitchToProdCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->configResourceModel = $this->createMock(ConfigResourceModel::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->filesystemDriver = $this->createMock(FilesystemDriver::class);
        $this->cutoverLogger = $this->createMock(LoggerInterface::class);

        $this->command = new SwitchToProdCommand(
            scopeConfig: $this->scopeConfig,
            configResourceModel: $this->configResourceModel,
            encryptor: $this->encryptor,
            cacheTypeList: $this->cacheTypeList,
            filesystemDriver: $this->filesystemDriver,
            cutoverLogger: $this->cutoverLogger,
        );
        $this->tester = new CommandTester($this->command);
    }

    private function primeCurrentConfig(
        string $clientId,
        string $encryptedSecret,
        string $environment,
        string $apiUrl,
    ): void {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use (
                $clientId,
                $encryptedSecret,
                $environment,
                $apiUrl,
            ): string {
                return match ($path) {
                    SwitchToProdCommand::CONFIG_PATH_CLIENT_ID => $clientId,
                    SwitchToProdCommand::CONFIG_PATH_CLIENT_SECRET => $encryptedSecret,
                    SwitchToProdCommand::CONFIG_PATH_ENVIRONMENT => $environment,
                    SwitchToProdCommand::CONFIG_PATH_API_URL => $apiUrl,
                    default => '',
                };
            }
        );
    }

    private function primeValidRsaKeyFile(string $path): void
    {
        $this->filesystemDriver->method('isExists')->with($path)->willReturn(true);
        $this->filesystemDriver->method('fileGetContents')->with($path)->willReturn(self::VALID_PEM);
    }

    public function testHappyPathWritesCredsRsaKeyFlipsEnvClearsOverrideAndCleanCaches(): void
    {
        $this->primeCurrentConfig('1006', 'enc_old', 'test', 'https://api.sandbox.bog.ge/payments/v1');
        $this->primeValidRsaKeyFile('/tmp/bog_pub.pem');

        $this->encryptor->expects($this->once())->method('decrypt')->with('enc_old')->willReturn('sandboxsecret');
        $this->encryptor->expects($this->exactly(2))
            ->method('encrypt')
            ->willReturnCallback(static fn(string $raw): string => 'ENC::' . $raw);

        $savedRows = [];
        $this->configResourceModel->expects($this->exactly(5))
            ->method('saveConfig')
            ->willReturnCallback(
                function (
                    string $path,
                    string $value,
                    string $scope,
                    int $scopeId
                ) use (&$savedRows): ConfigResourceModel {
                    $savedRows[$path] = ['value' => $value, 'scope' => $scope, 'scopeId' => $scopeId];
                    return $this->configResourceModel;
                }
            );

        $cleanedTypes = [];
        $this->cacheTypeList->expects($this->exactly(2))
            ->method('cleanType')
            ->willReturnCallback(static function (string $t) use (&$cleanedTypes): void {
                $cleanedTypes[] = $t;
            });

        $loggedLines = [];
        $this->cutoverLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $line) use (&$loggedLines): void {
                $loggedLines[] = $line;
            });

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-42',
            '--secret' => 'PRODBOGSECRET_ABCD1234',
            '--rsa-key-path' => '/tmp/bog_pub.pem',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertSame('PROD-BOG-42', $savedRows[SwitchToProdCommand::CONFIG_PATH_CLIENT_ID]['value']);
        self::assertSame(
            'ENC::PRODBOGSECRET_ABCD1234',
            $savedRows[SwitchToProdCommand::CONFIG_PATH_CLIENT_SECRET]['value']
        );
        self::assertStringStartsWith(
            'ENC::',
            $savedRows[SwitchToProdCommand::CONFIG_PATH_RSA_PUBLIC_KEY]['value']
        );
        self::assertSame(
            'production',
            $savedRows[SwitchToProdCommand::CONFIG_PATH_ENVIRONMENT]['value']
        );
        // api_url override explicitly cleared so ApiUrlResolver picks prod URL.
        self::assertSame('', $savedRows[SwitchToProdCommand::CONFIG_PATH_API_URL]['value']);
        self::assertSame(ScopeInterface::SCOPE_DEFAULT, $savedRows[SwitchToProdCommand::CONFIG_PATH_API_URL]['scope']);

        self::assertSame(
            [SwitchToProdCommand::CACHE_TYPE_CONFIG, SwitchToProdCommand::CACHE_TYPE_FULL_PAGE],
            $cleanedTypes,
        );

        self::assertStringContainsString('BOG BEFORE', $loggedLines[0]);
        self::assertStringContainsString('environment=test', $loggedLines[0]);
        self::assertStringContainsString('BOG AFTER', $loggedLines[1]);
        self::assertStringContainsString('environment=production', $loggedLines[1]);
        self::assertStringNotContainsString('PRODBOGSECRET_ABCD1234', $loggedLines[1]);
        self::assertStringContainsString('****1234', $loggedLines[1]);
        self::assertStringContainsString('Test card in prod is a real card', $this->tester->getDisplay());
    }

    public function testDryRunPrintsDiffAndExitsWithoutWriting(): void
    {
        $this->primeCurrentConfig('1006', 'enc_old', 'test', '');
        $this->primeValidRsaKeyFile('/tmp/bog_pub.pem');

        $this->encryptor->method('decrypt')->willReturn('sandboxsecret');
        $this->encryptor->expects($this->never())->method('encrypt');
        $this->configResourceModel->expects($this->never())->method('saveConfig');
        $this->cacheTypeList->expects($this->never())->method('cleanType');
        $this->cutoverLogger->expects($this->once())->method('info');

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-42',
            '--secret' => 'PRODBOGSECRET_ABCD1234',
            '--rsa-key-path' => '/tmp/bog_pub.pem',
            '--dry-run' => true,
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('[dry-run]', $display);
        self::assertStringContainsString('environment:     test -> production', $display);
        self::assertStringContainsString('api_url (override): (empty) -> (cleared)', $display);
        self::assertStringNotContainsString('PRODBOGSECRET_ABCD1234', $display);
    }

    public function testEmptyClientIdRejected(): void
    {
        $this->configResourceModel->expects($this->never())->method('saveConfig');

        $exitCode = $this->tester->execute([
            '--client-id' => '  ',
            '--secret' => 'nonempty',
            '--rsa-key-path' => '/tmp/bog_pub.pem',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('client-id is required', $this->tester->getDisplay());
    }

    public function testEmptySecretRejected(): void
    {
        $this->configResourceModel->expects($this->never())->method('saveConfig');

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-42',
            '--secret' => '',
            '--rsa-key-path' => '/tmp/bog_pub.pem',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('secret is required', $this->tester->getDisplay());
    }

    public function testEmptyRsaKeyPathRejected(): void
    {
        $this->configResourceModel->expects($this->never())->method('saveConfig');

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-42',
            '--secret' => 'PRODBOGSECRET_ABCD1234',
            '--rsa-key-path' => '',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('rsa-key-path is required', $this->tester->getDisplay());
    }

    /**
     * Explicit launch-blocker: a malformed PEM must fail before ANY
     * saveConfig call. Replacing a live RSA key with junk would break
     * every callback signature verification in production.
     */
    public function testRsaKeyParseFailureRejected(): void
    {
        $this->filesystemDriver->method('isExists')->willReturn(true);
        $this->filesystemDriver->method('fileGetContents')->willReturn('not-a-pem-file');

        $this->configResourceModel->expects($this->never())->method('saveConfig');
        $this->encryptor->expects($this->never())->method('encrypt');

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-42',
            '--secret' => 'PRODBOGSECRET_ABCD1234',
            '--rsa-key-path' => '/tmp/garbage.pem',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('openssl_pkey_get_public()', $this->tester->getDisplay());
    }

    public function testRsaKeyMissingFileRejected(): void
    {
        $this->filesystemDriver->method('isExists')->willReturn(false);
        $this->filesystemDriver->expects($this->never())->method('fileGetContents');
        $this->configResourceModel->expects($this->never())->method('saveConfig');

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-42',
            '--secret' => 'PRODBOGSECRET_ABCD1234',
            '--rsa-key-path' => '/tmp/not_there.pem',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('does not exist', $this->tester->getDisplay());
    }

    public function testReRunWithoutForceRejectedWhenAlreadyProduction(): void
    {
        $this->primeCurrentConfig('PROD-BOG-42', 'enc_prod', 'production', '');
        $this->primeValidRsaKeyFile('/tmp/bog_pub.pem');

        $this->encryptor->method('decrypt')->willReturn('old_prod_secret');
        $this->encryptor->expects($this->never())->method('encrypt');
        $this->configResourceModel->expects($this->never())->method('saveConfig');

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-42',
            '--secret' => 'NEWER_SECRET_xxxx',
            '--rsa-key-path' => '/tmp/bog_pub.pem',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('already "production"', $this->tester->getDisplay());
        self::assertStringContainsString('--force', $this->tester->getDisplay());
    }

    public function testReRunWithForceOverwritesProductionConfig(): void
    {
        $this->primeCurrentConfig('PROD-BOG-42', 'enc_prod', 'production', '');
        $this->primeValidRsaKeyFile('/tmp/bog_pub.pem');

        $this->encryptor->method('decrypt')->willReturn('old_prod_secret');
        $this->encryptor->expects($this->exactly(2))
            ->method('encrypt')
            ->willReturnCallback(static fn(string $raw): string => 'ENC::' . $raw);

        $this->configResourceModel->expects($this->exactly(5))->method('saveConfig');
        $this->cacheTypeList->expects($this->exactly(2))->method('cleanType');

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-43',
            '--secret' => 'ROTATED_bog_9999',
            '--rsa-key-path' => '/tmp/bog_pub.pem',
            '--force' => true,
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
    }

    /**
     * BUG-BOG-15 regression guard: on cutover, any explicit api_url override
     * MUST be cleared. Leaving a stale test/sandbox URL in place while
     * environment=production silently keeps production traffic on the
     * sandbox endpoint.
     */
    public function testApiUrlOverrideIsExplicitlyClearedOnCutover(): void
    {
        $this->primeCurrentConfig(
            '1006',
            'enc_old',
            'test',
            'https://api.sandbox.bog.ge/payments/v1',
        );
        $this->primeValidRsaKeyFile('/tmp/bog_pub.pem');

        $this->encryptor->method('decrypt')->willReturn('sandboxsecret');
        $this->encryptor->method('encrypt')
            ->willReturnCallback(static fn(string $raw): string => 'ENC::' . $raw);

        $apiUrlWritten = null;
        $this->configResourceModel->method('saveConfig')->willReturnCallback(
            function (string $path, string $value) use (&$apiUrlWritten): ConfigResourceModel {
                if ($path === SwitchToProdCommand::CONFIG_PATH_API_URL) {
                    $apiUrlWritten = $value;
                }
                return $this->configResourceModel;
            }
        );

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-42',
            '--secret' => 'PRODBOGSECRET_ABCD1234',
            '--rsa-key-path' => '/tmp/bog_pub.pem',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertSame('', $apiUrlWritten);
    }

    public function testSecretAndRsaKeyAreEncryptedBeforeSave(): void
    {
        $this->primeCurrentConfig('1006', '', 'test', '');
        $this->primeValidRsaKeyFile('/tmp/bog_pub.pem');
        $this->encryptor->method('decrypt')->willReturn('');

        $encryptCalls = [];
        $this->encryptor->method('encrypt')->willReturnCallback(
            static function (string $raw) use (&$encryptCalls): string {
                $encryptCalls[] = $raw;
                return 'ENC::' . $raw;
            }
        );

        $saved = [];
        $this->configResourceModel->method('saveConfig')->willReturnCallback(
            function (string $path, string $value) use (&$saved): ConfigResourceModel {
                $saved[$path] = $value;
                return $this->configResourceModel;
            }
        );

        $exitCode = $this->tester->execute([
            '--client-id' => 'PROD-BOG-42',
            '--secret' => 'rawBogSecret_abcd',
            '--rsa-key-path' => '/tmp/bog_pub.pem',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertContains('rawBogSecret_abcd', $encryptCalls);
        self::assertContains(self::VALID_PEM, $encryptCalls);
        self::assertSame('ENC::rawBogSecret_abcd', $saved[SwitchToProdCommand::CONFIG_PATH_CLIENT_SECRET]);
        self::assertSame(
            'ENC::' . self::VALID_PEM,
            $saved[SwitchToProdCommand::CONFIG_PATH_RSA_PUBLIC_KEY]
        );
    }

    public function testMaskTailHelper(): void
    {
        self::assertSame('(empty)', SwitchToProdCommand::maskTail(''));
        self::assertSame('****', SwitchToProdCommand::maskTail('abc'));
        self::assertSame('****', SwitchToProdCommand::maskTail('abcd'));
        self::assertSame('****1234', SwitchToProdCommand::maskTail('abcdef1234'));
    }
}
