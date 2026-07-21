<?php

declare(strict_types=1);

namespace Meulah\Http;

use InvalidArgumentException;
use JsonException;

final class Response implements ResponseInterface
{
    /**
     * @param array<string, string> $headers
     * @param list<Cookie> $cookies
     */
    public function __construct(
        private readonly string $content = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly array $cookies = [],
    ) {
        if ($this->status < 100 || $this->status > 599) {
            throw new InvalidArgumentException("Invalid HTTP status code: {$this->status}");
        }

        foreach ($this->headers as $name => $value) {
            if (!is_string($name) || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) !== 1) {
                throw new InvalidArgumentException('Invalid HTTP header name.');
            }

            if (!is_string($value) || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new InvalidArgumentException("Invalid value for HTTP header: {$name}");
            }
        }

        foreach ($this->cookies as $cookie) {
            if (!$cookie instanceof Cookie) {
                throw new InvalidArgumentException('Response cookies must be Cookie instances.');
            }
        }
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** @throws JsonException */
    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    /** @return list<Cookie> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function withoutBody(): self
    {
        return new self('', $this->status, $this->headers, $this->cookies);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;

        foreach (array_keys($headers) as $existingName) {
            if (strcasecmp($existingName, $name) === 0) {
                unset($headers[$existingName]);
            }
        }

        $headers[$name] = $value;

        return new self($this->content, $this->status, $headers, $this->cookies);
    }

    public function withCookie(Cookie $cookie): self
    {
        return new self(
            $this->content,
            $this->status,
            $this->headers,
            [...$this->cookies, $cookie],
        );
    }

    public function send(): void
    {
        if (headers_sent($file, $line)) {
            throw new ResponseException("Cannot send a response after output was sent at {$file}:{$line}.");
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, strcasecmp($name, 'Set-Cookie') !== 0);
        }

        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie->toHeader(), false);
        }

        echo $this->content;
    }
}
