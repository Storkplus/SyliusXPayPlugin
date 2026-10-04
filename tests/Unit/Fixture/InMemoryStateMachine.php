<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusXPayPlugin\Unit\Fixture;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/** The sylius_payment and sylius_payment_request graphs, without the Workflow component. */
final class InMemoryStateMachine implements StateMachineInterface
{
    private const GRAPHS = [
        PaymentTransitions::GRAPH => [
            PaymentTransitions::TRANSITION_PROCESS => [[PaymentInterface::STATE_NEW], PaymentInterface::STATE_PROCESSING],
            PaymentTransitions::TRANSITION_COMPLETE => [[PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING, PaymentInterface::STATE_AUTHORIZED], PaymentInterface::STATE_COMPLETED],
            PaymentTransitions::TRANSITION_FAIL => [[PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], PaymentInterface::STATE_FAILED],
            PaymentTransitions::TRANSITION_CANCEL => [[PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING, PaymentInterface::STATE_AUTHORIZED], PaymentInterface::STATE_CANCELLED],
            PaymentTransitions::TRANSITION_REFUND => [[PaymentInterface::STATE_COMPLETED], PaymentInterface::STATE_REFUNDED],
        ],
        PaymentRequestTransitions::GRAPH => [
            PaymentRequestTransitions::TRANSITION_PROCESS => [[PaymentRequestInterface::STATE_NEW], PaymentRequestInterface::STATE_PROCESSING],
            PaymentRequestTransitions::TRANSITION_COMPLETE => [[PaymentRequestInterface::STATE_NEW, PaymentRequestInterface::STATE_PROCESSING], PaymentRequestInterface::STATE_COMPLETED],
            PaymentRequestTransitions::TRANSITION_FAIL => [[PaymentRequestInterface::STATE_NEW, PaymentRequestInterface::STATE_PROCESSING], PaymentRequestInterface::STATE_FAILED],
            PaymentRequestTransitions::TRANSITION_CANCEL => [[PaymentRequestInterface::STATE_NEW, PaymentRequestInterface::STATE_PROCESSING], PaymentRequestInterface::STATE_CANCELLED],
        ],
    ];

    /** @var list<string> */
    public array $applied = [];

    public function can(object $subject, string $graphName, string $transition): bool
    {
        $definition = self::GRAPHS[$graphName][$transition] ?? null;

        return null !== $definition && in_array($this->state($subject), $definition[0], true);
    }

    public function apply(object $subject, string $graphName, string $transition, array $context = []): void
    {
        if (!$this->can($subject, $graphName, $transition)) {
            throw new \LogicException(sprintf('Cannot apply "%s" from "%s".', $transition, $this->state($subject)));
        }

        \assert($subject instanceof PaymentInterface || $subject instanceof PaymentRequestInterface);
        $subject->setState(self::GRAPHS[$graphName][$transition][1]);
        $this->applied[] = $graphName . ':' . $transition;
    }

    public function getEnabledTransitions(object $subject, string $graphName): array
    {
        return [];
    }

    public function getTransitionFromState(object $subject, string $graphName, string $fromState): ?string
    {
        return null;
    }

    public function getTransitionToState(object $subject, string $graphName, string $toState): ?string
    {
        return null;
    }

    private function state(object $subject): string
    {
        \assert($subject instanceof PaymentInterface || $subject instanceof PaymentRequestInterface);

        return (string) $subject->getState();
    }
}
