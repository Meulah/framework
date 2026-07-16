<?php

declare(strict_types=1);

namespace Meulah\Http;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Cookie
{
    private function __construct(
        private readonly string $name,
        private readonly string $value,
        private readonly ?DateTimeImmutable $expires,
        private readonly string $path,
        private readonly bool $secure,
        private readonly bool $httpOnly,
        private readonly SameSite $sameSite,
    ) {
    }

    public static function make(
        string $name,
        string $value,
        ?DateTimeImmutable $expires = null,
        string $path = '/',
        bool $secure = true,
        bool $httpOnly = true,
        SameSite|string $sameSite = SameSite::Lax,
    ): self {
        if ($name === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) !== 1) {
            throw new InvalidArgumentException('Invalid cookie name.');
        }

        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException('Cookie values cannot contain CR or LF characters.');
        }

        if ($path === '' || preg_match('/[\x00-\x1F\x7F;]/', $path) === 1) {
            throw new InvalidArgumentException('Invalid cookie path.');
        }

        if (is_string($sameSite)) {
            $sameSite = SameSite::tryFrom($sameSite)
                ?? throw new InvalidArgumentException("Unsupported SameSite value: {$sameSite}");
        }

        if ($sameSite === SameSite::None && !$secure) {
            throw new InvalidArgumentException('SameSite=None cookies must be Secure.');
        }

        if ($expires !== null) {
            $year = (int) $expires->format('Y');

            if ($year < 1601 || $year > 9999) {
                throw new InvalidArgumentException('Cookie expiration must be between years 1601 and 9999.');
            }
        }

        return new self($name, $value, $expires, $path, $secure, $httpOnly, $sameSite);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function expires(): ?DateTimeImmutable
    {
        return $this->expires;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    public function sameSite(): SameSite
    {
        return $this->sameSite;
    }

    public function toHeader(): string
    {
        $parts = [$this->name . '=' . rawurlencode($this->value)];

        if ($this->expires !== null) {
            $expires = $this->expires->setTimezone(new DateTimeZone('GMT'));
            $parts[] = 'Expires=' . $expires->format('D, d M Y H:i:s') . ' GMT';
        }

        $parts[] = 'Path=' . $this->path;

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite->value;

        return implode('; ', $parts);
    }
}
