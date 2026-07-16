<?php

declare(strict_types=1);

namespace Meulah\Security\Csrf;

use RuntimeException;

final class CsrfTokenMismatch extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('CSRF token mismatch.');
    }
}
