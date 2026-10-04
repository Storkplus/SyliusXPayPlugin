<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\EventListener;

use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Bundle\PaymentBundle\Checker\FinalizedPaymentRequestCheckerInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Turns Sylius payment transitions made in the admin into XPay calls:
 * refund -> POST /refunds, cancel -> expire the open Checkout Session.
 *
 * Only payments that went through XPay (a session is recorded) are touched.
 */
final class PaymentWorkflowListener
{
    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentRequestFactoryInterface $paymentRequestFactory,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentRequestAnnouncerInterface $paymentRequestAnnouncer,
        private readonly FinalizedPaymentRequestCheckerInterface $finalizedPaymentRequestChecker,
        private readonly string $action,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        $paymentMethod = $payment->getMethod();
        if (null === $paymentMethod || XPayGateway::FACTORY_NAME !== $paymentMethod->getGatewayConfig()?->getFactoryName()) {
            return;
        }

        $snapshot = $payment->getDetails()[XPayGateway::DETAILS_KEY] ?? null;
        if (!is_array($snapshot) || !isset($snapshot['session_id'])) {
            return;
        }

        $paymentRequest = $this->paymentRequestRepository->findOneByActionPaymentAndMethod($this->action, $payment, $paymentMethod);
        if (null === $paymentRequest || $this->finalizedPaymentRequestChecker->isFinal($paymentRequest)) {
            $paymentRequest = $this->paymentRequestFactory->create($payment, $paymentMethod);
            $paymentRequest->setAction($this->action);
            $this->paymentRequestRepository->add($paymentRequest);
        }

        $this->paymentRequestAnnouncer->dispatchPaymentRequestCommand($paymentRequest);

        if (PaymentRequestInterface::STATE_FAILED === $paymentRequest->getState()) {
            $this->flashFailure($paymentRequest);
        }
    }

    private function flashFailure(PaymentRequestInterface $paymentRequest): void
    {
        $session = $this->requestStack?->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $error = $paymentRequest->getResponseData()['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? null) : null;
        $session->getFlashBag()->add('error', [
            'message' => PaymentRequestInterface::ACTION_REFUND === $this->action
                ? 'storkplus_sylius_xpay.flash.refund_failed'
                : 'storkplus_sylius_xpay.flash.cancel_failed',
            'parameters' => ['%reason%' => is_string($message) ? $message : '-'],
        ]);
    }
}
