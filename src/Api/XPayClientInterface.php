<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Api;

interface XPayClientInterface
{
    /**
     * POST /checkout/sessions
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> the Checkout Session
     *
     * @throws XPayApiException
     */
    public function createCheckoutSession(string $secretKey, array $payload, string $idempotencyKey): array;

    /**
     * GET /checkout/sessions/{id}
     *
     * @return array<string, mixed>
     *
     * @throws XPayApiException
     */
    public function retrieveCheckoutSession(string $secretKey, string $sessionId): array;

    /**
     * POST /checkout/sessions/{id}/expire
     *
     * @return array<string, mixed>
     *
     * @throws XPayApiException
     */
    public function expireCheckoutSession(string $secretKey, string $sessionId): array;

    /**
     * POST /refunds
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> the Refund
     *
     * @throws XPayApiException
     */
    public function createRefund(string $secretKey, array $payload, string $idempotencyKey): array;

    /**
     * POST /webhook-endpoints. The response carries the endpoint's signing secret once.
     *
     * @param list<string> $enabledEvents
     *
     * @return array<string, mixed>
     *
     * @throws XPayApiException
     */
    public function createWebhookEndpoint(string $secretKey, string $url, array $enabledEvents, string $description): array;

    /**
     * GET /account
     *
     * @return array<string, mixed>
     *
     * @throws XPayApiException
     */
    public function retrieveAccount(string $secretKey): array;
}
