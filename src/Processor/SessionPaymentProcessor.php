<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Processor;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusXPayPlugin\Converter\AmountConverterInterface;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class SessionPaymentProcessor implements SessionPaymentProcessorInterface
{
    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly AmountConverterInterface $amountConverter,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function process(PaymentInterface $payment, array $session, ?string $eventType = null): string
    {
        $sessionId = is_string($session['id'] ?? null) ? $session['id'] : null;
        $boundSessionId = $this->getSnapshot($payment)['session_id'] ?? null;

        // A late event for a session the customer abandoned before retrying must not
        // touch the payment that now belongs to a newer session.
        if (null !== $boundSessionId && null !== $sessionId && $boundSessionId !== $sessionId) {
            $this->logger->info('Ignoring XPay session that is not bound to the payment', [
                'payment_id' => $payment->getId(),
                'bound_session' => $boundSessionId,
                'session' => $sessionId,
            ]);

            return self::RESULT_IGNORED;
        }

        $this->bind($payment, $session);

        $status = $session['status'] ?? null;
        $paymentStatus = $session['paymentStatus'] ?? null;

        if (XPayGateway::PAYMENT_STATUS_PAID === $paymentStatus || XPayGateway::PAYMENT_STATUS_NO_PAYMENT_REQUIRED === $paymentStatus) {
            if (!$this->amountMatches($payment, $session)) {
                return self::RESULT_MISMATCH;
            }

            $this->apply($payment, PaymentTransitions::TRANSITION_COMPLETE);

            return self::RESULT_COMPLETED;
        }

        if (XPayGateway::EVENT_SESSION_ASYNC_FAILED === $eventType || XPayGateway::SESSION_STATUS_EXPIRED === $status) {
            $this->apply($payment, PaymentTransitions::TRANSITION_FAIL);

            return self::RESULT_FAILED;
        }

        if (XPayGateway::SESSION_STATUS_COMPLETE === $status) {
            // Fawry and other voucher methods: the customer holds a reference and pays later.
            $this->apply($payment, PaymentTransitions::TRANSITION_PROCESS);

            return self::RESULT_PROCESSING;
        }

        if ($this->hasPendingPaymentIntent($session)) {
            // The bank took over the tab (3DS) and the outcome is not known yet; the webhook will settle it.
            $this->apply($payment, PaymentTransitions::TRANSITION_PROCESS);

            return self::RESULT_PROCESSING;
        }

        return self::RESULT_OPEN;
    }

    public function bind(PaymentInterface $payment, array $session): void
    {
        $details = $payment->getDetails();
        $previous = $this->getSnapshot($payment);
        if (isset($session['id'], $previous['session_id']) && $session['id'] !== $previous['session_id']) {
            // A new session replaces the old one: nothing of the old snapshot (payment intent, voucher) carries over.
            $previous = [];
        }

        /** @var array<string, mixed> $paymentIntent */
        $paymentIntent = is_array($session['paymentIntent'] ?? null) ? $session['paymentIntent'] : [];

        $nextAction = $paymentIntent['nextAction'] ?? null;
        $voucher = is_array($nextAction) ? ($nextAction['displayVoucherDetails'] ?? null) : null;

        $details[XPayGateway::DETAILS_KEY] = array_filter([
            'amount_total' => $session['amountTotal'] ?? $previous['amount_total'] ?? null,
            'currency' => $session['currency'] ?? $previous['currency'] ?? null,
            'livemode' => $session['livemode'] ?? $previous['livemode'] ?? null,
            'payment_intent_id' => $paymentIntent['id'] ?? $session['paymentIntentId'] ?? $previous['payment_intent_id'] ?? null,
            'payment_intent_status' => $paymentIntent['status'] ?? $previous['payment_intent_status'] ?? null,
            'payment_status' => $session['paymentStatus'] ?? $previous['payment_status'] ?? null,
            'session_id' => $session['id'] ?? $previous['session_id'] ?? null,
            'status' => $session['status'] ?? $previous['status'] ?? null,
            'url' => $session['url'] ?? $previous['url'] ?? null,
            'voucher' => is_array($voucher) ? array_filter([
                'expires_at' => $voucher['expiresAt'] ?? null,
                'reference' => $voucher['reference'] ?? null,
            ]) : ($previous['voucher'] ?? null),
            'last_error' => $this->extractLastError($paymentIntent) ?? $previous['last_error'] ?? null,
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);

        $payment->setDetails($details);
    }

    public function getSnapshot(PaymentInterface $payment): array
    {
        $snapshot = $payment->getDetails()[XPayGateway::DETAILS_KEY] ?? null;
        /** @var array<string, mixed> $snapshot */
        $snapshot = is_array($snapshot) ? $snapshot : [];

        return $snapshot;
    }

    /** @param array<string, mixed> $session */
    private function amountMatches(PaymentInterface $payment, array $session): bool
    {
        $currency = strtoupper((string) $payment->getCurrencyCode());
        $expected = $this->amountConverter->toMinorUnits((int) $payment->getAmount(), $currency);

        $amountTotal = $session['amountTotal'] ?? null;
        $sessionCurrency = is_string($session['currency'] ?? null) ? strtoupper($session['currency']) : null;

        if ((null === $amountTotal || (is_numeric($amountTotal) && (int) $amountTotal === $expected)) && (null === $sessionCurrency || $sessionCurrency === $currency)) {
            return true;
        }

        $this->logger->critical('XPay session amount does not match the Sylius payment; payment left untouched', [
            'payment_id' => $payment->getId(),
            'expected_amount' => $expected,
            'expected_currency' => $currency,
            'session_amount' => $amountTotal,
            'session_currency' => $sessionCurrency,
            'session' => $session['id'] ?? null,
        ]);

        return false;
    }

    /** @param array<string, mixed> $session */
    private function hasPendingPaymentIntent(array $session): bool
    {
        $paymentIntent = $session['paymentIntent'] ?? null;
        $status = is_array($paymentIntent) ? ($paymentIntent['status'] ?? null) : null;

        return is_string($status) && in_array(strtoupper($status), XPayGateway::PAYMENT_INTENT_PENDING_STATUSES, true);
    }

    /**
     * @param array<string, mixed> $paymentIntent
     *
     * @return array<string, string>|null
     */
    private function extractLastError(array $paymentIntent): ?array
    {
        $error = $paymentIntent['lastPaymentError'] ?? null;
        if (!is_array($error)) {
            return null;
        }

        $result = [];
        foreach (['code', 'declineCode', 'message'] as $key) {
            if (is_string($error[$key] ?? null)) {
                $result[$key] = $error[$key];
            }
        }

        return [] === $result ? null : $result;
    }

    private function apply(PaymentInterface $payment, string $transition): void
    {
        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        }
    }
}
