<?php

declare(strict_types=1);

namespace Meulah\Authorization;

interface Gate
{
    public function define(string $ability, callable|string $callback): void;

    public function allows(string $ability, mixed ...$arguments): bool;

    public function denies(string $ability, mixed ...$arguments): bool;

    public function inspect(string $ability, mixed ...$arguments): AuthorizationResult;

    /** @throws AuthorizationException */
    public function authorize(string $ability, mixed ...$arguments): void;
}
