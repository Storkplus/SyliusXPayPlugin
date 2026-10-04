<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\EventListener;

use Storkplus\SyliusXPayPlugin\Webhook\WebhookGuard;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Guards the per-method Sylius notify endpoint (/payment-methods/{code}) for XPay
 * methods before the Sylius controller runs. The static per-shop endpoint
 * (/payment/xpay/webhook, see WebhookAction) goes through the same WebhookGuard.
 */
final class WebhookRequestListener
{
    public const NOTIFY_ROUTE = 'sylius_payment_method_notify';

    /**
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly WebhookGuard $webhookGuard,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (self::NOTIFY_ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $code = $request->attributes->get('code');
        if (!is_string($code)) {
            return;
        }

        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $code]);
        if (!$paymentMethod instanceof PaymentMethodInterface || XPayGateway::FACTORY_NAME !== $paymentMethod->getGatewayConfig()?->getFactoryName()) {
            return;
        }

        $result = $this->webhookGuard->guard($request, [$paymentMethod]);
        if ($result instanceof Response) {
            $event->setResponse($result);
        }
    }
}
