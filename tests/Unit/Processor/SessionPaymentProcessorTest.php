<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusXPayPlugin\Unit\Processor;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusXPayPlugin\Converter\AmountConverter;
use Storkplus\SyliusXPayPlugin\Processor\SessionPaymentProcessor;
use Storkplus\SyliusXPayPlugin\Processor\SessionPaymentProcessorInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Tests\Storkplus\SyliusXPayPlugin\Unit\Fixture\InMemoryStateMachine;

final class SessionPaymentProcessorTest extends TestCase
{
    private InMemoryStateMachine $stateMachine;

    private SessionPaymentProcessor $processor;

    protected function setUp(): void
    {
        $this->stateMachine = new InMemoryStateMachine();
        $this->processor = new SessionPaymentProcessor($this->stateMachine, new AmountConverter());
    }

    public function test_a_paid_session_completes_the_payment_and_records_the_ids(): void
    {
        $payment = $this->payment();

        $result = $this->processor->process($payment, $this->session([
            'paymentStatus' => 'paid',
            'status' => 'complete',
            'paymentIntent' => ['id' => 'pi_test_1', 'status' => 'SUCCEEDED'],
        ]));

        self::assertSame(SessionPaymentProcessorInterface::RESULT_COMPLETED, $result);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame('cs_test_1', $payment->getDetails()['xpay']['session_id']);
        self::assertSame('pi_test_1', $payment->getDetails()['xpay']['payment_intent_id']);
    }

    public function test_a_paid_session_with_another_amount_leaves_the_payment_untouched(): void
    {
        $payment = $this->payment();

        $result = $this->processor->process($payment, $this->session(['paymentStatus' => 'paid', 'status' => 'complete', 'amountTotal' => 100]));

        self::assertSame(SessionPaymentProcessorInterface::RESULT_MISMATCH, $result);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    public function test_a_paid_session_in_another_currency_leaves_the_payment_untouched(): void
    {
        $payment = $this->payment();

        $result = $this->processor->process($payment, $this->session(['paymentStatus' => 'paid', 'status' => 'complete', 'currency' => 'USD']));

        self::assertSame(SessionPaymentProcessorInterface::RESULT_MISMATCH, $result);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    public function test_a_completed_unpaid_fawry_session_puts_the_payment_in_processing_with_its_reference(): void
    {
        $payment = $this->payment();

        $result = $this->processor->process($payment, $this->session([
            'paymentStatus' => 'unpaid',
            'status' => 'complete',
            'paymentIntent' => [
                'id' => 'pi_test_1',
                'status' => 'REQUIRES_ACTION',
                'nextAction' => ['displayVoucherDetails' => ['reference' => '9876543', 'expiresAt' => '2026-10-05T10:00:00Z']],
            ],
        ]), 'checkout.session.completed');

        self::assertSame(SessionPaymentProcessorInterface::RESULT_PROCESSING, $result);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertSame('9876543', $payment->getDetails()['xpay']['voucher']['reference']);

        // ...and the async success later completes it.
        $this->processor->process($payment, $this->session(['paymentStatus' => 'paid', 'status' => 'complete']), 'checkout.session.async_payment_succeeded');
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    public function test_an_async_payment_failure_fails_the_payment(): void
    {
        $payment = $this->payment(PaymentInterface::STATE_PROCESSING);

        $result = $this->processor->process($payment, $this->session(['paymentStatus' => 'unpaid', 'status' => 'complete']), 'checkout.session.async_payment_failed');

        self::assertSame(SessionPaymentProcessorInterface::RESULT_FAILED, $result);
        self::assertSame(PaymentInterface::STATE_FAILED, $payment->getState());
    }

    public function test_an_expired_session_fails_the_payment(): void
    {
        $payment = $this->payment();

        self::assertSame(SessionPaymentProcessorInterface::RESULT_FAILED, $this->processor->process($payment, $this->session(['status' => 'expired'])));
        self::assertSame(PaymentInterface::STATE_FAILED, $payment->getState());
    }

    public function test_an_open_session_with_a_payment_in_flight_puts_the_payment_in_processing(): void
    {
        $payment = $this->payment();

        $result = $this->processor->process($payment, $this->session(['paymentIntent' => ['id' => 'pi_test_1', 'status' => 'PROCESSING']]));

        self::assertSame(SessionPaymentProcessorInterface::RESULT_PROCESSING, $result);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
    }

    public function test_an_open_session_without_attempt_is_reported_open(): void
    {
        $payment = $this->payment();

        self::assertSame(SessionPaymentProcessorInterface::RESULT_OPEN, $this->processor->process($payment, $this->session()));
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame([], $this->stateMachine->applied);
    }

    public function test_a_session_not_bound_to_the_payment_is_ignored(): void
    {
        $payment = $this->payment();
        $this->processor->bind($payment, $this->session(['id' => 'cs_test_new']));

        $result = $this->processor->process($payment, $this->session(['id' => 'cs_test_old', 'status' => 'expired']));

        self::assertSame(SessionPaymentProcessorInterface::RESULT_IGNORED, $result);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame('cs_test_new', $payment->getDetails()['xpay']['session_id']);
    }

    public function test_redelivered_and_late_events_never_downgrade_a_completed_payment(): void
    {
        $payment = $this->payment();
        $this->processor->process($payment, $this->session(['paymentStatus' => 'paid', 'status' => 'complete']));

        $this->processor->process($payment, $this->session(['paymentStatus' => 'paid', 'status' => 'complete']));
        $this->processor->process($payment, $this->session(['status' => 'expired']), 'checkout.session.expired');

        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(['sylius_payment:complete'], $this->stateMachine->applied);
    }

    public function test_binding_a_new_session_drops_the_previous_payment_intent(): void
    {
        $payment = $this->payment();
        $this->processor->bind($payment, $this->session(['id' => 'cs_test_old', 'paymentIntent' => ['id' => 'pi_test_old', 'status' => 'FAILED']]));

        $this->processor->bind($payment, $this->session(['id' => 'cs_test_new']));

        self::assertSame('cs_test_new', $payment->getDetails()['xpay']['session_id']);
        self::assertArrayNotHasKey('payment_intent_id', $payment->getDetails()['xpay']);
    }

    public function test_it_locks_the_order_and_rereads_the_payment_before_deciding(): void
    {
        $payment = $this->payment();
        $order = new \Sylius\Component\Core\Model\Order();
        $order->addPayment($payment);
        (new \ReflectionProperty(Payment::class, 'id'))->setValue($payment, 7);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('contains')->willReturn(true);
        $entityManager->expects(self::exactly(2))->method('refresh')->willReturnCallback(
            static function (object $entity, $lockMode = null) use ($order, $payment): void {
                if ($entity === $order) {
                    self::assertSame(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE, $lockMode);

                    return;
                }
                // The webhook completed it meanwhile.
                self::assertSame($payment, $entity);
                $payment->setState(PaymentInterface::STATE_COMPLETED);
            },
        );

        $processor = new SessionPaymentProcessor($this->stateMachine, new AmountConverter(), entityManager: $entityManager);
        $result = $processor->process($payment, $this->session(['paymentStatus' => 'paid', 'status' => 'complete']));

        self::assertSame(SessionPaymentProcessorInterface::RESULT_COMPLETED, $result);
        self::assertSame([], $this->stateMachine->applied, 'the paid transition must not run twice');
    }

    public function test_kwd_amounts_are_compared_in_three_decimal_minor_units(): void
    {
        $payment = $this->payment(currency: 'KWD', amount: 1500);

        $result = $this->processor->process($payment, $this->session(['paymentStatus' => 'paid', 'status' => 'complete', 'amountTotal' => 15000, 'currency' => 'KWD']));

        self::assertSame(SessionPaymentProcessorInterface::RESULT_COMPLETED, $result);
    }

    private function payment(string $state = PaymentInterface::STATE_NEW, string $currency = 'EGP', int $amount = 149900): Payment
    {
        $payment = new Payment();
        $payment->setState($state);
        $payment->setCurrencyCode($currency);
        $payment->setAmount($amount);

        return $payment;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function session(array $overrides = []): array
    {
        return array_merge([
            'amountTotal' => 149900,
            'currency' => 'EGP',
            'id' => 'cs_test_1',
            'paymentIntent' => null,
            'paymentStatus' => 'unpaid',
            'status' => 'open',
            'url' => 'https://checkout.xpay.app/c/cs_test_1',
        ], $overrides);
    }
}
