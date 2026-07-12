<?php

declare(strict_types=1);

namespace Meulah\Routing;

use InvalidArgumentException;
use Meulah\Container\Container;
use Meulah\Http\CallableRequestHandler;
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
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $namedRoutes = [];

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

    public function match(array $methods, string $path, callable|array|string $handler, ?string $name = null): Route
    {
        return $this->add($methods, $path, $handler, $name);
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
            fn (array $match): string => rawurlencode($this->stringifyParameter(
                $name,
                $match[1],
                $parameters[$match[1]],
            )),
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
            $parameters = $this->matchPath($route->path, $request->path());

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
        $methods = array_values(array_unique(array_map('strtoupper', $methods)));

        if ($methods === []) {
            throw new InvalidArgumentException('A route needs at least one HTTP method.');
        }

        $path = '/' . trim($path, '/');
        $name = $name === null ? null : trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A named route needs a non-empty name.');
        }

        if ($name !== null && isset($this->namedRoutes[$name])) {
            throw new InvalidArgumentException(sprintf("Route name '%s' is already registered.", $name));
        }

        $parameterNames = $this->pathParameterNames($path);

        if (count($parameterNames) !== count(array_unique($parameterNames))) {
            throw new InvalidArgumentException('Route path parameter names must be unique.');
        }

        $route = new Route($methods, $path === '/' ? '/' : rtrim($path, '/'), $handler, $name);
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

    private function matchPath(string $routePath, string $requestPath): ?array
    {
        $parameterNames = [];
        $quoted = preg_quote($routePath, '#');
        $pattern = preg_replace_callback('/\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}/', function (array $match) use (&$parameterNames): string {
            $parameterNames[] = $match[1];
            return '([^/]+)';
        }, $quoted);

        if ($pattern === null || preg_match('#^' . $pattern . '$#', $requestPath, $matches) !== 1) {
            return null;
        }

        array_shift($matches);

        return array_combine($parameterNames, $matches) ?: [];
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
