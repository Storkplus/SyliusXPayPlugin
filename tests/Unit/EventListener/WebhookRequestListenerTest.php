<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusXPayPlugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusXPayPlugin\EventListener\WebhookRequestListener;
use Storkplus\SyliusXPayPlugin\Webhook\SignatureVerifier;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class WebhookRequestListenerTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const HASH = '0190c3f4-7b2a-7c5e-9a1d-3f6b8e2d4c10';

    private PaymentMethod $method;

    private PaymentRequest $paymentRequest;

    protected function setUp(): void
    {
        $config = new GatewayConfig();
        $config->setFactoryName('xpay');
        $config->setGatewayName('xpay');
        $config->setConfig(['webhook_secret' => self::SECRET]);

        $this->method = new PaymentMethod();
        $this->method->setCode('xpay');
        $this->method->setGatewayConfig($config);

        $this->paymentRequest = new PaymentRequest(new Payment(), $this->method);
    }

    public function test_a_valid_event_is_handed_to_the_notify_controller(): void
    {
        $event = $this->dispatch($this->body('checkout.session.completed', self::HASH));

        self::assertNull($event->getResponse());
        self::assertSame('checkout.session.completed', $event->getRequest()->attributes->get('_xpay_event')['type']);
    }

    public function test_a_bad_signature_is_rejected_with_400(): void
    {
        $event = $this->dispatch($this->body('checkout.session.completed', self::HASH), 't=' . time() . ',v1=00');

        self::assertSame(400, $event->getResponse()?->getStatusCode());
        self::assertFalse($event->getRequest()->attributes->has('_xpay_event'));
    }

    public function test_unhandled_event_types_are_acknowledged(): void
    {
        self::assertSame(204, $this->dispatch($this->body('charge.succeeded', self::HASH))->getResponse()?->getStatusCode());
    }

    public function test_sessions_not_created_by_sylius_are_acknowledged(): void
    {
        self::assertSame(204, $this->dispatch($this->body('checkout.session.completed', null))->getResponse()?->getStatusCode());
        self::assertSame(204, $this->dispatch($this->body('checkout.session.completed', '0190c3f4-7b2a-7c5e-9a1d-000000000000'))->getResponse()?->getStatusCode());
    }

    public function test_get_requests_are_refused(): void
    {
        self::assertSame(405, $this->dispatch('', null, 'GET')->getResponse()?->getStatusCode());
    }

    public function test_other_gateways_are_left_alone(): void
    {
        $this->method->getGatewayConfig()?->setFactoryName('paymob');

        $event = $this->dispatch('{"type":"TRANSACTION"}', 'garbage');

        self::assertNull($event->getResponse());
    }

    private function body(string $type, ?string $hash): string
    {
        return json_encode([
            'id' => 'evt_test_1',
            'type' => $type,
            'data' => ['object' => ['id' => 'cs_test_1', 'metadata' => null === $hash ? [] : ['sylius_payment_request_hash' => $hash]]],
        ], \JSON_THROW_ON_ERROR);
    }

    private function dispatch(string $body, ?string $signature = null, string $method = 'POST'): RequestEvent
    {
        $timestamp = time();
        $request = Request::create('/payment-methods/xpay', $method, [], [], [], [], $body);
        $request->attributes->set('_route', 'sylius_payment_method_notify');
        $request->attributes->set('code', 'xpay');
        $request->headers->set('XPay-Signature', $signature ?? sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET)));

        $methods = $this->createMock(PaymentMethodRepositoryInterface::class);
        $methods->method('findOneBy')->willReturn($this->method);

        $requests = $this->createMock(PaymentRequestRepositoryInterface::class);
        $requests->method('find')->willReturnCallback(fn (string $hash) => self::HASH === $hash ? $this->paymentRequest : null);

        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new WebhookRequestListener($methods, $requests, new SignatureVerifier()))($event);

        return $event;
    }
}
