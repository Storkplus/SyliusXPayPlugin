<?php

declare(strict_types=1);

namespace Storkplus\SyliusXPayPlugin\Api;

/**
 * A failed XPay API call. Carries the documented error envelope
 * ({ error: { type, code, message, param, doc_url } }) when XPay sent one.
 */
final class XPayApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly ?string $errorType = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $param = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(int $statusCode, array $body): self
    {
        /** @var array<string, mixed> $error */
        $error = is_array($body['error'] ?? null) ? $body['error'] : $body;

        $message = is_string($error['message'] ?? null) ? $error['message'] : sprintf('XPay API responded with HTTP %d.', $statusCode);

        return new self(
            $message,
            $statusCode,
            is_string($error['type'] ?? null) ? $error['type'] : null,
            is_string($error['code'] ?? null) ? $error['code'] : null,
            is_string($error['param'] ?? null) ? $error['param'] : null,
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getParam(): ?string
    {
        return $this->param;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'message' => $this->getMessage(),
            'status' => $this->statusCode,
            'type' => $this->errorType,
            'code' => $this->errorCode,
            'param' => $this->param,
        ], static fn (mixed $value): bool => null !== $value && 0 !== $value);
    }
}
