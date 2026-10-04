<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusXPayPlugin\Webhook\InvalidSignatureException;
use Storkplus\SyliusXPayPlugin\Webhook\SignatureVerifierInterface;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Guards the Sylius notify endpoint (/payment-methods/{code}) for XPay methods,
 * before the Sylius controller runs:
 *
 * - verifies XPay-Signature against the raw body (400 on failure, so XPay retries
 *   and a forged call never reaches the payment);
 * - acknowledges with 204 the events this plugin does not act on: other event
 *   types (charge.*, refund.*, ...) and sessions Sylius did not create (Payment
 *   Links, another shop on the same XPay account), so they do not turn into
 *   retries and failed-delivery emails;
 * - hands the verified event to the notify payload processor via a request attribute.
 */
final class WebhookRequestListener
{
    public const NOTIFY_ROUTE = 'sylius_payment_method_notify';

    /**
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly SignatureVerifierInterface $signatureVerifier,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (self::NOTIFY_ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $code = $request->attributes->get('code');
        if (!is_string($code)) {
            return;
        }

        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $code]);
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return;
        }

        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (XPayGateway::FACTORY_NAME !== $gatewayConfig?->getFactoryName()) {
            return;
        }

        if (!$request->isMethod('POST')) {
            $event->setResponse(new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']));

            return;
        }

        $secret = $gatewayConfig->getConfig()[XPayGateway::CONFIG_WEBHOOK_SECRET] ?? '';

        try {
            $xpayEvent = $this->signatureVerifier->verify(
                $request->getContent(),
                $request->headers->get(SignatureVerifierInterface::HEADER_NAME),
                is_string($secret) ? trim($secret) : '',
            );
        } catch (InvalidSignatureException $exception) {
            $this->logger->warning('Rejected XPay webhook', [
                'payment_method' => $code,
                'reason' => $exception->getMessage(),
            ]);
            $event->setResponse(new Response('invalid signature', Response::HTTP_BAD_REQUEST));

            return;
        }

        /** @var string $type verified by the signature verifier */
        $type = $xpayEvent['type'];
        if (!in_array($type, XPayGateway::HANDLED_EVENTS, true) || !$this->isOwnSession($xpayEvent, $paymentMethod)) {
            $event->setResponse(new Response('', Response::HTTP_NO_CONTENT));

            return;
        }

        $request->attributes->set(XPayGateway::REQUEST_ATTRIBUTE_EVENT, $xpayEvent);
    }

    /** @param array<string, mixed> $xpayEvent */
    private function isOwnSession(array $xpayEvent, PaymentMethodInterface $paymentMethod): bool
    {
        $hash = self::sessionMetadata($xpayEvent)[XPayGateway::METADATA_PAYMENT_REQUEST_HASH] ?? null;
        if (!is_string($hash) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $hash)) {
            return false;
        }

        $paymentRequest = $this->paymentRequestRepository->find($hash);

        return $paymentRequest instanceof PaymentRequestInterface && $paymentRequest->getMethod() === $paymentMethod;
    }

    /**
     * @param array<array-key, mixed> $xpayEvent
     *
     * @return array<array-key, mixed>
     */
    public static function sessionMetadata(array $xpayEvent): array
    {
        $data = $xpayEvent['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;
        $metadata = is_array($object) ? ($object['metadata'] ?? null) : null;

        return is_array($metadata) ? $metadata : [];
    }
}
