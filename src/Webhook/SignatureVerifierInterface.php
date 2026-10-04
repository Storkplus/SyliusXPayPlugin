<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Webhook;

interface SignatureVerifierInterface
{
    public const HEADER_NAME = 'XPay-Signature';

    /**
     * Verifies the XPay-Signature header (t=<unix>,v1=<hex hmac>) against the raw body
     * and returns the decoded event.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidSignatureException
     */
    public function verify(string $rawBody, ?string $signatureHeader, string $secret): array;
}
