<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Shubo\BogPayment\Gateway\Helper\SubjectReader;

class ResponseValidator extends AbstractValidator
{
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        private readonly SubjectReader $subjectReader,
    ) {
        parent::__construct($resultFactory);
    }

    /**
     * Validate BOG API response.
     *
     * @param array<string, mixed> $validationSubject
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $response = $this->subjectReader->readResponse($validationSubject);

        $httpStatus = (int) ($response['http_status'] ?? 0);
        $isValid = $httpStatus >= 200 && $httpStatus < 300;

        $errorMessages = [];
        $errorCodes = [];

        if (!$isValid) {
            $errorMessages[] = (string) __('BOG iPay API returned an error. HTTP status: %1', $httpStatus);
            $errorCodes[] = $httpStatus;

            if (isset($response['message'])) {
                $errorMessages[] = (string) $response['message'];
            }

            if (isset($response['error'])) {
                $errorMessages[] = (string) $response['error'];
            }
        }

        return $this->createResult($isValid, $errorMessages, $errorCodes);
    }
}
