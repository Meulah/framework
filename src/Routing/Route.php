<?php

declare(strict_types=1);

namespace Meulah\Routing;

use Meulah\Http\Middleware;

final class Route
{
    /** @var list<Middleware> */
    private array $middleware = [];

    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly ?string $name = null,
    ) {
    }

    public function middleware(Middleware ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);
        return $this;
    }

    /** @return list<Middleware> */
    public function middlewareStack(): array
    {
        return $this->middleware;
    }
}
