<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusXPayPlugin\Api\XPayApiException;
use Storkplus\SyliusXPayPlugin\Api\XPayClientInterface;
use Storkplus\SyliusXPayPlugin\Command\CaptureEndPaymentRequest;
use Storkplus\SyliusXPayPlugin\Processor\SessionPaymentProcessorInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * The redirect back from XPay is a UX courtesy, never proof of payment: the
 * session is always read from the API. The signed webhook does the same work and
 * whichever arrives first wins; the other becomes a no-op.
 */
final class CaptureEndPaymentRequestHandler
{
    use PaymentRequestStateTrait;

    /** Errors returned by /expire when the session cannot be closed because money is moving. */
    private const EXPIRE_RACE_ERRORS = ['payment_still_confirming', 'payment_already_completed', 'resource_invalid_state'];

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly XPayClientInterface $client,
        private readonly SessionPaymentProcessorInterface $sessionPaymentProcessor,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CaptureEndPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_PROCESSING !== $paymentRequest->getState()) {
            return;
        }

        $payment = $this->getPayment($paymentRequest);
        $sessionId = $this->sessionPaymentProcessor->getSnapshot($payment)['session_id'] ?? null;
        if (!is_string($sessionId)) {
            $this->fail($paymentRequest, ['message' => 'No XPay Checkout Session is bound to this payment.']);

            return;
        }

        $secretKey = $this->getSecretKey($paymentRequest);

        try {
            $session = $this->client->retrieveCheckoutSession($secretKey, $sessionId);
        } catch (XPayApiException $exception) {
            // Leave the request processing: the customer can reload, and the webhook still settles it.
            $this->logger->error('Could not read the XPay Checkout Session on return', [
                'session' => $sessionId,
                'error' => $exception->toArray(),
            ]);

            return;
        }

        $result = $this->sessionPaymentProcessor->process($payment, $session);

        if (SessionPaymentProcessorInterface::RESULT_OPEN === $result) {
            if (!$command->isReturnedFromGateway()) {
                // The customer re-entered the payment from the order page: keep the request
                // processing so the response provider sends them back to the same session.
                return;
            }

            // Declined card, rejected 3DS or abandoned voucher: close the session so it can
            // no longer be paid, and fail the payment so Sylius offers a fresh attempt.
            $result = $this->expireAndFail($secretKey, $sessionId, $paymentRequest);
        }

        $paymentRequest->setResponseData(array_merge($paymentRequest->getResponseData(), ['result' => $result]));
        $this->complete($paymentRequest);
    }

    private function expireAndFail(string $secretKey, string $sessionId, PaymentRequestInterface $paymentRequest): string
    {
        $payment = $this->getPayment($paymentRequest);

        try {
            $session = $this->client->expireCheckoutSession($secretKey, $sessionId);
        } catch (XPayApiException $exception) {
            if (!in_array($exception->getErrorCode(), self::EXPIRE_RACE_ERRORS, true)) {
                $this->logger->warning('Could not expire the XPay Checkout Session', [
                    'session' => $sessionId,
                    'error' => $exception->toArray(),
                ]);
            }

            try {
                $session = $this->client->retrieveCheckoutSession($secretKey, $sessionId);
            } catch (XPayApiException) {
                return SessionPaymentProcessorInterface::RESULT_OPEN;
            }
        }

        $result = $this->sessionPaymentProcessor->process($payment, $session);
        if (SessionPaymentProcessorInterface::RESULT_OPEN !== $result) {
            return $result;
        }

        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL);
        }

        return SessionPaymentProcessorInterface::RESULT_FAILED;
    }
}
