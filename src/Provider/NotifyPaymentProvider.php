<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Provider;

use Storkplus\SyliusXPayPlugin\Webhook\WebhookGuard;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the payment a verified webhook event is about, through the payment
 * request hash we put in the Checkout Session metadata.
 */
final class NotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    /**
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
    ) {
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return XPayGateway::FACTORY_NAME === $paymentMethod->getGatewayConfig()?->getFactoryName();
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        $event = $request->attributes->get(XPayGateway::REQUEST_ATTRIBUTE_EVENT);
        if (!is_array($event)) {
            // Only reachable if the signature listener did not run: never trust the body.
            throw new BadRequestHttpException('Unverified XPay webhook.');
        }

        $hash = WebhookGuard::sessionMetadata($event)[XPayGateway::METADATA_PAYMENT_REQUEST_HASH] ?? null;
        if (!is_string($hash) || '' === $hash) {
            throw new NotFoundHttpException('The XPay session carries no Sylius payment request reference.');
        }

        $paymentRequest = $this->paymentRequestRepository->find($hash);
        if (!$paymentRequest instanceof PaymentRequestInterface || $paymentRequest->getMethod() !== $paymentMethod) {
            throw new NotFoundHttpException(sprintf('No XPay payment request "%s" for this payment method.', $hash));
        }

        return $paymentRequest->getPayment();
    }
}
