<?php

declare(strict_types=1);

namespace Meulah\Routing;

use Meulah\Http\Middleware;

final class Route
{
    /** @var list<Middleware> */
    private array $middleware = [];

    /** @var array<string, string> */
    private array $constraints = [];

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

    public function where(string $parameter, string $pattern): self
    {
        if (!in_array($parameter, $this->parameterNames(), true)) {
            throw new RouteDefinitionException(sprintf(
                "Route path '%s' does not contain a parameter named '%s'.",
                $this->path,
                $parameter,
            ));
        }

        RouteConstraint::validate($parameter, $pattern);

        $this->constraints[$parameter] = $pattern;
        return $this;
    }

    /** @return list<Middleware> */
    public function middlewareStack(): array
    {
        return $this->middleware;
    }

    /** @return array<string, string> */
    public function constraints(): array
    {
        return $this->constraints;
    }

    /** @return list<string> */
    private function parameterNames(): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $this->path, $matches);

        return $matches[1];
    }
}
