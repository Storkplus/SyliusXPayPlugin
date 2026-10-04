<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusXPayPlugin\Api\XPayApiException;
use Storkplus\SyliusXPayPlugin\Api\XPayClientInterface;
use Storkplus\SyliusXPayPlugin\Command\RefundPaymentRequest;
use Storkplus\SyliusXPayPlugin\Converter\AmountConverterInterface;
use Storkplus\SyliusXPayPlugin\Processor\SessionPaymentProcessorInterface;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Sent when an admin refunds an XPay payment in Sylius. Refunds the whole payment
 * against its Payment Intent; the payment request hash is the Idempotency-Key, so a
 * retried request can never refund twice.
 */
final class RefundPaymentRequestHandler
{
    use PaymentRequestStateTrait;

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly XPayClientInterface $client,
        private readonly SessionPaymentProcessorInterface $sessionPaymentProcessor,
        private readonly AmountConverterInterface $amountConverter,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(RefundPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = $this->getPayment($paymentRequest);
        $snapshot = $this->sessionPaymentProcessor->getSnapshot($payment);

        $paymentIntentId = $snapshot['payment_intent_id'] ?? null;
        if (!is_string($paymentIntentId) || '' === $paymentIntentId) {
            $this->fail($paymentRequest, ['message' => 'No XPay Payment Intent is recorded on this payment; refund it from the XPay dashboard.']);

            return;
        }

        $order = $payment->getOrder();

        try {
            $refund = $this->client->createRefund($this->getSecretKey($paymentRequest), [
                'amount' => $this->amountConverter->toMinorUnits((int) $payment->getAmount(), (string) $payment->getCurrencyCode()),
                'metadata' => [
                    XPayGateway::METADATA_ORDER_NUMBER => (string) $order?->getNumber(),
                    XPayGateway::METADATA_PAYMENT_ID => (string) $payment->getId(),
                    XPayGateway::METADATA_PAYMENT_REQUEST_HASH => (string) $paymentRequest->getId(),
                ],
                'paymentIntentId' => $paymentIntentId,
                'reason' => 'REQUESTED_BY_CUSTOMER',
            ], (string) $paymentRequest->getId());
        } catch (XPayApiException $exception) {
            $this->logger->error('XPay refund failed', [
                'order' => $order?->getNumber(),
                'payment_intent' => $paymentIntentId,
                'error' => $exception->toArray(),
            ]);
            $this->fail($paymentRequest, $exception->toArray());

            return;
        }

        $details = $payment->getDetails();
        /** @var array<string, mixed> $xpay */
        $xpay = is_array($details[XPayGateway::DETAILS_KEY] ?? null) ? $details[XPayGateway::DETAILS_KEY] : [];
        $xpay['refunds'] = array_values(array_merge(is_array($xpay['refunds'] ?? null) ? $xpay['refunds'] : [], [array_filter([
            'amount' => $refund['amount'] ?? null,
            'id' => $refund['id'] ?? null,
            'status' => $refund['status'] ?? null,
        ])]));
        $details[XPayGateway::DETAILS_KEY] = $xpay;
        $payment->setDetails($details);

        $paymentRequest->setResponseData(['refund' => $refund]);

        if ('failed' === ($refund['status'] ?? null)) {
            $this->logger->error('XPay rejected the refund', ['order' => $order?->getNumber(), 'refund' => $refund['id'] ?? null]);
            $this->fail($paymentRequest, ['message' => 'XPay rejected the refund.', 'refund' => $refund['id'] ?? null]);

            return;
        }

        $this->complete($paymentRequest);
    }
}
