<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Processor;

use Sylius\Component\Core\Model\PaymentInterface;

interface SessionPaymentProcessorInterface
{
    public const RESULT_COMPLETED = 'completed';

    public const RESULT_PROCESSING = 'processing';

    public const RESULT_FAILED = 'failed';

    public const RESULT_OPEN = 'open';

    public const RESULT_IGNORED = 'ignored';

    public const RESULT_MISMATCH = 'mismatch';

    /**
     * Records the Checkout Session on the payment and moves the payment along the
     * sylius_payment graph. Safe to call repeatedly with the same or older data:
     * it never downgrades a payment and ignores sessions that are not the one the
     * payment is currently bound to.
     *
     * @param array<string, mixed> $session
     *
     * @return self::RESULT_*
     */
    public function process(PaymentInterface $payment, array $session, ?string $eventType = null): string;

    /**
     * Writes the session snapshot into the payment details without changing state.
     *
     * @param array<string, mixed> $session
     */
    public function bind(PaymentInterface $payment, array $session): void;

    /** @return array<string, mixed> */
    public function getSnapshot(PaymentInterface $payment): array;
}
