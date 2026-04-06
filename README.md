# Shubo_BogPayment — Bank of Georgia (iPay) for Magento 2

[![Latest Stable Version](https://img.shields.io/packagist/v/shubo/module-bog-payment.svg)](https://packagist.org/packages/shubo/module-bog-payment)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-purple.svg)](https://www.php.net/)

Accept payments in your Magento 2 store via Bank of Georgia's iPay gateway. Supports OAuth2 authentication, full and partial refunds, IBAN-based split payments for multi-vendor orders, and country restrictions.

## Features

- **BOG iPay integration** — REST API-based payment processing via Bank of Georgia
- **OAuth2 authentication** — client credentials flow with automatic token caching and refresh
- **Full and partial refunds** — process refunds directly from the Magento admin
- **Split payments** — distribute funds by IBAN and percentage for marketplace orders
- **Environment toggle** — switch between Test and Live environments
- **Country restrictions** — limit payments to specific countries
- **Multi-locale** — auto-detects Georgian (ka) or English (en) from the store locale
- **Debug logging** — optional PSR-3 structured logging for all API interactions
- **Payment info display** — stores and shows BOG order ID, payment ID, and status

## Requirements

| Dependency | Version |
|---|---|
| PHP | >= 8.4 |
| Magento Framework | * |
| Magento_Payment | * |
| Magento_Sales | * |
| Magento_Checkout | * |
| Magento_Store | * |
| Magento_Backend | * |

Compatible with Magento 2.4.x (Open Source and Commerce).

## Installation

```bash
composer require shubo/module-bog-payment
bin/magento module:enable Shubo_BogPayment
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

Navigate to **Stores > Configuration > Sales > Payment Methods > BOG iPay (Bank of Georgia)**.

| Field | Description | Scope |
|---|---|---|
| **Enabled** | Activate/deactivate the payment method | Website |
| **Title** | Payment method name shown at checkout (default: "BOG iPay") | Store View |
| **Environment** | Test or Live | Website |
| **Client ID** | OAuth2 client ID from BOG | Website |
| **Client Secret** | OAuth2 client secret (stored encrypted) | Website |
| **API URL** | BOG iPay API base URL (default: `https://ipay.ge/opay/api/v1`) | Website |
| **Enable Split Payments** | Send IBAN-based split data for multi-vendor orders | Website |
| **Debug** | Enable detailed logging to `var/log/` | Website |
| **New Order Status** | Order status after successful payment (default: "Pending Payment") | Website |
| **Payment from Applicable Countries** | All countries or specific countries only | Website |
| **Payment from Specific Countries** | Restrict to selected countries | Website |
| **Sort Order** | Display order among payment methods | Website |

## Payment Flow

1. Customer selects "BOG iPay" at checkout
2. Frontend JS calls `shubo_bog/payment/createOrder` to initiate a payment
3. `OAuthTokenProvider` obtains an access token via OAuth2 client credentials grant
4. `CreatePaymentClient` sends the order to BOG iPay REST API (`/checkout/orders`)
5. Customer is presented with the BOG iPay payment interface
6. After payment, BOG sends a callback to `shubo_bog/payment/callback`
7. `CallbackValidator` verifies the response
8. Order is updated and payment information (BOG order ID, payment ID, status) is stored

### OAuth2 Authentication

The module authenticates with BOG using the **client credentials** grant type. Tokens are cached in memory with a 60-second safety buffer before expiry, so multiple API calls within a request reuse the same token.

**API Endpoints used:**

| Endpoint | Purpose |
|---|---|
| `POST /oauth2/token` | Obtain access token |
| `POST /checkout/orders` | Create a payment order |
| `GET /checkout/orders/{id}` | Check order status |
| `POST /checkout/refund` | Process a refund |

## Split Payments

When **Enable Split Payments** is turned on, the module dispatches the event `shubo_bog_payment_split_before` before sending the payment request. External modules can observe this event to provide IBAN-based split instructions.

BOG split payments use **IBAN + percentage** (not fixed amounts like TBC/Flitt).

### Implementing a Split Payment Observer

```xml
<!-- In your module's etc/events.xml -->
<event name="shubo_bog_payment_split_before">
    <observer name="my_module_bog_split" instance="Vendor\Module\Observer\BogSplitObserver"/>
</event>
```

```php
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Shubo\BogPayment\Api\Data\SplitPaymentDataInterface;

class BogSplitObserver implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        /** @var SplitPaymentDataInterface $splitData */
        $splitData = $observer->getData('split_payment_data');

        $splitData->addSplitPayment(
            iban: 'GE00TB0000000000000001',
            percent: 70.0,
            description: 'Vendor payout'
        );
        $splitData->addSplitPayment(
            iban: 'GE00TB0000000000000002',
            percent: 30.0,
            description: 'Platform commission'
        );
    }
}
```

Each entry requires: `iban` (Georgian IBAN), `percent` (float, 0-100, all entries must sum to 100), and `description`.

The resulting API payload is structured as:

```json
{
  "config": {
    "split": {
      "split_payments": [
        { "iban": "GE00TB...", "percent": 70.0, "description": "Vendor payout" },
        { "iban": "GE00TB...", "percent": 30.0, "description": "Platform commission" }
      ]
    }
  }
}
```

## Module Structure

```
Shubo/BogPayment/
  Api/Data/SplitPaymentDataInterface.php
  Block/Payment/Info.php
  Controller/Payment/Callback.php
  Controller/Payment/CreateOrder.php
  Gateway/
    Config/Config.php
    Helper/SubjectReader.php
    Http/Client/CreatePaymentClient.php
    Http/Client/RefundClient.php
    Http/TransferFactory.php
    Request/InitializeRequestBuilder.php
    Request/RefundRequestBuilder.php
    Request/SplitDataBuilder.php
    Response/InitializeHandler.php
    Response/RefundHandler.php
    Validator/CallbackValidator.php
    Validator/ResponseValidator.php
  Model/
    OAuthTokenProvider.php
    Source/Environment.php
    SplitPaymentData.php
    Ui/ConfigProvider.php
  view/frontend/
    layout/checkout_index_index.xml
    requirejs-config.js
    web/js/view/payment/method-renderer.js
    web/js/view/payment/shubo-bog.js
```

## Testing

```bash
# Coding standards
vendor/bin/phpcs --standard=Magento2 app/code/Shubo/BogPayment/

# Static analysis
vendor/bin/phpstan analyse -l 8 app/code/Shubo/BogPayment/

# Unit tests
vendor/bin/phpunit -c phpunit.xml --filter Shubo_BogPayment
```

## License

Proprietary. All rights reserved.
