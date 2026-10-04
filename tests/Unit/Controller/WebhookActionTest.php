<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusXPayPlugin\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusXPayPlugin\Controller\WebhookAction;
use Storkplus\SyliusXPayPlugin\Webhook\SignatureVerifier;
use Storkplus\SyliusXPayPlugin\Webhook\WebhookGuard;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebhookActionTest extends TestCase
{
    private const HASH = '0190c3f4-7b2a-7c5e-9a1d-3f6b8e2d4c10';

    public function test_it_picks_the_xpay_method_whose_secret_verifies_the_event(): void
    {
        $paymob = $this->method('paymob', 'paymob', 'irrelevant');
        $live = $this->method('xpay_live', 'xpay', 'whsec_live');
        $test = $this->method('xpay_test', 'xpay', 'whsec_test');

        $notified = null;
        $response = $this->action([$paymob, $live, $test], $test, function (Request $request, string $code) use (&$notified): Response {
            $notified = $code;

            return new Response('', 204);
        })($this->request('whsec_test'));

        self::assertSame('xpay_test', $notified);
        self::assertSame(204, $response->getStatusCode());
    }

    public function test_it_rejects_an_event_no_method_can_verify(): void
    {
        $method = $this->method('xpay', 'xpay', 'whsec_test');

        $response = $this->action([$method], $method, static fn (): Response => throw new \LogicException('must not notify'))($this->request('whsec_other'));

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * @param list<PaymentMethod> $methods
     * @param callable(Request, string): Response $notify
     */
    private function action(array $methods, PaymentMethod $owner, callable $notify): WebhookAction
    {
        $methodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $methodRepository->method('findAll')->willReturn($methods);

        $paymentRequest = new PaymentRequest(new Payment(), $owner);
        $requestRepository = $this->createMock(PaymentRequestRepositoryInterface::class);
        $requestRepository->method('find')->willReturnCallback(static fn (string $hash) => self::HASH === $hash ? $paymentRequest : null);

        return new WebhookAction($methodRepository, new WebhookGuard($requestRepository, new SignatureVerifier()), $notify);
    }

    private function method(string $code, string $factory, string $secret): PaymentMethod
    {
        $config = new GatewayConfig();
        $config->setFactoryName($factory);
        $config->setGatewayName($code);
        $config->setConfig(['webhook_secret' => $secret]);

        $method = new PaymentMethod();
        $method->setCode($code);
        $method->setGatewayConfig($config);

        return $method;
    }

    private function request(string $secret): Request
    {
        $body = json_encode([
            'id' => 'evt_test_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_1', 'metadata' => ['sylius_payment_request_hash' => self::HASH]]],
        ], \JSON_THROW_ON_ERROR);
        $timestamp = time();

        $request = Request::create('/payment/xpay/webhook', 'POST', [], [], [], [], $body);
        $request->headers->set('XPay-Signature', sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $body, $secret)));

        return $request;
    }
}
