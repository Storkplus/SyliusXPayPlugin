<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\CommandProvider;

use Storkplus\SyliusXPayPlugin\Command\CancelPaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\CaptureEndPaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\CapturePaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\NotifyPaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\RefundPaymentRequest;
use Storkplus\SyliusXPayPlugin\Command\StatusPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Maps a payment request action to the command handled by this plugin.
 *
 * A capture request is dispatched twice: first while it is "new" (create the
 * Checkout Session and redirect), then again when the customer lands back on the
 * same pay URL while it is "processing" (read the session outcome).
 */
final class XPayCommandProvider implements PaymentRequestCommandProviderInterface
{
    /** Query parameter XPay sends the customer back with (see CheckoutSessionPayloadProvider). */
    public const RETURN_QUERY_PARAMETER = 'xpay_return';

    public function __construct(
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return in_array($paymentRequest->getAction(), [
            PaymentRequestInterface::ACTION_AUTHORIZE,
            PaymentRequestInterface::ACTION_CANCEL,
            PaymentRequestInterface::ACTION_CAPTURE,
            PaymentRequestInterface::ACTION_NOTIFY,
            PaymentRequestInterface::ACTION_REFUND,
            PaymentRequestInterface::ACTION_STATUS,
        ], true);
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        $hash = $paymentRequest->getId();

        return match ($paymentRequest->getAction()) {
            PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE => PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState()
                ? new CaptureEndPaymentRequest($hash, $this->isReturnFromGateway())
                : new CapturePaymentRequest($hash),
            PaymentRequestInterface::ACTION_STATUS => new StatusPaymentRequest($hash),
            PaymentRequestInterface::ACTION_NOTIFY => new NotifyPaymentRequest($hash),
            PaymentRequestInterface::ACTION_REFUND => new RefundPaymentRequest($hash),
            PaymentRequestInterface::ACTION_CANCEL => new CancelPaymentRequest($hash),
            default => throw new \InvalidArgumentException(sprintf('Unsupported XPay payment request action "%s".', $paymentRequest->getAction())),
        };
    }

    private function isReturnFromGateway(): bool
    {
        return null !== $this->requestStack?->getCurrentRequest()?->query->get(self::RETURN_QUERY_PARAMETER);
    }
}
