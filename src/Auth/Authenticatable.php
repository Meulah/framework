<?php

declare(strict_types=1);

namespace Meulah\Auth;

interface Authenticatable
{
    public function authIdentifier(): string;
}
