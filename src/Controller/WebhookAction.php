<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Controller;

use Storkplus\SyliusXPayPlugin\Webhook\WebhookGuard;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static webhook endpoint, one per shop: https://<shop>/payment/xpay/webhook.
 *
 * It does not depend on the payment method code, so it can be registered in XPay
 * before the payment method exists. The event is matched to the XPay method whose
 * signing secret verifies it, then handed to the standard Sylius notify action.
 */
final class WebhookAction
{
    /**
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param callable(Request, string): Response $notifyAction
     */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly WebhookGuard $webhookGuard,
        private readonly mixed $notifyAction,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $candidates = array_filter(
            $this->paymentMethodRepository->findAll(),
            static fn (mixed $method): bool => $method instanceof PaymentMethodInterface &&
                XPayGateway::FACTORY_NAME === $method->getGatewayConfig()?->getFactoryName(),
        );

        $result = $this->webhookGuard->guard($request, $candidates);
        if ($result instanceof Response) {
            return $result;
        }

        /** @var callable(Request, string): Response $notify */
        $notify = $this->notifyAction;

        return $notify($request, (string) $result->getCode());
    }
}
