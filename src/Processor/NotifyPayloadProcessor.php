<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Processor;

use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Bundle\PaymentBundle\Processor\NotifyPayloadProcessorInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stores only the verified event on XPay notify requests instead of the whole
 * normalized HTTP request (headers, signature, raw body).
 */
final class NotifyPayloadProcessor implements NotifyPayloadProcessorInterface
{
    public function __construct(
        private readonly NotifyPayloadProcessorInterface $decorated,
    ) {
    }

    public function process(PaymentRequestInterface $paymentRequest, Request $request): void
    {
        $event = $request->attributes->get(XPayGateway::REQUEST_ATTRIBUTE_EVENT);
        if (XPayGateway::FACTORY_NAME === $paymentRequest->getMethod()->getGatewayConfig()?->getFactoryName() && is_array($event)) {
            $paymentRequest->setPayload(['event' => $event]);

            return;
        }

        $this->decorated->process($paymentRequest, $request);
    }
}
