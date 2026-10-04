<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\CommandHandler;

use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Webmozart\Assert\Assert;

/**
 * @property StateMachineInterface $stateMachine
 */
trait PaymentRequestStateTrait
{
    private function getSecretKey(PaymentRequestInterface $paymentRequest): string
    {
        $config = $paymentRequest->getMethod()->getGatewayConfig()?->getConfig() ?? [];
        $secretKey = $config[XPayGateway::CONFIG_SECRET_KEY] ?? '';

        return is_string($secretKey) ? trim($secretKey) : '';
    }

    private function getPayment(PaymentRequestInterface $paymentRequest): PaymentInterface
    {
        $payment = $paymentRequest->getPayment();
        Assert::isInstanceOf($payment, PaymentInterface::class);

        return $payment;
    }

    private function process(PaymentRequestInterface $paymentRequest): void
    {
        $this->transition($paymentRequest, PaymentRequestTransitions::TRANSITION_PROCESS);
    }

    private function complete(PaymentRequestInterface $paymentRequest): void
    {
        $this->transition($paymentRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    /** @param array<string, mixed> $responseData */
    private function fail(PaymentRequestInterface $paymentRequest, array $responseData): void
    {
        $paymentRequest->setResponseData(array_merge($paymentRequest->getResponseData(), ['error' => $responseData]));

        $this->transition($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    private function transition(PaymentRequestInterface $paymentRequest, string $transition): void
    {
        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, $transition);
        }
    }
}
