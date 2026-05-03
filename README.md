# Shubo_BogPayment -- Bank of Georgia (iPay) Payment Module for Magento 2

[![Packagist](https://img.shields.io/badge/packagist-shubo%2Fmodule--bog--payment-orange.svg)](https://packagist.org/packages/shubo/module-bog-payment)
[![License: Apache 2.0](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](./LICENSE)
[![Magento](https://img.shields.io/badge/Magento-2.4.x-8a2be2.svg)](https://magento.com)

Bank of Georgia payment integration for Magento 2 using the BOG Payments REST API. Customers are redirected to the BOG-hosted payment page to complete their payment and returned to your store afterward.

> **IMPORTANT DISCLAIMER**: This module has NOT been tested in production with real transactions. It has been developed and tested against sandbox/test environments only. Thorough testing with real payment credentials and real cards is required before going live.

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Supported Features](#supported-features)
- [Unsupported Features](#unsupported-features)
- [Payment Flow](#payment-flow)
- [Order Status Flow](#order-status-flow)
- [Split Payments (Marketplace)](#split-payments-marketplace)
- [Admin Actions](#admin-actions)
- [Technical Architecture](#technical-architecture)
- [API Endpoints](#api-endpoints)
- [Logging](#logging)
- [Cron Jobs](#cron-jobs)
- [Internationalization](#internationalization)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Overview

Shubo_BogPayment integrates Bank of Georgia payments into Magento 2 via the BOG Payments API (also known as iPay). The module uses a **redirect-based flow**: the customer clicks "Place Order", is redirected to the BOG-hosted payment page, and returns to the store after payment completes.

Key highlights:
- Redirect to BOG-hosted payment page
- OAuth2 client credentials authentication
- Pre-authorization (manual capture) and automatic capture modes
- Full and partial refunds via Magento credit memos
- Multiple payment methods: Card, Google Pay, Apple Pay, BOG P2P, BOG Loyalty
- Split payments for marketplace/multi-vendor scenarios (via IBAN-based distribution)
- SHA256withRSA callback signature verification
- Server-to-server callback + cron reconciler for reliable order processing
- Country restriction support
- Light/Dark payment page themes

**Key design decision**: The Magento order is created *after* the customer returns from the BOG payment page with a successful payment, not before. This prevents ghost orders from abandoned payment flows.

## Requirements

| Requirement | Version |
|---|---|
| Magento | 2.4.8+ |
| PHP | 8.4+ |
| `magento/framework` | * |
| `magento/module-payment` | * |
| `magento/module-sales` | * |
| `magento/module-checkout` | * |
| `magento/module-store` | * |
| `magento/module-backend` | * |

You need a **BOG Payments API account** with a Client ID and Client Secret. These are obtained from the Bank of Georgia merchant portal.

No external PHP SDK dependency is required -- the module communicates directly with the BOG REST API using cURL.

## Installation

### Via Composer (recommended)

```bash
composer require shubo/module-bog-payment
bin/magento module:enable Shubo_BogPayment
bin/magento setup:upgrade
bin/magento cache:flush
```

### Manual Installation

1. Copy the module files to `app/code/Shubo/BogPayment/`.
2. Enable and install:
   ```bash
   bin/magento module:enable Shubo_BogPayment
   bin/magento setup:upgrade
   bin/magento cache:flush
   ```

## Configuration

Navigate to **Stores > Configuration > Sales > Payment Methods > BOG Payments (Bank of Georgia)**.

### Credentials

| Field | Description |
|---|---|
| **Enabled** | Enable or disable the payment method. |
| **Title** | Display name shown to customers at checkout. Default: `BOG Payments`. |
| **Environment** | `Test` or `Live`. Controls which credentials and environment are active. |
| **Client ID** | Your BOG Payments API client ID. |
| **Client Secret** | Your BOG Payments API client secret. Stored encrypted. |

### Payment Settings

| Field | Description |
|---|---|
| **Payment Action** | `Automatic Capture` (default) -- charges immediately and creates an invoice. `Manual Capture` -- pre-authorizes funds, requiring manual capture from admin. |
| **API URL** | Base URL for the BOG Payments API. Default: `https://api.bog.ge/payments/v1`. |
| **OAuth URL** | OAuth2 token endpoint URL. Default: `https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token`. |
| **Payment Lifetime (minutes)** | How long the payment session stays valid. Default: 15 minutes. Range: 2 to 1440 (24 hours). |
| **Payment Page Theme** | `Light` or `Dark` theme for the BOG-hosted payment page. |
| **Allowed Payment Methods** | Multi-select: Card, Google Pay, Apple Pay, BOG P2P, BOG Loyalty. |
| **Debug** | When enabled, logs all API requests and responses to the dedicated log file. |
| **New Order Status** | Initial order status. Default: `pending_payment`. |
| **Sort Order** | Controls the display order of the payment method at checkout. |

### Country Restrictions

| Field | Description |
|---|---|
| **Payment from Applicable Countries** | All Allowed Countries or Specific Countries. |
| **Payment from Specific Countries** | If "Specific Countries" is selected, only these countries can use this payment method. |

### Split Payments

| Field | Description |
|---|---|
| **Enable Split Payments** | Enable fund distribution to multiple BOG merchants. |
| **Auto-Settle After Approval** | Automatically distribute funds when payment is approved. |
| **Split Receivers** | Dynamic rows table. Each row has: IBAN, Amount Type (Percentage or Fixed), Amount, Description. |

Config paths (for programmatic access):
```
payment/shubo_bog/active
payment/shubo_bog/title
payment/shubo_bog/environment
payment/shubo_bog/client_id
payment/shubo_bog/client_secret
payment/shubo_bog/api_url
payment/shubo_bog/oauth_url
payment/shubo_bog/payment_action_mode
payment/shubo_bog/payment_lifetime
payment/shubo_bog/payment_theme
payment/shubo_bog/payment_methods
payment/shubo_bog/split_enabled
payment/shubo_bog/split_auto_settle
payment/shubo_bog/split_receivers
payment/shubo_bog/debug
payment/shubo_bog/order_status
payment/shubo_bog/sort_order
payment/shubo_bog/allowspecific
payment/shubo_bog/specificcountry
```

## Supported Features

| Feature | Status | Details |
|---|---|---|
| Redirect-based payment | Supported | Customer redirected to BOG-hosted payment page |
| Card payments | Supported | Visa, Mastercard, etc. via BOG |
| Google Pay | Supported | Configurable in Allowed Payment Methods |
| Apple Pay | Supported | Configurable in Allowed Payment Methods |
| BOG P2P | Supported | Configurable in Allowed Payment Methods |
| BOG Loyalty | Supported | Configurable in Allowed Payment Methods |
| Automatic Capture (auto-invoice) | Supported | Payment charged on approval, invoice created automatically |
| Manual Capture (pre-authorization) | Supported | Funds held, manual capture via admin button |
| Manual capture from admin | Supported | "Capture Payment" button on order view |
| Void pre-authorized payment | Supported | "Void Payment" button cancels the order |
| Full refund | Supported | Via Magento credit memo |
| Partial refund | Supported | Via Magento credit memo with partial amount |
| Server-to-server callbacks | Supported | BOG POSTs to `/shubo_bog/payment/callback` |
| SHA256withRSA signature verification | Supported | Verifies callback authenticity using BOG's RSA public key |
| Status API fallback verification | Supported | Falls back to checking BOG Receipt API if signature verification fails |
| Cron reconciler | Supported | Checks stuck orders every 5 minutes |
| Manual status check from admin | Supported | "Check BOG Status" button queries API and syncs order |
| Split payments (IBAN-based) | Supported | Fund distribution to sub-merchants via IBAN + percentage |
| OAuth2 authentication | Supported | Client credentials grant with automatic token refresh |
| Multi-currency | Supported | Sends quote currency code to BOG |
| Multi-store / multi-website | Supported | All config fields are website-scoped |
| Country restrictions | Supported | Allow all or specific countries |
| Payment info in admin | Supported | Shows BOG Order ID, status, card type, PAN, payment method |
| Localization (EN/KA) | Supported | Payment page and API requests use store locale |
| Payment page theme | Supported | Light or Dark theme for the BOG payment page |
| Sensitive data protection | Supported | Client secret encrypted in config |
| Order confirmation emails | Supported | Sent after payment is confirmed |
| Idempotency | Supported | UUID v4 Idempotency-Key header on create-order requests |

## Unsupported Features

| Feature | Status |
|---|---|
| Embedded payment form (no redirect) | Not implemented (redirect only) |
| Recurring / subscription payments | Not implemented |
| Saved card / tokenization | Not implemented |
| Installment payments | Not implemented |
| Partial capture of pre-authorized amount | Supported but untested -- the capture API call sends the full amount |
| Admin order creation (phone orders) | Not supported (`can_use_internal` = 0) |
| Settlement API for split distribution | The split data is sent at order creation time via the `config.split` payload, not as a separate post-payment settlement call (unlike the TBC module) |
| Multiple address checkout | Disabled in `payment.xml` |

## Payment Flow

```
Customer selects BOG payment at checkout
            |
            v
Customer clicks "Place Order"
  -> JS calls POST /shubo_bog/payment/initiate
  -> Backend authenticates via OAuth2 (client_credentials)
  -> Backend calls BOG API: POST /ecommerce/orders
  -> BOG returns order ID + redirect URL
  -> Frontend redirects to BOG payment page
            |
            v
Customer completes payment on BOG page
  (Card entry, 3DS, Google Pay, etc.)
            |
      +-----+-----+
      |           |
   Success      Failure
      |           |
      v           v
Redirect to     Redirect to
/shubo_bog/     checkout/
payment/return  onepage/failure
      |
      v
ReturnAction controller:
  -> Checks BOG Status API (GET /receipt/{id})
  -> If completed: creates Magento order, captures, invoices
  -> If pending: creates order in pending_payment state
  -> Redirects to success page
            |
            v
(Meanwhile) BOG sends POST callback to
  /shubo_bog/payment/callback
  -> Verifies SHA256withRSA signature (or falls back to Status API)
  -> Updates order if not already processed
  -> Sends order confirmation email
            |
            v
(Every 5 min) Cron reconciler checks stuck orders
  -> Queries BOG Status API
  -> Processes completed / cancels failed or expired
```

**Key design decision**: No Magento order exists until the customer returns from BOG. The `Initiate` controller works with the quote only. The `ReturnAction` controller creates the actual Magento order via `CartManagementInterface::placeOrder()`. This prevents ghost orders.

## Order Status Flow

```
Customer returns from BOG
      |
  +---+---+
  |       |
Success  Pending
  |       |
  v       v
processing  pending_payment
(invoice)       |
           +----+----+
           |    |    |
        Approved Failed Expired
           |    |    |
           v    v    v
      processing canceled canceled
      (invoice)

If Payment Action = "Manual Capture":
    Success ──> processing (funds held, no invoice)
                     |
              +------+------+
              |             |
           Capture        Void
              |             |
              v             v
         processing     canceled
         (invoice)
```

## Split Payments (Marketplace)

Split payments allow distributing order funds to multiple BOG merchants. This is designed for marketplace scenarios.

### How It Works (BOG)

Unlike the TBC/Flitt module which uses a post-payment settlement API, the BOG module sends split payment data **at order creation time** as part of the create-order API request:

```json
{
  "config": {
    "split": {
      "split_payments": [
        {
          "iban": "GE12TB1234567890123456",
          "percent": 60.0,
          "description": "Vendor payment"
        }
      ]
    }
  }
}
```

### Configuration Methods

**Admin-configured receivers** (static): Set fixed receivers in the admin panel using IBANs.

**Event-based receivers** (dynamic): Other modules can listen to the `shubo_bog_payment_split_before` event and call `addSplitPayment()` on the `SplitPaymentDataInterface` object.

### Key Differences from TBC Module

| Aspect | TBC (Flitt) | BOG |
|---|---|---|
| Split identifier | Merchant ID (Flitt) | IBAN (bank account) |
| Split timing | Post-payment settlement API | At order creation time |
| Amount types | Fixed + Percentage | Percentage only (in API payload) |
| Settlement button | Yes (manual trigger) | No (sent with order) |

## Admin Actions

The following buttons appear on the order view page for BOG-paid orders:

| Button | Appears When | Action |
|---|---|---|
| **Check BOG Status** | Any BOG order with a BOG order ID | Queries BOG Status API, displays status, auto-processes if completed |
| **Capture Payment** | Pre-authorized order (not yet captured) | Sends capture request to BOG API, creates invoice |
| **Void Payment** | Pre-authorized order (not yet captured) | Cancels the Magento order; hold expires on bank side |

## Technical Architecture

### Key Classes

| Class | Purpose |
|---|---|
| `Gateway\Config\Config` | Configuration reader; URL builders for all API endpoints |
| `Gateway\Http\Client\CreatePaymentClient` | Creates orders via BOG `/ecommerce/orders` with OAuth2 bearer token |
| `Gateway\Http\Client\RefundClient` | Sends refunds via BOG `/checkout/refund` |
| `Gateway\Http\Client\CaptureClient` | Captures pre-authorized payments via `/payment/authorization/approve/{id}` |
| `Gateway\Http\Client\StatusClient` | Checks status via `/receipt/{id}` |
| `Gateway\Request\InitializeRequestBuilder` | Builds the create-order request with basket, URLs, capture mode, TTL |
| `Gateway\Request\RefundRequestBuilder` | Builds the refund request with BOG order ID and amount |
| `Gateway\Request\SplitDataBuilder` | Adds split payment config to the create-order request |
| `Gateway\Response\InitializeHandler` | Stores BOG order ID, redirect URL, details URL on payment |
| `Gateway\Response\RefundHandler` | Stores refund status and transaction ID |
| `Gateway\Validator\ResponseValidator` | Validates HTTP status codes from BOG API |
| `Gateway\Validator\CallbackValidator` | SHA256withRSA signature verification + Status API fallback |
| `Model\OAuthTokenProvider` | OAuth2 client_credentials token management with in-memory caching |
| `Controller\Payment\Initiate` | AJAX endpoint: creates BOG order from quote, returns redirect URL |
| `Controller\Payment\ReturnAction` | Handles customer return: creates Magento order, processes payment |
| `Controller\Payment\Callback` | Server-to-server callback from BOG |
| `Controller\Payment\Confirm` | Safety net confirmation endpoint |
| `Cron\PendingOrderReconciler` | Reconciles stuck pending orders |
| `Observer\SetPendingPaymentState` | Sets order to `pending_payment` on placement |
| `Plugin\AddOrderButtons` | Adds admin toolbar buttons |
| `Model\Ui\ConfigProvider` | Provides checkout JS configuration |
| `Block\Payment\Info` | Renders payment details in admin |

### Magento Payment Gateway Pattern

The module uses Magento's Payment Gateway framework with virtual types:

- **Facade**: `ShuboBogPaymentFacade` (virtual type of `Magento\Payment\Model\Method\Adapter`)
- **Command Pool**: `initialize` and `refund` commands
- **Request Builders**: Composite builder with `InitializeRequestBuilder` + `SplitDataBuilder`
- **HTTP Clients**: Direct cURL calls with OAuth2 bearer token
- **Response Handlers**: Store BOG order IDs, redirect URLs, refund status
- **Validators**: `ResponseValidator` for HTTP status, `CallbackValidator` for signatures, `CountryValidator` for country restrictions

### OAuth2 Authentication

The BOG Payments API uses OAuth2 client credentials grant:
- Token endpoint: `https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token`
- Grant type: `client_credentials`
- Auth: HTTP Basic (Client ID : Client Secret)
- Token is cached in memory with a 60-second buffer before expiry

### Callback Signature Verification

BOG callbacks include a `Callback-Signature` header containing a base64-encoded SHA256withRSA signature. The module verifies this signature using BOG's RSA public key, which is loaded from encrypted system config at `payment/shubo_bog/rsa_public_key` (Stores → Configuration → Sales → Payment Methods → BOG Payments → RSA Public Key, or via `bin/magento shubo:payment:switch-to-prod:bog --rsa-key-path=...`). If the key is unset or signature verification fails, the module falls back to checking the payment status via the BOG Receipt/Status API.

### Events Dispatched

| Event | Purpose |
|---|---|
| `shubo_bog_payment_split_before` | Allows modules to populate split payment data via `SplitPaymentDataInterface` |

## API Endpoints

### BOG Payments API Endpoints Used

| Endpoint | Method | Purpose |
|---|---|---|
| `/ecommerce/orders` | POST | Create payment order |
| `/receipt/{order_id}` | GET | Check payment status |
| `/checkout/refund` | POST | Refund payment |
| `/payment/authorization/approve/{order_id}` | POST | Capture pre-authorized payment |

Base URL: `https://api.bog.ge/payments/v1`

OAuth token endpoint: `https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token`

### Module Frontend Routes

| URL | Method | Controller | Purpose |
|---|---|---|---|
| `/shubo_bog/payment/initiate` | POST | `Initiate` | Create BOG order and get redirect URL (AJAX) |
| `/shubo_bog/payment/return` | GET | `ReturnAction` | Handle customer return from BOG |
| `/shubo_bog/payment/callback` | POST | `Callback` | Server-to-server callback (CSRF exempt) |
| `/shubo_bog/payment/confirm` | POST | `Confirm` | Safety net payment confirmation (AJAX) |

### Module Admin Routes

| URL | Controller | Purpose |
|---|---|---|
| `/shubo_bog/order/checkStatus` | `CheckStatus` | Query BOG API and sync order status |
| `/shubo_bog/order/capture` | `Capture` | Capture a pre-authorized payment |
| `/shubo_bog/order/voidPayment` | `VoidPayment` | Void payment and cancel order |

## Logging

All module logs are written to a dedicated file:

```
var/log/shubo_bog_payment.log
```

Enable **Debug** mode in configuration to log full API request/response bodies including OAuth token requests, create-order calls, status checks, and callback processing.

## Cron Jobs

| Job | Schedule | Purpose |
|---|---|---|
| `shubo_bog_pending_order_reconciler` | Every 5 minutes | Checks orders in `pending_payment` state older than 15 minutes. Queries BOG Status API and processes completed/failed/expired orders. Max 50 orders per run. |

## Internationalization

The module includes translations for:
- **English** (`en_US`)
- **Georgian** (`ka_GE`)

The BOG payment page language is automatically set based on the Magento store locale via the `Accept-Language` header on API requests. Supported languages: `en`, `ka`.

## Troubleshooting

### Customer redirected but order not created
- This is by design. The Magento order is only created when the customer returns from BOG.
- If the customer closes the browser on the BOG page, the callback and cron reconciler will not create an order since the quote has not been converted.
- Check `var/log/shubo_bog_payment.log` for details.

### Order stuck in "pending_payment"
- Use the "Check BOG Status" button in admin to manually query and sync.
- The cron reconciler should automatically process stuck orders after 15 minutes.
- Verify the callback URL (`/shubo_bog/payment/callback`) is accessible from the internet (HTTPS required).
- Check that your OAuth2 credentials are valid and not expired.

### OAuth2 authentication fails
- Verify Client ID and Client Secret are correctly set.
- Ensure the OAuth URL is correct: `https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token`.
- Check if the credentials are for the correct environment (test vs. live).
- Check `var/log/shubo_bog_payment.log` for the HTTP response from the token endpoint.

### Callback signature verification fails
- The RSA public key is loaded from encrypted admin config at `payment/shubo_bog/rsa_public_key`. If BOG rotates their key, paste the new PEM into the admin field (or re-run `bin/magento shubo:payment:switch-to-prod:bog --rsa-key-path=...`) — no code change.
- An empty config (pre-cutover state) logs at INFO; a malformed PEM logs at WARNING. Both fall through to the Status API.
- The module falls back to the Status API when signature verification fails, so orders should still process correctly.
- Check `var/log/shubo_bog_payment.log` for "signature verification failed" or "not a valid PEM" messages.

### Refund fails
- Ensure the BOG order ID is stored on the payment (`bog_order_id` in additional info).
- Check `var/log/shubo_bog_payment.log` for the BOG API error response.
- Verify your OAuth2 credentials have refund permissions.

### Payment page shows wrong language
- The language is derived from the Magento store locale. Set the store locale to `ka_GE` for Georgian or any English locale for English.

### Split payments not working
- BOG split payments use IBANs, not merchant IDs.
- Verify the IBANs are valid Georgian bank accounts.
- The split data is sent at order creation time. If the create-order call fails, check the API response in debug logs.
- The `shubo_bog_payment_split_before` event must be observed by your module to provide dynamic split data.

## License

Apache License 2.0. See [LICENSE](LICENSE) for details.

Copyright 2026 Nikoloz Shubitidze (Shubo).
