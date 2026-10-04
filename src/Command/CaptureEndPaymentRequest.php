<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * Runs when the pay URL is hit again for a capture already sent to XPay: either the
 * customer is coming back from the hosted page ($returnedFromGateway), or they are
 * re-entering the payment from the order page and should be sent back to it.
 */
final class CaptureEndPaymentRequest implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(
        ?string $hash,
        private readonly bool $returnedFromGateway = false,
    ) {
        $this->hash = $hash;
    }

    public function isReturnedFromGateway(): bool
    {
        return $this->returnedFromGateway;
    }
}
