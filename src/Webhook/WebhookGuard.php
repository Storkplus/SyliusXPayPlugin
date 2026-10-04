<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Webhook;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusXPayPlugin\XPayGateway;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides what to do with an incoming XPay webhook before any payment is touched:
 *
 * - finds the XPay payment method whose whsec_ secret verifies XPay-Signature against
 *   the raw body (400 when none does, so XPay retries and forgeries never get in);
 * - acknowledges with 204 the events the plugin does not act on: other event types
 *   (charge.*, refund.*, ...) and sessions Sylius did not create (Payment Links,
 *   another shop on the same XPay account), so they cause no retries or alert emails;
 * - otherwise stores the verified event on the request for the notify flow.
 */
final class WebhookGuard
{
    /**
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly SignatureVerifierInterface $signatureVerifier,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param iterable<PaymentMethodInterface> $candidates XPay payment methods the event may belong to
     *
     * @return PaymentMethodInterface|Response the method to notify, or the response that ends the request
     */
    public function guard(Request $request, iterable $candidates): PaymentMethodInterface|Response
    {
        if (!$request->isMethod('POST')) {
            return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
        }

        $rawBody = $request->getContent();
        $header = $request->headers->get(SignatureVerifierInterface::HEADER_NAME);

        $reasons = [];
        foreach ($candidates as $paymentMethod) {
            if (XPayGateway::FACTORY_NAME !== $paymentMethod->getGatewayConfig()?->getFactoryName()) {
                continue;
            }

            $secret = $paymentMethod->getGatewayConfig()->getConfig()[XPayGateway::CONFIG_WEBHOOK_SECRET] ?? '';

            try {
                $event = $this->signatureVerifier->verify($rawBody, $header, is_string($secret) ? trim($secret) : '');
            } catch (InvalidSignatureException $exception) {
                $reasons[(string) $paymentMethod->getCode()] = $exception->getMessage();

                continue;
            }

            /** @var string $type verified by the signature verifier */
            $type = $event['type'];
            if (!in_array($type, XPayGateway::HANDLED_EVENTS, true) || !$this->isOwnSession($event, $paymentMethod)) {
                return new Response('', Response::HTTP_NO_CONTENT);
            }

            $request->attributes->set(XPayGateway::REQUEST_ATTRIBUTE_EVENT, $event);

            return $paymentMethod;
        }

        $this->logger->warning('Rejected XPay webhook', ['reasons' => [] === $reasons ? 'no XPay payment method' : $reasons]);

        return new Response('invalid signature', Response::HTTP_BAD_REQUEST);
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
}
