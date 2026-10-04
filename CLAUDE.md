# CLAUDE.md

XPay (Egypt) payment gateway plugin for Sylius 2.1+, built on the Sylius 2 Payment Request API (no Payum).
Package `storkplus/sylius-xpay-plugin`, namespace `Storkplus\SyliusXPayPlugin`, gateway factory `xpay`.
Started from `Sylius/PluginSkeleton` (2.2 branch).

## Commands

The host PHP may lack extensions; run tooling in the Sylius PHP image:

```bash
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app --entrypoint sh ghcr.io/sylius/sylius-php:8.3-alpine -c '<cmd>'
```

- `vendor/bin/phpunit --testsuite unit`
- `vendor/bin/phpstan analyse -c phpstan.neon` (level max, `src/` only)
- `vendor/bin/ecs check [--fix]`
- `APP_ENV=test vendor/bin/console lint:container` (compiles the plugin into `sylius/test-application`)

## Architecture

- `XPayGateway` — every shared constant (factory name, config keys, details key, handled events).
- `Api/XPayClient` — Symfony HttpClient wrapper, Bearer secret key, `Idempotency-Key` = payment request hash.
- `CommandProvider/XPayCommandProvider` — payment request action → command. A capture is dispatched twice:
  `new` → `CapturePaymentRequest` (create session), `processing` → `CaptureEndPaymentRequest` (customer back on
  `/pay/{hash}`; `?xpay_return=` tells a return from XPay apart from re-entering the payment).
- `Processor/SessionPaymentProcessor` — the single place mapping a Checkout Session onto the `sylius_payment`
  graph. Idempotent, never downgrades, ignores sessions not bound to the payment, refuses amount mismatches.
- `Webhook/WebhookGuard` — signature (tries every XPay method's whsec_), event filtering (204), stores the verified
  event in a request attribute. Used by `Controller/WebhookAction` (static `/payment/xpay/webhook`, forwards to the
  Sylius notify action) and `EventListener/WebhookRequestListener` (per-method `/payment-methods/{code}`).
  `NotifyPayloadProcessor` + `NotifyPaymentProvider` plug the event into the standard notify flow.
- `SessionPaymentProcessor` locks the order row and refreshes before deciding: webhook and return race.
- `EventListener/PaymentWorkflowListener` — payment `refund` / `cancel` transitions → refund / cancel requests.
- `OrderPay/Provider/XPayHttpResponseProvider` — first argument typed `mixed`: it is `RequestConfiguration`
  up to Sylius 2.2 and `Request` from 2.3.

## Rules

- Payment state changes only through `SessionPaymentProcessor` (or an explicit `fail` on capture errors).
- Never trust the return redirect alone; always read the session from the API or a verified webhook.
- Handlers run inside the `doctrine_transaction` middleware: don't throw on API errors, fail the payment request.
- Keep translations in both `*.en.yaml` and `*.ar.yaml`.
