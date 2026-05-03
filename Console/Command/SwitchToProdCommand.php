<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Console\Command;

use Magento\Config\Model\ResourceModel\Config as ConfigResourceModel;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filesystem\Driver\File as FilesystemDriver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Atomic launch-day cutover from BOG sandbox credentials to production.
 *
 * Usage:
 *     bin/magento shubo:payment:switch-to-prod:bog \
 *         --client-id=<id> --secret=<secret> --rsa-key-path=<path> \
 *         [--dry-run] [--force]
 *
 * Additional responsibilities on top of the TBC analogue (see
 * Shubo\TbcPayment\Console\Command\SwitchToProdCommand for the shared
 * contract):
 *   1. Read the real RSA public key provided by BOG from --rsa-key-path;
 *      validate it parses with openssl_pkey_get_public() BEFORE writing
 *      anything. This is the launch-day half of BUG-BOG-3; the structural
 *      half (removing the hardcoded PEM const from CallbackValidator and
 *      having the validator read encrypted system config via
 *      Config::getRsaPublicKey()) shipped 2026-05-03.
 *   2. Encrypt + store the RSA key at payment/shubo_bog/rsa_public_key —
 *      already declared as backend_model=Encrypted in system.xml so the
 *      callback validator can read+decrypt it per request.
 *   3. Flip payment/shubo_bog/environment=production AND clear any explicit
 *      payment/shubo_bog/api_url override. ApiUrlResolver::resolve() then
 *      picks api.bog.ge/payments/v1 automatically (closes BUG-BOG-15 cutover
 *      trap — env=test while api_url pointed at prod).
 */
class SwitchToProdCommand extends Command
{
    public const NAME = 'shubo:payment:switch-to-prod:bog';

    public const OPT_CLIENT_ID = 'client-id';
    public const OPT_SECRET = 'secret';
    public const OPT_RSA_KEY_PATH = 'rsa-key-path';
    public const OPT_DRY_RUN = 'dry-run';
    public const OPT_FORCE = 'force';

    public const CONFIG_PATH_CLIENT_ID = 'payment/shubo_bog/client_id';
    public const CONFIG_PATH_CLIENT_SECRET = 'payment/shubo_bog/client_secret';
    public const CONFIG_PATH_RSA_PUBLIC_KEY = 'payment/shubo_bog/rsa_public_key';
    public const CONFIG_PATH_ENVIRONMENT = 'payment/shubo_bog/environment';
    public const CONFIG_PATH_API_URL = 'payment/shubo_bog/api_url';

    public const CACHE_TYPE_CONFIG = 'config';
    public const CACHE_TYPE_FULL_PAGE = 'full_page';

    public const ENV_PRODUCTION = 'production';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ConfigResourceModel $configResourceModel,
        private readonly EncryptorInterface $encryptor,
        private readonly TypeListInterface $cacheTypeList,
        private readonly FilesystemDriver $filesystemDriver,
        private readonly LoggerInterface $cutoverLogger,
        ?string $name = self::NAME,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription(
            (string) __(
                'Atomically switch BOG payment config from sandbox to production credentials + RSA key.'
            )
        );
        $this->addOption(
            self::OPT_CLIENT_ID,
            null,
            InputOption::VALUE_REQUIRED,
            (string) __('Production BOG client ID'),
        );
        $this->addOption(
            self::OPT_SECRET,
            null,
            InputOption::VALUE_REQUIRED,
            (string) __('Production BOG client secret'),
        );
        $this->addOption(
            self::OPT_RSA_KEY_PATH,
            null,
            InputOption::VALUE_REQUIRED,
            (string) __('Absolute path to a PEM file containing the BOG RSA public key'),
        );
        $this->addOption(
            self::OPT_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            (string) __('Print the planned diff and exit without writing'),
        );
        $this->addOption(
            self::OPT_FORCE,
            null,
            InputOption::VALUE_NONE,
            (string) __('Allow re-running when environment is already "production"'),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clientId = (string) ($input->getOption(self::OPT_CLIENT_ID) ?? '');
        $secret = (string) ($input->getOption(self::OPT_SECRET) ?? '');
        $rsaKeyPath = (string) ($input->getOption(self::OPT_RSA_KEY_PATH) ?? '');
        $dryRun = (bool) $input->getOption(self::OPT_DRY_RUN);
        $force = (bool) $input->getOption(self::OPT_FORCE);

        if (trim($clientId) === '') {
            $output->writeln('<error>' . (string) __('client-id is required and must not be empty.') . '</error>');
            return Command::FAILURE;
        }
        if (trim($secret) === '') {
            $output->writeln('<error>' . (string) __('secret is required and must not be empty.') . '</error>');
            return Command::FAILURE;
        }
        if (trim($rsaKeyPath) === '') {
            $output->writeln(
                '<error>' . (string) __('rsa-key-path is required and must not be empty.') . '</error>'
            );
            return Command::FAILURE;
        }

        $rsaKeyPem = $this->readRsaKey($rsaKeyPath, $output);
        if ($rsaKeyPem === null) {
            return Command::FAILURE;
        }

        $currentClientId = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_CLIENT_ID,
            ScopeConfig::SCOPE_TYPE_DEFAULT,
        );
        $currentSecretRaw = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_CLIENT_SECRET,
            ScopeConfig::SCOPE_TYPE_DEFAULT,
        );
        $currentEnvironment = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_ENVIRONMENT,
            ScopeConfig::SCOPE_TYPE_DEFAULT,
        );
        $currentApiUrl = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_API_URL,
            ScopeConfig::SCOPE_TYPE_DEFAULT,
        );

        $currentSecretDecrypted = $currentSecretRaw !== ''
            ? $this->encryptor->decrypt($currentSecretRaw)
            : '';

        $beforeLine = sprintf(
            'BOG BEFORE client_id=%s secret=%s environment=%s api_url_override=%s',
            self::maskTail($currentClientId),
            self::maskTail($currentSecretDecrypted),
            $currentEnvironment !== '' ? $currentEnvironment : '(empty)',
            $currentApiUrl !== '' ? $currentApiUrl : '(empty)',
        );
        $this->cutoverLogger->info($beforeLine);

        if ($currentEnvironment === self::ENV_PRODUCTION && !$force) {
            $output->writeln(
                '<error>'
                . (string) __(
                    'BOG environment is already "production". '
                    . 'Re-run with --force to overwrite the production credentials.'
                )
                . '</error>'
            );
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<info>' . (string) __('[dry-run] Planned BOG production cutover:') . '</info>');
            $output->writeln(sprintf(
                '  client_id:       %s -> %s',
                $currentClientId !== '' ? self::maskTail($currentClientId) : '(empty)',
                self::maskTail($clientId),
            ));
            $output->writeln(sprintf(
                '  client_secret:   %s -> %s',
                $currentSecretDecrypted !== '' ? self::maskTail($currentSecretDecrypted) : '(empty)',
                self::maskTail($secret),
            ));
            $output->writeln(sprintf(
                '  rsa_public_key:  <new key parsed OK, %d bytes>',
                strlen($rsaKeyPem),
            ));
            $output->writeln(sprintf(
                '  environment:     %s -> %s',
                $currentEnvironment !== '' ? $currentEnvironment : '(empty)',
                self::ENV_PRODUCTION,
            ));
            $output->writeln(sprintf(
                '  api_url (override): %s -> (cleared)',
                $currentApiUrl !== '' ? $currentApiUrl : '(empty)',
            ));
            $output->writeln('<comment>' . (string) __('No values written (dry-run).') . '</comment>');
            return Command::SUCCESS;
        }

        $encryptedSecret = $this->encryptor->encrypt($secret);
        $encryptedRsaKey = $this->encryptor->encrypt($rsaKeyPem);

        $this->configResourceModel->saveConfig(
            self::CONFIG_PATH_CLIENT_ID,
            $clientId,
            ScopeInterface::SCOPE_DEFAULT,
            0,
        );
        $this->configResourceModel->saveConfig(
            self::CONFIG_PATH_CLIENT_SECRET,
            $encryptedSecret,
            ScopeInterface::SCOPE_DEFAULT,
            0,
        );
        $this->configResourceModel->saveConfig(
            self::CONFIG_PATH_RSA_PUBLIC_KEY,
            $encryptedRsaKey,
            ScopeInterface::SCOPE_DEFAULT,
            0,
        );
        $this->configResourceModel->saveConfig(
            self::CONFIG_PATH_ENVIRONMENT,
            self::ENV_PRODUCTION,
            ScopeInterface::SCOPE_DEFAULT,
            0,
        );
        $this->configResourceModel->saveConfig(
            self::CONFIG_PATH_API_URL,
            '',
            ScopeInterface::SCOPE_DEFAULT,
            0,
        );

        $this->cacheTypeList->cleanType(self::CACHE_TYPE_CONFIG);
        $this->cacheTypeList->cleanType(self::CACHE_TYPE_FULL_PAGE);

        $afterLine = sprintf(
            'BOG AFTER client_id=%s secret=%s environment=%s api_url_override=(cleared)',
            self::maskTail($clientId),
            self::maskTail($secret),
            self::ENV_PRODUCTION,
        );
        $this->cutoverLogger->info($afterLine);

        $output->writeln(
            (string) __(
                'BOG switched to production. Test card in prod is a real card — '
                . 'run the Playwright cutover-smoke spec now.'
            )
        );

        return Command::SUCCESS;
    }

    /**
     * Read + validate the RSA public key file. Returns the PEM contents on
     * success or null when the file is missing / unreadable / not a valid key.
     */
    private function readRsaKey(string $path, OutputInterface $output): ?string
    {
        try {
            $isExisting = $this->filesystemDriver->isExists($path);
        } catch (\Throwable $e) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                (string) __('Unable to access RSA key path "%1": %2', $path, $e->getMessage()),
            ));
            return null;
        }
        if ($isExisting === false) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                (string) __('RSA key file does not exist at "%1".', $path),
            ));
            return null;
        }

        try {
            $pem = $this->filesystemDriver->fileGetContents($path);
        } catch (\Throwable $e) {
            $output->writeln(sprintf(
                '<error>%s</error>',
                (string) __('RSA key file at "%1" is not readable: %2', $path, $e->getMessage()),
            ));
            return null;
        }

        $pem = trim($pem);
        if ($pem === '') {
            $output->writeln('<error>' . (string) __('RSA key file is empty.') . '</error>');
            return null;
        }

        // openssl_pkey_get_public() raises a PHP WARNING on malformed PEM
        // instead of throwing. Silence via a scoped error handler rather
        // than the `@` operator (forbidden by Magento2.NoSilencedErrors).
        set_error_handler(static fn(): bool => true);
        try {
            $publicKey = openssl_pkey_get_public($pem);
        } finally {
            restore_error_handler();
        }
        // Drain the openssl error buffer so follow-up calls start clean.
        // We intentionally discard the messages — the caller gets a clean
        // "failed openssl_pkey_get_public() validation" error below.
        $drain = 0;
        while (openssl_error_string() !== false) {
            $drain++;
        }
        unset($drain);
        if ($publicKey === false) {
            $output->writeln(
                '<error>'
                . (string) __(
                    'RSA key at "%1" failed openssl_pkey_get_public() validation. '
                    . 'The file is not a valid PEM-encoded RSA public key.',
                    $path
                )
                . '</error>'
            );
            return null;
        }

        return $pem;
    }

    /**
     * Mask a secret to `****<last 4>`, or `****` when shorter than 4 chars.
     * Only used for log + stdout — never for storage.
     */
    public static function maskTail(string $value): string
    {
        if ($value === '') {
            return '(empty)';
        }
        if (strlen($value) <= 4) {
            return '****';
        }
        return '****' . substr($value, -4);
    }
}
