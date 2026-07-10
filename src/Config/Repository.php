<?php

declare(strict_types=1);

namespace Meulah\Config;

use InvalidArgumentException;
use RuntimeException;

final class Repository
{
    public function __construct(private readonly array $items = [])
    {
    }

    public static function load(string $directory): self
    {
        if (!is_dir($directory)) {
            throw new RuntimeException("Configuration directory not found: {$directory}");
        }

        $items = [];

        foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $value = require $file;

            if (!is_array($value)) {
                throw new RuntimeException("Configuration files must return arrays: {$file}");
            }

            $items[pathinfo($file, PATHINFO_FILENAME)] = $value;
        }

        return new self($items);
    }

    public function has(string $key): bool
    {
        return $this->find($key)[0];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->find($key);

        return $found ? $value : $default;
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->get($key, $default);

        if (!is_string($value)) {
            throw $this->typeError($key, 'string', $value);
        }

        return $value;
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->get($key, $default);

        if (!is_int($value)) {
            throw $this->typeError($key, 'integer', $value);
        }

        return $value;
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->get($key, $default);

        if (!is_bool($value)) {
            throw $this->typeError($key, 'boolean', $value);
        }

        return $value;
    }

    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        if (!is_array($value)) {
            throw $this->typeError($key, 'array', $value);
        }

        return $value;
    }

    private function find(string $key): array
    {
        if ($key === '') {
            return [true, $this->items];
        }

        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }

    private function typeError(string $key, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Configuration value "%s" must be %s; %s given.',
            $key,
            $expected,
            get_debug_type($value),
        ));
    }
}

