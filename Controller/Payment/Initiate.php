<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;
use Shubo\BogPayment\Gateway\Config\Config;
use Shubo\BogPayment\Gateway\Http\Client\CreatePaymentClient;
use Shubo\BogPayment\Gateway\Http\TransferFactory;

/**
 * Initiates a BOG payment session from the active quote.
 *
 * No Magento order is created at this point. The quote's reserved order ID
 * is sent to BOG as the external_order_id. The actual Magento order is
 * created only after the customer returns from BOG with a successful payment.
 */
class Initiate implements HttpPostActionInterface
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
    ) {
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

    private function resolveLocale(): string
    {
        $locale = $this->localeResolver->getLocale();
        $language = substr($locale, 0, 2);

        return match ($language) {
            'ka' => 'ka',
            default => 'en',
        };
    }
}
