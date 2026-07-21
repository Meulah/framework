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
        private readonly ?string $domain,
        private readonly ?int $maxAge,
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
        ?string $domain = null,
        ?int $maxAge = null,
    ): self {
        if ($name === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) !== 1) {
            throw new InvalidArgumentException('Invalid cookie name.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Cookie values cannot contain control characters.');
        }

        if ($path === '' || !str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7F-\xFF;]/', $path) === 1) {
            throw new InvalidArgumentException('Invalid cookie path.');
        }

        if ($domain !== null) {
            self::assertDomain($domain);
        }

        if ($maxAge !== null && $maxAge < 0) {
            throw new InvalidArgumentException('Cookie Max-Age cannot be negative.');
        }

        if (is_string($sameSite)) {
            $sameSite = SameSite::tryFrom($sameSite)
                ?? throw new InvalidArgumentException("Unsupported SameSite value: {$sameSite}");
        }

        if ($sameSite === SameSite::None && !$secure) {
            throw new InvalidArgumentException('SameSite=None cookies must be Secure.');
        }

        if (str_starts_with($name, '__Secure-') && !$secure) {
            throw new InvalidArgumentException('__Secure- cookies must be Secure.');
        }

        if (str_starts_with($name, '__Host-') && (!$secure || $path !== '/' || $domain !== null)) {
            throw new InvalidArgumentException('__Host- cookies must be Secure, use Path=/, and omit Domain.');
        }

        if ($expires !== null) {
            $year = (int) $expires->format('Y');

            if ($year < 1601 || $year > 9999) {
                throw new InvalidArgumentException('Cookie expiration must be between years 1601 and 9999.');
            }
        }

        return new self(
            $name,
            $value,
            $expires,
            $path,
            $secure,
            $httpOnly,
            $sameSite,
            $domain,
            $maxAge,
        );
    }

    public static function forget(
        string $name,
        string $path = '/',
        bool $secure = true,
        bool $httpOnly = true,
        SameSite|string $sameSite = SameSite::Lax,
        ?string $domain = null,
    ): self {
        return self::make(
            name: $name,
            value: '',
            expires: new DateTimeImmutable('@0'),
            path: $path,
            secure: $secure,
            httpOnly: $httpOnly,
            sameSite: $sameSite,
            domain: $domain,
            maxAge: 0,
        );
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

    public function domain(): ?string
    {
        return $this->domain;
    }

    public function maxAge(): ?int
    {
        return $this->maxAge;
    }

    public function toHeader(): string
    {
        $parts = [$this->name . '=' . rawurlencode($this->value)];

        if ($this->expires !== null) {
            $expires = $this->expires->setTimezone(new DateTimeZone('GMT'));
            $parts[] = 'Expires=' . $expires->format('D, d M Y H:i:s') . ' GMT';
        }

        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . $this->maxAge;
        }

        $parts[] = 'Path=' . $this->path;

        if ($this->domain !== null) {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite->value;

        return implode('; ', $parts);
    }

    private static function assertDomain(string $domain): void
    {
        if (
            $domain === ''
            || strlen($domain) > 253
            || preg_match('/[\x00-\x20\x7F;,\x80-\xFF]/', $domain) === 1
        ) {
            throw new InvalidArgumentException('Invalid cookie domain.');
        }

        foreach (explode('.', $domain) as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $label) !== 1
            ) {
                throw new InvalidArgumentException('Invalid cookie domain.');
            }
        }
    }
}
