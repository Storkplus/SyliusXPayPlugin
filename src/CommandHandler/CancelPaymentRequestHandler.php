<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusXPayPlugin\Api\XPayApiException;
use Storkplus\SyliusXPayPlugin\Api\XPayClientInterface;
use Storkplus\SyliusXPayPlugin\Command\CancelPaymentRequest;
use Storkplus\SyliusXPayPlugin\Processor\SessionPaymentProcessorInterface;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Sent when a Sylius payment bound to an XPay session is cancelled (e.g. the order
 * was cancelled): expires the session so the customer can no longer pay it.
 */
final class CancelPaymentRequestHandler
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

    public function __invoke(CancelPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $snapshot = $this->sessionPaymentProcessor->getSnapshot($this->getPayment($paymentRequest));
        $sessionId = $snapshot['session_id'] ?? null;

        if (is_string($sessionId) && XPayGateway::SESSION_STATUS_OPEN === ($snapshot['status'] ?? XPayGateway::SESSION_STATUS_OPEN)) {
            try {
                $session = $this->client->expireCheckoutSession($this->getSecretKey($paymentRequest), $sessionId);
                $this->sessionPaymentProcessor->bind($this->getPayment($paymentRequest), $session);
            } catch (XPayApiException $exception) {
                $this->logger->warning('Could not expire the XPay Checkout Session of a cancelled payment', [
                    'session' => $sessionId,
                    'error' => $exception->toArray(),
                ]);
                $this->fail($paymentRequest, $exception->toArray());

                return;
            }
        }

        $this->complete($paymentRequest);
    }
}
