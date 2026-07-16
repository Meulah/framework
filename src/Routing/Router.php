<?php

declare(strict_types=1);

namespace Meulah\Routing;

use InvalidArgumentException;
use Meulah\Container\Container;
use Meulah\Http\CallableRequestHandler;
use Meulah\Http\Middleware;
use Meulah\Http\MiddlewarePipeline;
use Meulah\Http\Request;
use Meulah\Http\Response;
use Meulah\Http\ResponseInterface;
use ReflectionFunction;
use ReflectionNamedType;
use Stringable;
use UnexpectedValueException;

final class Router
{
    private const METHOD_PATTERN = "/^[!#\\$%&'*+.^_`|~0-9A-Za-z-]+$/D";

    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $namedRoutes = [];

    /**
     * @var list<array{prefix: string, name: string, middleware: list<Middleware>}>
     */
    private array $groups = [];

    private readonly Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container();
        $this->container->instance(self::class, $this);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function get(string $path, callable|array|string $handler, ?string $name = null): Route
    {
        return $this->add(['GET', 'HEAD'], $path, $handler, $name);
    }

    public function post(string $path, callable|array|string $handler, ?string $name = null): Route
    {
        return $this->add(['POST'], $path, $handler, $name);
    }

    public function put(string $path, callable|array|string $handler, ?string $name = null): Route
    {
        return $this->add(['PUT'], $path, $handler, $name);
    }

    public function patch(string $path, callable|array|string $handler, ?string $name = null): Route
    {
        return $this->add(['PATCH'], $path, $handler, $name);
    }

    public function delete(string $path, callable|array|string $handler, ?string $name = null): Route
    {
        return $this->add(['DELETE'], $path, $handler, $name);
    }

    public function options(string $path, callable|array|string $handler, ?string $name = null): Route
    {
        return $this->add(['OPTIONS'], $path, $handler, $name);
    }

    public function match(array $methods, string $path, callable|array|string $handler, ?string $name = null): Route
    {
        return $this->add($methods, $path, $handler, $name);
    }

    /**
     * @param array{prefix?: string, name?: string, middleware?: list<Middleware>} $attributes
     */
    public function group(array $attributes, callable $routes): self
    {
        $unknown = array_diff(array_keys($attributes), ['prefix', 'name', 'middleware']);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown route group attribute%s: %s.',
                count($unknown) === 1 ? '' : 's',
                implode(', ', $unknown),
            ));
        }

        $prefix = $attributes['prefix'] ?? '';
        $name = $attributes['name'] ?? '';
        $middleware = $attributes['middleware'] ?? [];

        if (!is_string($prefix) || !is_string($name) || !is_array($middleware)) {
            throw new InvalidArgumentException(
                'Route group prefix and name must be strings, and middleware must be an array.',
            );
        }

        foreach ($middleware as $item) {
            if (!$item instanceof Middleware) {
                throw new InvalidArgumentException('Route group middleware must implement Middleware.');
            }
        }

        $parent = $this->currentGroup();
        $this->groups[] = [
            'prefix' => $this->joinPaths($parent['prefix'], $prefix),
            'name' => $parent['name'] . $name,
            'middleware' => [...$parent['middleware'], ...array_values($middleware)],
        ];

        try {
            $routes($this);
        } finally {
            array_pop($this->groups);
        }

        return $this;
    }

    public function url(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->namedRoutes[$name] ?? null;

        if ($route === null) {
            throw new UrlGenerationException(sprintf("Named route '%s' does not exist.", $name));
        }

        $expected = $this->pathParameterNames($route->path);
        $provided = array_keys($parameters);
        $missing = array_values(array_diff($expected, $provided));
        $extra = array_values(array_diff($provided, $expected));

        if ($missing !== []) {
            throw new UrlGenerationException(sprintf(
                "Cannot generate route '%s'; missing path parameters: %s.",
                $name,
                implode(', ', $missing),
            ));
        }

        if ($extra !== []) {
            throw new UrlGenerationException(sprintf(
                "Cannot generate route '%s'; unknown path parameters: %s.",
                $name,
                implode(', ', $extra),
            ));
        }

        $path = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            function (array $match) use ($name, $parameters, $route): string {
                $value = $this->stringifyParameter(
                    $name,
                    $match[1],
                    $parameters[$match[1]],
                );
                $constraint = $route->constraints()[$match[1]] ?? null;

                if (
                    $constraint !== null
                    && preg_match('#^(?:' . str_replace('#', '\\#', $constraint) . ')$#', $value) !== 1
                ) {
                    throw new UrlGenerationException(sprintf(
                        "Path parameter '%s' for route '%s' does not satisfy its constraint.",
                        $match[1],
                        $name,
                    ));
                }

                return rawurlencode($value);
            },
            $route->path,
        );

        if ($path === null) {
            throw new UrlGenerationException(sprintf("Could not generate route '%s'.", $name));
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $queryString === '' ? $path : $path . '?' . $queryString;
    }

    public function dispatch(Request $request): ResponseInterface
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            $parameters = $this->matchPath($route, $request->path());

            if ($parameters === null) {
                continue;
            }

            if (!in_array($request->method(), $route->methods, true)) {
                $allowed = array_merge($allowed, $route->methods);
                continue;
            }

            $handler = $this->resolveHandler($route->handler);
            $destination = new CallableRequestHandler(
                fn (Request $request): ResponseInterface => $this->toResponse(
                    $this->invokeHandler($handler, $request, array_values($parameters)),
                ),
            );
            $response = (new MiddlewarePipeline($route->middlewareStack(), $destination))->handle($request);

            return $request->method() === 'HEAD' ? $response->withoutBody() : $response;
        }

        if ($allowed !== []) {
            throw new MethodNotAllowed(array_values(array_unique($allowed)));
        }

        throw new RouteNotFound(sprintf('No route matches %s.', $request->path()));
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    private function add(array $methods, string $path, callable|array|string $handler, ?string $name): Route
    {
        $normalizedMethods = [];

        foreach ($methods as $method) {
            if (!is_string($method) || preg_match(self::METHOD_PATTERN, $method) !== 1) {
                throw new RouteDefinitionException('Route methods must be non-empty valid HTTP tokens.');
            }

            $normalizedMethods[] = strtoupper($method);
        }

        $methods = array_values(array_unique($normalizedMethods));

        if ($methods === []) {
            throw new RouteDefinitionException('A route needs at least one HTTP method.');
        }

        $group = $this->currentGroup();
        $path = $this->joinPaths($group['prefix'], $path);

        if (
            preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || str_contains($path, '?')
            || str_contains($path, '#')
        ) {
            throw new RouteDefinitionException('Route paths cannot contain controls, queries, or fragments.');
        }
        $name = $name === null ? null : trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A named route needs a non-empty name.');
        }

        if ($name !== null) {
            $name = $group['name'] . $name;
        }

        if ($name !== null && isset($this->namedRoutes[$name])) {
            throw new InvalidArgumentException(sprintf("Route name '%s' is already registered.", $name));
        }

        $parameterNames = $this->pathParameterNames($path);

        if (count($parameterNames) !== count(array_unique($parameterNames))) {
            throw new InvalidArgumentException('Route path parameter names must be unique.');
        }

        $route = new Route($methods, $path === '/' ? '/' : rtrim($path, '/'), $handler, $name);

        if ($group['middleware'] !== []) {
            $route->middleware(...$group['middleware']);
        }

        $this->routes[] = $route;

        if ($name !== null) {
            $this->namedRoutes[$name] = $route;
        }

        return $route;
    }

    /** @return list<string> */
    private function pathParameterNames(string $path): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);

        return $matches[1];
    }

    private function stringifyParameter(string $route, string $parameter, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_string($value) && !is_int($value) && !is_float($value) && !$value instanceof Stringable) {
            throw new UrlGenerationException(sprintf(
                "Path parameter '%s' for route '%s' must be scalar or stringable.",
                $parameter,
                $route,
            ));
        }

        $value = (string) $value;

        if ($value === '') {
            throw new UrlGenerationException(sprintf(
                "Path parameter '%s' for route '%s' cannot be empty.",
                $parameter,
                $route,
            ));
        }

        if (str_contains($value, '/') || str_contains($value, '\\')) {
            throw new UrlGenerationException(sprintf(
                "Path parameter '%s' for route '%s' cannot contain a slash.",
                $parameter,
                $route,
            ));
        }

        return $value;
    }

    private function matchPath(Route $route, string $requestPath): ?array
    {
        $parameterNames = [];
        $constraints = $route->constraints();
        $quoted = preg_quote($route->path, '#');
        $pattern = preg_replace_callback('/\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}/', function (array $match) use (&$parameterNames, $constraints): string {
            $parameterNames[] = $match[1];
            $constraint = $constraints[$match[1]] ?? '[^/]+';

            return '(?P<' . $match[1] . '>' . str_replace('#', '\\#', $constraint) . ')';
        }, $quoted);

        if ($pattern === null || preg_match('#^' . $pattern . '$#', $requestPath, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($parameterNames as $parameter) {
            $parameters[$parameter] = $matches[$parameter];
        }

        return $parameters;
    }

    /** @return array{prefix: string, name: string, middleware: list<Middleware>} */
    private function currentGroup(): array
    {
        return $this->groups[array_key_last($this->groups)] ?? [
            'prefix' => '',
            'name' => '',
            'middleware' => [],
        ];
    }

    private function joinPaths(string $prefix, string $path): string
    {
        $joined = trim($prefix, '/') . '/' . trim($path, '/');
        $joined = '/' . trim($joined, '/');

        return $joined === '/' ? '/' : rtrim($joined, '/');
    }

    private function resolveHandler(callable|array|string $handler): callable
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
            $handler = [$this->container->get($handler[0]), $handler[1]];
        } elseif (is_string($handler) && class_exists($handler)) {
            $handler = $this->container->get($handler);
        }

        if (!is_callable($handler)) {
            throw new InvalidArgumentException('The route handler is not callable.');
        }

        return $handler;
    }

    private function toResponse(mixed $result): ResponseInterface
    {
        return match (true) {
            $result instanceof ResponseInterface => $result,
            is_string($result) => Response::html($result),
            $result === null => new Response(),
            default => throw new UnexpectedValueException(
                'Route handlers must return a Response, string, or null.',
            ),
        };
    }

    private function invokeHandler(callable $handler, Request $request, array $parameters): mixed
    {
        $reflection = new ReflectionFunction(\Closure::fromCallable($handler));
        $firstParameter = $reflection->getParameters()[0] ?? null;
        $type = $firstParameter?->getType();
        $acceptsRequest = $type instanceof ReflectionNamedType
            && !$type->isBuiltin()
            && $type->getName() === Request::class;

        $arguments = $acceptsRequest ? [$request, ...$parameters] : $parameters;
        $maximum = $reflection->getNumberOfParameters();
        $provided = count($arguments);

        if ($provided < $reflection->getNumberOfRequiredParameters()) {
            throw new RouteHandlerException(sprintf(
                'Route handler requires %d arguments; %d provided.',
                $reflection->getNumberOfRequiredParameters(),
                $provided,
            ));
        }

        if (!$reflection->isVariadic() && $provided > $maximum) {
            throw new RouteHandlerException(sprintf(
                'Route handler accepts at most %d arguments; %d provided.',
                $maximum,
                $provided,
            ));
        }

        return $handler(...$arguments);
    }
}
