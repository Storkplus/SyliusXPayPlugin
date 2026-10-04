<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusXPayPlugin\Api\XPayApiException;
use Storkplus\SyliusXPayPlugin\Api\XPayClientInterface;
use Storkplus\SyliusXPayPlugin\Command\StatusPaymentRequest;
use Storkplus\SyliusXPayPlugin\Processor\SessionPaymentProcessorInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * After-pay step. Re-reads the session only while the payment is not final, so a
 * customer refreshing the page picks up a payment the webhook settled meanwhile.
 */
final class StatusPaymentRequestHandler
{
    use PaymentRequestStateTrait;

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly XPayClientInterface $client,
        private readonly SessionPaymentProcessorInterface $sessionPaymentProcessor,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(StatusPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = $this->getPayment($paymentRequest);

        $sessionId = $this->sessionPaymentProcessor->getSnapshot($payment)['session_id'] ?? null;
        if (is_string($sessionId) && in_array($payment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true)) {
            try {
                $session = $this->client->retrieveCheckoutSession($this->getSecretKey($paymentRequest), $sessionId);
                $this->sessionPaymentProcessor->process($payment, $session);
            } catch (XPayApiException $exception) {
                $this->logger->warning('Could not refresh the XPay Checkout Session status', [
                    'session' => $sessionId,
                    'error' => $exception->toArray(),
                ]);
            }
        }

        $this->complete($paymentRequest);
    }
}
