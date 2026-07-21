<?php

declare(strict_types=1);

namespace Meulah\Auth;

interface UserProvider
{
    public function retrieveById(string $identifier): ?Authenticatable;
}
