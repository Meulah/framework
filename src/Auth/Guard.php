<?php

declare(strict_types=1);

namespace Meulah\Auth;

interface Guard
{
    public function user(): ?Authenticatable;

    public function id(): ?string;

    public function check(): bool;

    public function guest(): bool;

    public function login(Authenticatable $user): void;

    public function logout(): void;
}
