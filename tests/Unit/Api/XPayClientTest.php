<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusXPayPlugin\Unit\Api;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusXPayPlugin\Api\XPayApiException;
use Storkplus\SyliusXPayPlugin\Api\XPayClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class XPayClientTest extends TestCase
{
    public function test_it_creates_a_checkout_session_with_bearer_auth_and_idempotency_key(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => $options['body']];

            return new MockResponse('{"id":"cs_test_1","url":"https://checkout.xpay.app/c/cs_test_1","status":"open"}', ['http_code' => 200]);
        });

        $session = (new XPayClient($http))->createCheckoutSession('sk_test_123', ['currency' => 'EGP'], 'hash-1');

        self::assertSame('cs_test_1', $session['id']);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.xpay.app/checkout/sessions', $captured['url']);
        self::assertContains('Authorization: Bearer sk_test_123', $captured['headers']);
        self::assertContains('Idempotency-Key: hash-1', $captured['headers']);
        self::assertSame('{"currency":"EGP"}', $captured['body']);
    }

    public function test_expire_sends_an_empty_json_object(): void
    {
        $body = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = $options['body'];
            self::assertSame('https://api.xpay.app/checkout/sessions/cs_test_1/expire', $url);

            return new MockResponse('{"id":"cs_test_1","status":"expired"}');
        });

        (new XPayClient($http))->expireCheckoutSession('sk_test_123', 'cs_test_1');

        self::assertSame('{}', $body);
    }

    public function test_it_maps_the_error_envelope(): void
    {
        $http = new MockHttpClient(new MockResponse(
            '{"error":{"type":"invalid_request_error","code":"parameter_invalid","message":"Bad currency","param":"currency"}}',
            ['http_code' => 400],
        ));

        try {
            (new XPayClient($http))->retrieveCheckoutSession('sk_test_123', 'cs_test_1');
            self::fail('Expected an exception');
        } catch (XPayApiException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertSame('invalid_request_error', $exception->getErrorType());
            self::assertSame('parameter_invalid', $exception->getErrorCode());
            self::assertSame('currency', $exception->getParam());
            self::assertSame('Bad currency', $exception->getMessage());
        }
    }

    public function test_it_wraps_transport_errors(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('timeout');
        });

        $this->expectException(XPayApiException::class);
        (new XPayClient($http))->retrieveAccount('sk_test_123');
    }

    public function test_it_refuses_to_call_without_a_secret_key(): void
    {
        $this->expectException(XPayApiException::class);
        (new XPayClient(new MockHttpClient()))->retrieveAccount(' ');
    }
}
