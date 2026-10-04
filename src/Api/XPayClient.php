<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Api;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class XPayClient implements XPayClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl = 'https://api.xpay.app',
        private readonly float $timeout = 20.0,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function createCheckoutSession(string $secretKey, array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', '/checkout/sessions', $secretKey, $payload, $idempotencyKey);
    }

    public function retrieveCheckoutSession(string $secretKey, string $sessionId): array
    {
        return $this->request('GET', sprintf('/checkout/sessions/%s', rawurlencode($sessionId)), $secretKey);
    }

    public function expireCheckoutSession(string $secretKey, string $sessionId): array
    {
        return $this->request('POST', sprintf('/checkout/sessions/%s/expire', rawurlencode($sessionId)), $secretKey, []);
    }

    public function createRefund(string $secretKey, array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', '/refunds', $secretKey, $payload, $idempotencyKey);
    }

    public function createWebhookEndpoint(string $secretKey, string $url, array $enabledEvents, string $description): array
    {
        return $this->request('POST', '/webhook-endpoints', $secretKey, [
            'description' => $description,
            'enabledEvents' => $enabledEvents,
            'url' => $url,
        ]);
    }

    public function retrieveAccount(string $secretKey): array
    {
        return $this->request('GET', '/account', $secretKey);
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        string $secretKey,
        ?array $json = null,
        ?string $idempotencyKey = null,
    ): array {
        if ('' === trim($secretKey)) {
            throw new XPayApiException('The XPay secret key is not configured on this payment method.');
        }

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $secretKey,
        ];
        if (null !== $idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $options = [
            'headers' => $headers,
            'timeout' => $this->timeout,
        ];
        if (null !== $json) {
            // An empty array must still be sent as a JSON object.
            $options['body'] = [] === $json ? '{}' : json_encode($json, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $options['headers']['Content-Type'] = 'application/json';
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/') . $path, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('XPay API transport error', [
                'method' => $method,
                'path' => $path,
                'exception' => $exception,
            ]);

            throw new XPayApiException(sprintf('Could not reach XPay: %s', $exception->getMessage()), 0, 'api_error', 'request_timeout', null, $exception);
        }

        try {
            /** @var mixed $body */
            $body = '' === $content ? [] : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new XPayApiException(sprintf('XPay returned a non-JSON response (HTTP %d).', $statusCode), $statusCode, 'api_error', null, null, $exception);
        }

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        if ($statusCode >= 400) {
            $exception = XPayApiException::fromResponse($statusCode, $body);
            $this->logger->warning('XPay API error', [
                'method' => $method,
                'path' => $path,
                'error' => $exception->toArray(),
            ]);

            throw $exception;
        }

        return $body;
    }
}
