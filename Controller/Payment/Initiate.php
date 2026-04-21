<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Api\Data\SplitPaymentDataInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Exception\BogApiException;
use Shubo\BogPayment\Gateway\Http\Client\CreatePaymentClient;
use Shubo\BogPayment\Gateway\Http\Client\StatusClient;
use Shubo\BogPayment\Gateway\Http\TransferFactory;

/**
 * Initiates a BOG payment session from the active quote.
 *
 * No Magento order is created at this point. The quote's reserved order ID
 * is sent to BOG as the external_order_id. The actual Magento order is
 * created only after the customer returns from BOG with a successful payment.
 */
class Initiate implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly JsonFactory $jsonFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CreatePaymentClient $createPaymentClient,
        private readonly TransferFactory $transferFactory,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly ResolverInterface $localeResolver,
        private readonly LoggerInterface $logger,
        private readonly EventManager $eventManager,
        private readonly SplitPaymentDataInterface $splitPaymentData,
        private readonly StatusClient $statusClient,
        private readonly FormKeyValidator $formKeyValidator,
    ) {
    }

    /**
     * BUG-BOG-14: explicit CSRF guard. Reject POSTs without a valid
     * form_key instead of relying on the implicit default
     * FormKeyValidator plugin — makes the security posture first-class
     * and audit-visible.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        if ($this->formKeyValidator->validate($request)) {
            return null;
        }

        return new InvalidRequestException(
            $this->jsonFactory->create()->setData([
                'success' => false,
                'message' => (string) __('Invalid form key. Please refresh the page and try again.'),
            ]),
            [new Phrase('Invalid form key.')]
        );
    }

    /**
     * Return null to defer to Magento's default validation flow which
     * invokes createCsrfValidationException above. Returning true here
     * would bypass the form_key check entirely.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return null;
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote || !$quote->getId()) {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('No active cart found.'),
                ]);
            }

            if ($quote->getItemsCount() === 0) {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Your cart is empty.'),
                ]);
            }

            $grandTotal = (float) $quote->getGrandTotal();
            if ($grandTotal <= 0) {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Invalid cart total.'),
                ]);
            }

            // BUG-BOG-13b: probe an existing bog_order_id before a fresh
            // initiation. Three outcomes:
            //   terminal success  -> redirect to success page (don't
            //                        double-charge; watchdog/reconciler may
            //                        not have cleaned up yet)
            //   pending           -> surface "still processing" message
            //   terminal failure  -> clear stale id, proceed with fresh order
            //   status API error  -> proceed defensively; cron will reap any
            //                        orphan
            $probe = $this->probeExistingBogOrder($quote);
            if ($probe !== null) {
                return $result->setData($probe);
            }

            // Reserve an order ID on the quote if not already reserved
            if (!$quote->getReservedOrderId()) {
                $quote->reserveOrderId();
                $this->cartRepository->save($quote);
            }

            $reservedOrderId = (string) $quote->getReservedOrderId();

            // Set the payment method on the quote
            $quotePayment = $quote->getPayment();
            $quotePayment->setMethod(Config::METHOD_CODE);

            // Build the BOG API request payload
            $requestBody = $this->buildRequestPayload($quote, $reservedOrderId, $grandTotal);

            // BUG-BOG-1: merge quote-time commission split (dispatched via
            // shubo_bog_payment_split_before_quote) into the BOG create-order
            // payload. Failure is non-fatal — payment proceeds without split.
            $splitSection = $this->buildSplitSection($quote);
            if ($splitSection !== []) {
                $requestBody = array_replace_recursive($requestBody, $splitSection);
            }

            // Create the transfer and send to BOG
            $transfer = $this->transferFactory->create($requestBody);
            $response = $this->createPaymentClient->placeRequest($transfer);

            $httpStatus = (int) ($response['http_status'] ?? 0);
            if ($httpStatus < 200 || $httpStatus >= 300) {
                throw new LocalizedException(
                    __('BOG Payments API returned an error. HTTP status: %1', $httpStatus)
                );
            }

            $bogOrderId = (string) ($response['id'] ?? '');
            $redirectUrl = (string) ($response['_links']['redirect']['href'] ?? '');
            $detailsUrl = (string) ($response['_links']['details']['href'] ?? '');

            if ($bogOrderId === '' || $redirectUrl === '') {
                throw new LocalizedException(
                    __('Invalid response from BOG Payments API: missing order ID or redirect URL.')
                );
            }

            // Store BOG order data on the quote payment for later retrieval
            $quotePayment->setAdditionalInformation('bog_order_id', $bogOrderId);
            $quotePayment->setAdditionalInformation('bog_redirect_url', $redirectUrl);
            $quotePayment->setAdditionalInformation('bog_status', 'created');
            if ($detailsUrl !== '') {
                $quotePayment->setAdditionalInformation('bog_details_url', $detailsUrl);
            }
            $this->cartRepository->save($quote);

            $this->logger->info('BOG payment initiated', [
                'reserved_order_id' => $reservedOrderId,
                'bog_order_id' => $bogOrderId,
            ]);

            return $result->setData([
                'success' => true,
                'redirect_url' => $redirectUrl,
                'bog_order_id' => $bogOrderId,
            ]);
        } catch (LocalizedException $e) {
            $this->logger->error('BOG Initiate error', [
                'message' => $e->getMessage(),
            ]);
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->critical('BOG Initiate unexpected error', [
                'exception' => $e->getMessage(),
            ]);
            return $result->setData([
                'success' => false,
                'message' => (string) __('An error occurred while initiating payment. Please try again.'),
            ]);
        }
    }

    /**
     * Build the BOG Payments API create-order request payload from the quote.
     *
     * @return array<string, mixed>
     */
    private function buildRequestPayload(
        \Magento\Quote\Model\Quote $quote,
        string $reservedOrderId,
        float $grandTotal,
    ): array {
        $basket = [];
        /** @var \Magento\Quote\Model\Quote\Item $item */
        foreach ($quote->getAllVisibleItems() as $item) {
            $basket[] = [
                'product_id' => $item->getSku() ?? '',
                'description' => mb_substr($item->getName() ?? '', 0, 255),
                'quantity' => (int) $item->getQty(),
                'unit_price' => round((float) $item->getPrice(), 2),
            ];
        }

        $callbackUrl = $this->urlBuilder->getUrl(
            'shubo_bog/payment/callback',
            ['_secure' => true]
        );
        $returnUrl = $this->urlBuilder->getUrl(
            'shubo_bog/payment/return',
            ['_secure' => true]
        );
        $failUrl = $this->urlBuilder->getUrl(
            'checkout/onepage/failure',
            ['_secure' => true]
        );

        $currency = $quote->getQuoteCurrencyCode() ?: 'GEL';
        $locale = $this->resolveLocale();
        $captureMode = $this->config->getPaymentActionMode();
        $ttl = $this->config->getPaymentLifetime();
        $paymentMethods = $this->config->getAllowedPaymentMethods();

        return [
            'callback_url' => $callbackUrl,
            'external_order_id' => $reservedOrderId,
            'capture' => $captureMode,
            'ttl' => $ttl,
            'payment_method' => $paymentMethods,
            'redirect_urls' => [
                'success' => $returnUrl,
                'fail' => $failUrl,
            ],
            'purchase_units' => [
                'currency' => $currency,
                'total_amount' => round($grandTotal, 2),
                'basket' => $basket,
            ],
            '__locale' => $locale,
        ];
    }

    /**
     * BUG-BOG-1: dispatch the quote-time commission split event and, if any
     * observer populated SplitPaymentData, return the `config.split`
     * fragment in the exact shape expected by BOG's create-order API (same
     * as Gateway/Request/SplitDataBuilder).
     *
     * Fail-safe: any exception from the event dispatch is logged and
     * swallowed; the payment proceeds without split data.
     *
     * @return array<string, mixed> empty array when split is disabled, the
     *         observer produces no data, or dispatch throws
     */
    private function buildSplitSection(\Magento\Quote\Model\Quote $quote): array
    {
        if (!$this->config->isSplitEnabled()) {
            return [];
        }

        $this->splitPaymentData->reset();

        try {
            $this->eventManager->dispatch('shubo_bog_payment_split_before_quote', [
                'quote' => $quote,
                'payment' => $quote->getPayment(),
                'split_payment_data' => $this->splitPaymentData,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('BOG split dispatch failed — payment proceeds without split', [
                'quote_id' => $quote->getId(),
                'exception' => $e->getMessage(),
            ]);
            return [];
        }

        if (!$this->splitPaymentData->hasSplitPayments()) {
            return [];
        }

        $splitPayments = [];
        foreach ($this->splitPaymentData->getSplitPayments() as $split) {
            $splitPayments[] = [
                'iban' => $split['iban'],
                'percent' => $split['percent'],
                'description' => $split['description'],
            ];
        }

        return [
            'config' => [
                'split' => [
                    'split_payments' => $splitPayments,
                ],
            ],
        ];
    }

    private function resolveLocale(): string
    {
        $locale = $this->localeResolver->getLocale();
        $language = substr($locale, 0, 2);

        return match ($language) {
            'ka' => 'ka',
            default => 'en',
        };
    }

    /**
     * BUG-BOG-13b: if the quote carries an existing bog_order_id, probe
     * BOG for its status and return a ready-made JSON response payload
     * for the three non-proceed outcomes:
     *
     *   terminal success -> {success: true, redirect_url: onepage/success}
     *   pending          -> {success: false, message: still processing}
     *
     * Terminal failure (expired/rejected/declined/error) clears the stale
     * id from the quote and returns null so the caller proceeds with a
     * fresh create-order. Status API errors also return null — the
     * defensive choice is "treat existing id as gone" (cron will reap).
     *
     * @return array<string, mixed>|null null if caller should proceed
     */
    private function probeExistingBogOrder(\Magento\Quote\Model\Quote $quote): ?array
    {
        $quotePayment = $quote->getPayment();
        $existing = (string) ($quotePayment->getAdditionalInformation('bog_order_id') ?? '');
        if ($existing === '') {
            return null;
        }

        $storeId = (int) $quote->getStoreId();

        try {
            $response = $this->statusClient->checkStatus($existing, $storeId);
        } catch (BogApiException $e) {
            $this->logger->warning('BOG Initiate: status probe failed on existing bog_order_id', [
                'bog_order_id' => $existing,
                'exception' => $e->getMessage(),
            ]);
            $this->clearStaleBogData($quote);
            return null;
        }

        $status = strtolower(
            (string) ($response['order_status']['key'] ?? ($response['status'] ?? ''))
        );

        if (in_array($status, ['completed', 'captured'], true)) {
            $successUrl = $this->urlBuilder->getUrl(
                'checkout/onepage/success',
                ['_secure' => true]
            );
            $this->logger->info('BOG Initiate: existing order already paid, short-circuit', [
                'bog_order_id' => $existing,
            ]);
            return [
                'success' => true,
                'redirect_url' => $successUrl,
                'bog_order_id' => $existing,
                'already_paid' => true,
            ];
        }

        if (in_array($status, ['created', 'in_progress'], true)) {
            $this->logger->info('BOG Initiate: existing order still in progress, blocking fresh init', [
                'bog_order_id' => $existing,
                'status' => $status,
            ]);
            return [
                'success' => false,
                'bog_order_id' => $existing,
                'message' => (string) __(
                    'Your previous payment is still being processed by the bank. '
                    . 'Please wait a few minutes before retrying.'
                ),
            ];
        }

        // Terminal failure: expired / rejected / declined / error / unknown.
        $this->logger->info('BOG Initiate: existing bog_order_id is stale, clearing', [
            'bog_order_id' => $existing,
            'status' => $status,
        ]);
        $this->clearStaleBogData($quote);
        return null;
    }

    private function clearStaleBogData(\Magento\Quote\Model\Quote $quote): void
    {
        $payment = $quote->getPayment();
        foreach (['bog_order_id', 'bog_redirect_url', 'bog_details_url', 'bog_status'] as $key) {
            $payment->unsAdditionalInformation($key);
        }
        try {
            $this->cartRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->warning('BOG Initiate: failed to save quote after clearing stale bog data', [
                'quote_id' => $quote->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
