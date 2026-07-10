<?php

declare(strict_types=1);

namespace Meulah\Routing;

use RuntimeException;

final class MethodNotAllowed extends RuntimeException
{
    public function __construct(public readonly array $allowedMethods)
    {
        parent::__construct('Method not allowed.');
    }
}

