<?php

declare(strict_types=1);

namespace Meulah\Routing;

final class Route
{
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly ?string $name = null,
    ) {
    }
}

