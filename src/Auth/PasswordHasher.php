<?php

declare(strict_types=1);

namespace Meulah\Auth;

interface PasswordHasher
{
    public function hash(string $plainText): string;

    public function verify(string $plainText, string $hash): bool;

    public function needsRehash(string $hash): bool;
}
