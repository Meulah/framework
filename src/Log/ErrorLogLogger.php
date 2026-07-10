<?php

declare(strict_types=1);

namespace Meulah\Log;

use Throwable;

final class ErrorLogLogger implements Logger
{
    public function error(Throwable $exception): void
    {
        error_log($exception->__toString());
    }
}

