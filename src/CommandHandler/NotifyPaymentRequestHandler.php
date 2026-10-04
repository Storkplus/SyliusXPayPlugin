<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\CommandHandler;

use Storkplus\SyliusXPayPlugin\Command\NotifyPaymentRequest;
use Storkplus\SyliusXPayPlugin\Processor\SessionPaymentProcessorInterface;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;

/**
 * Applies a webhook event. The event reached the payload only after its
 * XPay-Signature was verified (see WebhookRequestListener), and its data.object is
 * the full Checkout Session, so no extra API round-trip is needed. Redeliveries are
 * harmless: the processor never re-applies or downgrades a state.
 */
final class NotifyPaymentRequestHandler
{
    use PaymentRequestStateTrait;

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly SessionPaymentProcessorInterface $sessionPaymentProcessor,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(NotifyPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        /** @var array<string, mixed> $payload */
        $payload = is_array($paymentRequest->getPayload()) ? $paymentRequest->getPayload() : [];
        $event = $payload['event'] ?? null;
        $data = is_array($event) ? ($event['data'] ?? null) : null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if (!is_array($event) || !is_array($object) || !in_array($event['type'] ?? null, XPayGateway::HANDLED_EVENTS, true)) {
            $this->fail($paymentRequest, ['message' => 'The notify payload does not carry a handled XPay Checkout Session event.']);

            return;
        }

        /** @var array<string, mixed> $session */
        $session = $object;
        $result = $this->sessionPaymentProcessor->process($this->getPayment($paymentRequest), $session, (string) $event['type']);

        $paymentRequest->setResponseData([
            'event_id' => $event['id'] ?? null,
            'event_type' => $event['type'],
            'result' => $result,
        ]);

        $this->complete($paymentRequest);
    }
}
