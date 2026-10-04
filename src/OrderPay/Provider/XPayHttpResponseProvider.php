<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\OrderPay\Provider;

use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * - capture while processing: 303 to the hosted XPay checkout page;
 * - status (after-pay) for a payment still processing (Fawry voucher, bank
 *   verification in flight): the order is placed, so show the thank-you page
 *   instead of the retry page Sylius would pick for a non-completed payment.
 *
 * Everything else falls through to the Sylius defaults.
 *
 * The first argument is a RequestConfiguration up to Sylius 2.2 and a Request from
 * 2.3 on; it is unused here, so it is typed mixed to satisfy both interfaces.
 */
final class XPayHttpResponseProvider implements HttpResponseProviderInterface
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly string $thankYouRoute = 'sylius_shop_order_thank_you',
    ) {
    }

    public function supports(mixed $request, PaymentRequestInterface $paymentRequest): bool
    {
        return match ($paymentRequest->getAction()) {
            PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE => PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState() &&
                is_string($paymentRequest->getResponseData()['url'] ?? null),
            PaymentRequestInterface::ACTION_STATUS => PaymentInterface::STATE_PROCESSING === $paymentRequest->getPayment()->getState(),
            default => false,
        };
    }

    public function getResponse(mixed $request, PaymentRequestInterface $paymentRequest): Response
    {
        if (PaymentRequestInterface::ACTION_STATUS === $paymentRequest->getAction()) {
            return new RedirectResponse($this->router->generate($this->thankYouRoute), Response::HTTP_SEE_OTHER);
        }

        /** @var string $url */
        $url = $paymentRequest->getResponseData()['url'];

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }
}
