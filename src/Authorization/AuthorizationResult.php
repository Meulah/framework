<?php

declare(strict_types=1);

namespace Meulah\Authorization;

final class AuthorizationResult
{
    private function __construct(
        private readonly bool $allowed,
        private readonly ?string $message,
        private readonly ?string $code,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null, null);
    }

    public static function deny(?string $message = null, ?string $code = null): self
    {
        return new self(false, $message, $code);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function denied(): bool
    {
        return !$this->allowed;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function code(): ?string
    {
        return $this->code;
    }
}
