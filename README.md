# Sylius XPay Plugin

[XPay](https://docs.xpay.app/en) payment gateway for Sylius 2.1+: cards (with 3-D Secure), Fawry and ValU,
through XPay's **Hosted Checkout**. Built on the Sylius 2 *Payment Request* architecture (no Payum).

- Hosted Checkout Session per payment, redirect to `checkout.xpay.app`, return to the shop.
- **Signed webhooks** (`XPay-Signature`, HMAC-SHA256, 5-minute replay window) are the source of truth for payment status.
- **Asynchronous methods** (Fawry): the order is placed with the payment in `processing`, completed when the reference is paid.
- **Refunds** from the Sylius admin call `POST /refunds` (idempotent).
- **Cancelled payments** expire the open Checkout Session.
- Admin form in English and Arabic, with the webhook URL to paste into XPay.

## Requirements

| | Version |
| --- | --- |
| PHP | 8.2+ |
| Sylius | 2.1, 2.2, 2.3 |

## Installation

The package is private; add the repository and require it:

```bash
composer config repositories.sylius-xpay-plugin vcs https://github.com/Storkplus/SyliusXPayPlugin
composer require storkplus/sylius-xpay-plugin
```

Register the bundle in `config/bundles.php`:

```php
Storkplus\SyliusXPayPlugin\StorkplusSyliusXPayPlugin::class => ['all' => true],
```

Import its configuration in `config/packages/storkplus_sylius_xpay.yaml`:

```yaml
imports:
    - { resource: "@StorkplusSyliusXPayPlugin/config/config.yaml" }

# Optional, these are the defaults:
# storkplus_sylius_xpay:
#     api_base_url: 'https://api.xpay.app'
#     timeout: 20
```

No routes and no database migrations are needed: the plugin uses the Sylius payment routes
(`/pay/{hash}`, `/after-pay/{hash}`, `/payment-methods/{code}`) and stores what it needs in the payment details.

Clear the cache. The payment request messenger transport must stay synchronous (the Sylius default,
`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=sync://`) so the customer is redirected in the same request.

## Setting up a payment method

1. In XPay, open **Developers → API keys** and copy a secret key (`sk_test_…` while testing).
2. In Sylius admin, **Configuration → Payment methods → Create → XPay (cards, Fawry, ValU)**.
   Fill in the code (e.g. `xpay`), the channels, a name, and the secret key. Save.
3. Edit the method again: the form now shows the webhook URL, `https://<your-shop>/payment-methods/<code>`.
4. In XPay, open **Developers → Webhooks → Add endpoint**, paste the URL and subscribe to:
   - `checkout.session.completed`
   - `checkout.session.async_payment_succeeded`
   - `checkout.session.async_payment_failed`
   - `checkout.session.expired`
5. Copy the `whsec_…` signing secret XPay shows once, paste it into **Webhook signing secret**, save.

Test and live mode are separate in XPay: switching to live means a `sk_live_…` key **and** a new live
webhook endpoint with its own `whsec_…` secret.

Optional settings:

- **Checkout page language**: follow the order locale (Arabic/English), or force one.
- **Payment method configuration ID**: restrict which methods XPay shows (Settings → Payment methods in XPay).

### From the command line

```bash
# Check the key, print the webhook URL
bin/console storkplus:xpay:webhook xpay --base-url=https://mystore.example

# Also create the endpoint through the API and store its signing secret
bin/console storkplus:xpay:webhook xpay --base-url=https://mystore.example --register
```

## How a payment flows

```
Sylius checkout complete
  └─ /pay/{hash}                    capture request (new)
       └─ POST /checkout/sessions   Idempotency-Key = payment request hash
       └─ 303 → checkout.xpay.app/c/cs_…
customer pays (3-D Secure / Fawry reference / ValU)
  ├─ webhook  POST /payment-methods/{code}   (signature verified first)
  │     checkout.session.completed / async_payment_* / expired → payment state
  └─ redirect /pay/{hash}?xpay_return=success|cancel
        └─ GET /checkout/sessions/{id} → payment state
        └─ /after-pay/{hash} → thank-you page (paid or processing) or "pay again" (failed)
```

Payment states:

| XPay session | Sylius payment |
| --- | --- |
| `paymentStatus: paid` (amount and currency match) | `completed` |
| `status: complete`, `paymentStatus: unpaid` (Fawry reference issued) | `processing` |
| open, bank verification still running | `processing` |
| `checkout.session.async_payment_failed` / `expired` | `failed` (Sylius opens a new payment) |
| customer back via the cancel URL, nothing in flight | session expired, payment `failed` |

Safety rules:

- The return redirect is never trusted on its own: the session is always read from the API, and webhooks
  are applied only after `XPay-Signature` verification.
- A session whose amount or currency differs from the Sylius payment never completes it (logged as critical).
- Events for a session that is no longer bound to the payment, for sessions Sylius did not create
  (Payment Links, another shop on the same account) and for other event types are acknowledged with
  `204` and ignored, so they never cause retries or failed-delivery emails.
- Invalid signatures get `400`.

Amounts: Sylius stores two implied decimals for every currency; for KWD, BHD, JOD, OMR and LYD the plugin
converts to XPay's three-decimal minor unit.

## Testing with XPay test mode

Use card `5123 4500 0000 0008`, expiry `01/39`, CVV `100` for an approved payment. Other expiries simulate
declines (`05/39`), insufficient funds (`01/27`) and more; see [XPay test mode](https://docs.xpay.app/en/get-started/test-mode).
Fawry and ValU need a total of at least 50 EGP.

## Development

```bash
composer install
vendor/bin/phpunit --testsuite unit
vendor/bin/phpstan analyse -c phpstan.neon
vendor/bin/ecs check
APP_ENV=test vendor/bin/console lint:container

# End to end (Docker): test application + MySQL + a mock XPay API sending signed webhooks.
# Covers card payment, decline and retry, Fawry, forged webhook, refund and the admin form.
tests/E2E/run.sh
```

Logs go to the `xpay` Monolog channel.
