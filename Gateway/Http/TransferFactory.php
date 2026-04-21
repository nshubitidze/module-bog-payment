<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Shubo\BogPayment\Gateway\Config\Config;

class TransferFactory implements TransferFactoryInterface
{
    public function __construct(
        private readonly TransferBuilder $transferBuilder,
        private readonly Config $config,
    ) {
    }

    /**
     * Build transfer object with request body.
     *
     * @param array<string, mixed> $request
     */
    public function create(array $request): TransferInterface
    {
        return $this->transferBuilder
            ->setBody($request)
            ->setMethod('POST')
            // BUG-BOG-15: environment-aware base URL.
            ->setUri($this->config->getEffectiveApiUrl())
            ->build();
    }
}
