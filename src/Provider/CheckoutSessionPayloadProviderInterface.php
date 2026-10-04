<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Provider;

use Sylius\Component\Payment\Model\PaymentRequestInterface;

interface CheckoutSessionPayloadProviderInterface
{
    /**
     * Builds the POST /checkout/sessions body for a capture payment request.
     *
     * @return array<string, mixed>
     */
    public function provide(PaymentRequestInterface $paymentRequest): array;
}
