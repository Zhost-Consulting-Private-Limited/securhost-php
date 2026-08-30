<?php

declare(strict_types=1);

namespace SecurHost;

class SecurHostError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $type = null,
        public readonly ?string $requestId = null,
        public readonly array $body = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public function getStatus(): ?int { return $this->status; }
    public function getErrorCode(): ?string { return $this->errorCode; }
    public function getCodeName(): ?string { return $this->errorCode; }
    public function getType(): ?string { return $this->type; }
    public function getRequestId(): ?string { return $this->requestId; }
    public function getBody(): array { return $this->body; }

    public function isRetryable(): bool
    {
        if ($this instanceof SecurHostConnectionError) return true;
        if ($this instanceof SecurHostRateLimitError) return true;
        return $this->status !== null && $this->status >= 500;
    }
}

final class SecurHostConnectionError extends SecurHostError
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, null, null, null, null, [], $previous);
    }
}

class SecurHostApiError extends SecurHostError {}

final class SecurHostAuthError extends SecurHostApiError {}

final class SecurHostRateLimitError extends SecurHostApiError
{
    public ?int $retryAfter = null;

    public function getRetryAfterSeconds(): ?int { return $this->retryAfter; }
}
