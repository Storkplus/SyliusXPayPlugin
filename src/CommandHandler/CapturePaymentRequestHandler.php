<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusXPayPlugin\Api\XPayApiException;
use Storkplus\SyliusXPayPlugin\Api\XPayClientInterface;
use Storkplus\SyliusXPayPlugin\Command\CapturePaymentRequest;
use Storkplus\SyliusXPayPlugin\Processor\SessionPaymentProcessorInterface;
use Storkplus\SyliusXPayPlugin\Provider\CheckoutSessionPayloadProviderInterface;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class CapturePaymentRequestHandler
{
    use PaymentRequestStateTrait;

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly XPayClientInterface $client,
        private readonly CheckoutSessionPayloadProviderInterface $payloadProvider,
        private readonly SessionPaymentProcessorInterface $sessionPaymentProcessor,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CapturePaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = $this->getPayment($paymentRequest);

        $currencyCode = strtoupper((string) $payment->getCurrencyCode());
        if (!in_array($currencyCode, XPayGateway::SUPPORTED_CURRENCIES, true)) {
            $this->failCapture($paymentRequest, ['message' => sprintf('XPay does not support the currency "%s".', $currencyCode), 'code' => 'currency_invalid']);

            return;
        }

        try {
            $session = $this->client->createCheckoutSession(
                $this->getSecretKey($paymentRequest),
                $this->payloadProvider->provide($paymentRequest),
                // One session per payment request: a retried request replays the same session.
                (string) $paymentRequest->getId(),
            );
        } catch (XPayApiException $exception) {
            $this->logger->error('Could not create the XPay Checkout Session', [
                'order' => $payment->getOrder()?->getNumber(),
                'payment_id' => $payment->getId(),
                'error' => $exception->toArray(),
            ]);
            $this->failCapture($paymentRequest, $exception->toArray());

            return;
        }

        $url = $session['url'] ?? null;
        if (!is_string($url) || !is_string($session['id'] ?? null)) {
            $this->failCapture($paymentRequest, ['message' => 'XPay returned a Checkout Session without id or url.']);

            return;
        }

        $this->sessionPaymentProcessor->bind($payment, $session);

        $paymentRequest->setResponseData([
            'session_id' => $session['id'],
            'url' => $url,
        ]);

        $this->process($paymentRequest);
    }

    /**
     * Failing the payment (not only the request) is what makes Sylius open a fresh
     * payment and show the "payment failed" message, so the customer can retry.
     *
     * @param array<string, mixed> $error
     */
    private function failCapture(PaymentRequestInterface $paymentRequest, array $error): void
    {
        $this->fail($paymentRequest, $error);

        $payment = $this->getPayment($paymentRequest);
        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL);
        }
    }
}
