<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Helper;

use InvalidArgumentException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Helper\SubjectReader as MagentoSubjectReader;

class SubjectReader
{
    /**
     * Read payment data object from subject.
     *
     * @param array<string, mixed> $subject
     * @throws InvalidArgumentException
     */
    public function readPayment(array $subject): PaymentDataObjectInterface
    {
        return MagentoSubjectReader::readPayment($subject);
    }

    /**
     * Read amount from subject.
     *
     * @param array<string, mixed> $subject
     * @throws InvalidArgumentException
     */
    public function readAmount(array $subject): float
    {
        return (float) MagentoSubjectReader::readAmount($subject);
    }

    /**
     * Read response from subject.
     *
     * @param array<string, mixed> $subject
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public function readResponse(array $subject): array
    {
        if (!isset($subject['response']) || !is_array($subject['response'])) {
            throw new InvalidArgumentException('Response does not exist or is not an array.');
        }
        return $subject['response'];
    }
}
