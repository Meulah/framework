<?php

declare(strict_types=1);

namespace Meulah\Log;

use Throwable;

interface Logger
{
    public function error(Throwable $exception): void;
}

