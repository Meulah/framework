<?php

declare(strict_types=1);

namespace Meulah\Http;

use JsonException;
use stdClass;

final class Request
{
    private bool $jsonDecoded = false;
    private mixed $decodedJson = null;
    private readonly string $method;
    private readonly string $path;

    public function __construct(
        string $method,
        string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $server = [],
        array $headers = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly string $rawBody = '',
        int $maxBodySize = 10_485_760,
    ) {
        if ($maxBodySize < 1) {
            throw new \InvalidArgumentException('Maximum request body size must be positive.');
        }

        if (strlen($this->rawBody) > $maxBodySize) {
            throw new BadRequest('Request body is too large.', 'payload_too_large', status: 413);
        }

        $this->method = strtoupper($method);
        $this->path = self::normalizePath($path);
        $this->headers = self::normalizeHeaders($headers);
    }

    /** @var array<string, string> */
    private readonly array $headers;

    public static function capture(int $maxBodySize = 10_485_760): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $query = $_GET;

        if (isset($_GET['url'])) {
            $path = '/' . trim((string) $_GET['url'], '/');
            unset($query['url']);
        } else {
            $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
            $basePath = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));

            if (
                $basePath !== '/'
                && $basePath !== '.'
                && ($path === $basePath || str_starts_with($path, $basePath . '/'))
            ) {
                $path = substr($path, strlen($basePath)) ?: '/';
            }
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength > $maxBodySize) {
            throw new BadRequest('Request body is too large.', 'payload_too_large', status: 413);
        }

        $rawBody = file_get_contents('php://input', false, null, 0, $maxBodySize + 1);

        return new self(
            $method,
            $path,
            $query,
            $_POST,
            $_SERVER,
            self::headersFromServer($_SERVER),
            $_COOKIE,
            self::normalizeFiles($_FILES),
            $rawBody === false ? '' : $rawBody,
            $maxBodySize,
        );
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
        if ($this->hasJsonContentType()) {
            $json = $this->jsonValue();
            $bodyInput = $json instanceof stdClass ? get_object_vars($json) : [];
        } else {
            $bodyInput = $this->body;
        }
        $input = array_replace($this->query, is_array($bodyInput) ? $bodyInput : []);

        return $key === null ? $input : ($input[$key] ?? $default);
    }

    public function allInput(): array
    {
        return $this->input();
    }

    public function hasInput(string $key): bool
    {
        return array_key_exists($key, $this->allInput());
    }

    public function filled(string $key): bool
    {
        if (!$this->hasInput($key)) {
            return false;
        }

        $value = $this->input($key);
        return $value !== null && $value !== '' && $value !== [];
    }

    public function form(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->body : ($this->body[$key] ?? $default);
    }

    public function server(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->server : ($this->server[$key] ?? $default);
    }

    public function header(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->headers;
        }

        return $this->headers[strtolower($name)] ?? $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    public function cookie(?string $name = null, mixed $default = null): mixed
    {
        return $name === null ? $this->cookies : ($this->cookies[$name] ?? $default);
    }

    public function file(?string $name = null, mixed $default = null): mixed
    {
        return $name === null ? $this->files : $this->valueByPath($this->files, $name, $default);
    }

    public function files(): array
    {
        return $this->files;
    }

    public function hasFile(string $name): bool
    {
        return $this->containsValidFile($this->file($name));
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        $decoded = $this->jsonValue();

        if ($key === null) {
            return $decoded;
        }

        if (!$decoded instanceof stdClass) {
            return $default;
        }

        $value = $decoded;

        foreach (explode('.', $key) as $segment) {
            if (!$value instanceof stdClass || !property_exists($value, $segment)) {
                return $default;
            }

            $value = $value->{$segment};
        }

        return $value;
    }

    public function jsonValue(): mixed
    {
        return $this->decodeJson();
    }

    public function jsonObject(): object
    {
        $value = $this->jsonValue();

        if (!$value instanceof stdClass) {
            throw new BadRequest(
                'Expected a JSON object, received ' . $this->jsonType($value) . '.',
                'invalid_json_shape',
            );
        }

        return $value;
    }

    public function jsonArray(): array
    {
        $value = $this->jsonValue();

        if (!is_array($value)) {
            throw new BadRequest(
                'Expected a JSON array, received ' . $this->jsonType($value) . '.',
                'invalid_json_shape',
            );
        }

        return $value;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        if (!$this->hasInput($key)) {
            return func_num_args() >= 2 ? $default : throw new InvalidInput("Input '{$key}' is required.");
        }

        $value = $this->input($key);

        if (!is_string($value)) {
            throw new InvalidInput("Input '{$key}' must be a string.");
        }

        return $value;
    }

    public function integer(string $key, ?int $default = null): ?int
    {
        if (!$this->hasInput($key)) {
            return func_num_args() >= 2 ? $default : throw new InvalidInput("Input '{$key}' is required.");
        }

        $value = $this->input($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer !== false) {
                return $integer;
            }
        }

        throw new InvalidInput("Input '{$key}' must be an integer.");
    }

    public function boolean(string $key, ?bool $default = null): ?bool
    {
        if (!$this->hasInput($key)) {
            return func_num_args() >= 2 ? $default : throw new InvalidInput("Input '{$key}' is required.");
        }

        return match ($this->input($key)) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => throw new InvalidInput("Input '{$key}' must be a boolean."),
        };
    }

    public function array(string $key, ?array $default = null): ?array
    {
        if (!$this->hasInput($key)) {
            return func_num_args() >= 2 ? $default : throw new InvalidInput("Input '{$key}' is required.");
        }

        $value = $this->input($key);

        if (!is_array($value)) {
            throw new InvalidInput("Input '{$key}' must be an array.");
        }

        return $value;
    }

    private function hasJsonContentType(): bool
    {
        $contentType = strtolower(trim(explode(';', (string) $this->header('content-type', ''))[0]));

        return $contentType === 'application/json' || str_ends_with($contentType, '+json');
    }

    private function decodeJson(): mixed
    {
        if ($this->jsonDecoded) {
            return $this->decodedJson;
        }

        if (trim($this->rawBody) === '') {
            $this->decodedJson = new stdClass();
        } else {
            try {
                $this->decodedJson = json_decode($this->rawBody, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new BadRequest(
                    'The request body contains malformed JSON.',
                    'invalid_json',
                    $exception->getMessage(),
                    previous: $exception,
                );
            }
        }

        $this->jsonDecoded = true;
        return $this->decodedJson;
    }

    private function jsonType(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'an array',
            is_string($value) => 'a string',
            is_int($value), is_float($value) => 'a number',
            is_bool($value) => 'a boolean',
            $value === null => 'null',
            default => 'an object',
        };
    }

    private function valueByPath(array $values, string $path, mixed $default): mixed
    {
        $value = $values;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function containsValidFile(mixed $value): bool
    {
        if ($value instanceof UploadedFile) {
            return $value->isValid();
        }

        if (is_array($value)) {
            foreach ($value as $file) {
                if ($this->containsValidFile($file)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function expectsJson(): bool
    {
        if ($this->hasJsonContentType()) {
            return true;
        }

        $accept = strtolower((string) $this->header('accept', ''));
        return str_contains($accept, 'application/json')
            || preg_match('#application/[a-z0-9.+-]+\+json#', $accept) === 1;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . trim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $normalized[strtolower($name)] = (string) $value;
            }
        }

        return $normalized;
    }

    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $header = str_replace('_', '-', substr($name, 5));
                $headers[$header] = $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $header) {
            if (isset($server[$key])) {
                $headers[$header] = $server[$key];
            }
        }

        if (!isset($headers['Authorization'])) {
            $authorization = $server['AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

            if ($authorization !== null) {
                $headers['Authorization'] = $authorization;
            }
        }

        return $headers;
    }

    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $name => $file) {
            if (is_array($file) && isset($file['name'], $file['tmp_name'], $file['error'], $file['size'])) {
                $normalized[$name] = self::normalizeFile($file);
            }
        }

        return $normalized;
    }

    private static function normalizeFile(array $file): UploadedFile|array
    {
        if (is_array($file['name'])) {
            $normalized = [];

            foreach (array_keys($file['name']) as $key) {
                $normalized[$key] = self::normalizeFile([
                    'name' => $file['name'][$key],
                    'type' => $file['type'][$key] ?? '',
                    'tmp_name' => $file['tmp_name'][$key],
                    'error' => $file['error'][$key],
                    'size' => $file['size'][$key],
                ]);
            }

            return $normalized;
        }

        return UploadedFile::fromPhpUpload(
            (string) $file['name'],
            (string) ($file['type'] ?? ''),
            (string) $file['tmp_name'],
            (int) $file['error'],
            (int) $file['size'],
        );
    }
}
