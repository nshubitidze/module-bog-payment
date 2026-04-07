<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Exception thrown when BOG Payments API returns an error or is unreachable.
 */
class BogApiException extends LocalizedException
{
}
