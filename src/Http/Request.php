<?php

declare(strict_types=1);

namespace Meulah\Http;

final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $server = [],
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (isset($_GET['url'])) {
            $path = '/' . trim((string) $_GET['url'], '/');
        } else {
            $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
            $basePath = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));

            if ($basePath !== '/' && $basePath !== '.' && str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath)) ?: '/';
            }
        }

        return new self($method, self::normalizePath($path), $_GET, $_POST, $_SERVER);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $input = array_replace($this->query, $this->body);

        return $key === null ? $input : ($input[$key] ?? $default);
    }

    public function server(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->server : ($this->server[$key] ?? $default);
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . trim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
