<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Webhook;

use Psr\Clock\ClockInterface;

/**
 * XPay signs "{t}.{rawBody}" with HMAC-SHA256 using the endpoint's whsec_ secret
 * and rejects deliveries older than five minutes on its side; we apply the same
 * replay window here.
 */
final class SignatureVerifier implements SignatureVerifierInterface
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly ?ClockInterface $clock = null,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ) {
    }

    public function verify(string $rawBody, ?string $signatureHeader, string $secret): array
    {
        if ('' === $secret) {
            throw new InvalidSignatureException('No webhook signing secret is configured for this payment method.');
        }

        if (null === $signatureHeader || '' === trim($signatureHeader)) {
            throw new InvalidSignatureException(sprintf('Missing %s header.', self::HEADER_NAME));
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (2 !== count($pair)) {
                continue;
            }

            [$key, $value] = $pair;
            if ('t' === $key) {
                $timestamp = $value;
            } elseif ('v1' === $key && '' !== $value) {
                $signatures[] = $value;
            }
        }

        if (null === $timestamp || !ctype_digit($timestamp) || [] === $signatures) {
            throw new InvalidSignatureException(sprintf('Malformed %s header.', self::HEADER_NAME));
        }

        $now = null !== $this->clock ? $this->clock->now()->getTimestamp() : time();
        if (abs($now - (int) $timestamp) > $this->toleranceSeconds) {
            throw new InvalidSignatureException('Webhook timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        $matched = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                $matched = true;

                break;
            }
        }

        if (!$matched) {
            throw new InvalidSignatureException('Webhook signature does not match.');
        }

        try {
            $event = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidSignatureException('Webhook body is not valid JSON.', 0, $exception);
        }

        if (!is_array($event) || !is_string($event['type'] ?? null)) {
            throw new InvalidSignatureException('Webhook body is not an XPay event.');
        }

        /** @var array<string, mixed> $verified */
        $verified = $event;

        return $verified;
    }
}
