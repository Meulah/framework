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

        if ($pattern === '') {
            throw new RouteDefinitionException('A route parameter constraint cannot be empty.');
        }

        if (preg_match("/\\(\\?(?:P?<|<[A-Za-z_]|'[A-Za-z_])/", $pattern) === 1) {
            throw new RouteDefinitionException(sprintf(
                "The constraint for route parameter '%s' cannot contain a named capture.",
                $parameter,
            ));
        }

        $escapedPattern = str_replace('#', '\\#', $pattern);

        if (@preg_match('#^(?:' . $escapedPattern . ')$#', '') === false) {
            throw new RouteDefinitionException(sprintf(
                "The constraint for route parameter '%s' is not a valid regular expression.",
                $parameter,
            ));
        }

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
