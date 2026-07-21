<?php

declare(strict_types=1);

namespace Meulah\Session;

interface Session
{
    public function start(): void;

    public function isStarted(): bool;

    public function id(): string;

    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function regenerate(): void;

    public function invalidate(): void;

    public function flash(string $key, mixed $value): void;

    public function keep(string ...$keys): void;

    public function reflash(): void;

    public function close(): void;
}
