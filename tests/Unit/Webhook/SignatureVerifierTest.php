<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusXPayPlugin\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Storkplus\SyliusXPayPlugin\Webhook\InvalidSignatureException;
use Storkplus\SyliusXPayPlugin\Webhook\SignatureVerifier;

final class SignatureVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const NOW = 1730000000;

    private const BODY = '{"id":"evt_test_1","object":"event","type":"checkout.session.completed","data":{"object":{"id":"cs_test_1"}}}';

    public function test_it_accepts_a_valid_signature_and_returns_the_event(): void
    {
        $event = $this->verifier()->verify(self::BODY, $this->header(self::NOW), self::SECRET);

        self::assertSame('evt_test_1', $event['id']);
        self::assertSame('checkout.session.completed', $event['type']);
    }

    public function test_it_accepts_any_matching_v1_signature(): void
    {
        $header = sprintf('t=%d,v1=deadbeef,v1=%s', self::NOW, $this->sign(self::NOW, self::BODY));

        self::assertSame('evt_test_1', $this->verifier()->verify(self::BODY, $header, self::SECRET)['id']);
    }

    public function test_it_rejects_a_tampered_body(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify(str_replace('cs_test_1', 'cs_test_2', self::BODY), $this->header(self::NOW), self::SECRET);
    }

    public function test_it_rejects_a_wrong_secret(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify(self::BODY, $this->header(self::NOW), 'whsec_other');
    }

    public function test_it_rejects_a_timestamp_outside_the_replay_window(): void
    {
        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('tolerance');

        $this->verifier()->verify(self::BODY, $this->header(self::NOW - 301), self::SECRET);
    }

    public function test_it_accepts_a_timestamp_inside_the_replay_window(): void
    {
        self::assertSame('evt_test_1', $this->verifier()->verify(self::BODY, $this->header(self::NOW - 299), self::SECRET)['id']);
    }

    /** @return iterable<string, array{string|null}> */
    public static function malformedHeaders(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'no timestamp' => ['v1=abc'];
        yield 'no signature' => ['t=1730000000'];
        yield 'non numeric timestamp' => ['t=abc,v1=abc'];
    }

    /** @dataProvider malformedHeaders */
    public function test_it_rejects_malformed_headers(?string $header): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify(self::BODY, $header, self::SECRET);
    }

    public function test_it_refuses_to_verify_without_a_secret(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify(self::BODY, $this->header(self::NOW), '');
    }

    public function test_it_rejects_a_signed_body_that_is_not_an_event(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify('{"foo":1}', sprintf('t=%d,v1=%s', self::NOW, $this->sign(self::NOW, '{"foo":1}')), self::SECRET);
    }

    private function verifier(): SignatureVerifier
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('@' . self::NOW));

        return new SignatureVerifier($clock);
    }

    private function header(int $timestamp): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, $this->sign($timestamp, self::BODY));
    }

    private function sign(int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);
    }
}
