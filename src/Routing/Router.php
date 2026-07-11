<?php

declare(strict_types=1);

namespace Meulah\Routing;

use InvalidArgumentException;
use Meulah\Http\CallableRequestHandler;
use Meulah\Http\MiddlewarePipeline;
use Meulah\Http\Request;
use Meulah\Http\Response;
use ReflectionFunction;
use ReflectionNamedType;
use UnexpectedValueException;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

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

    public function dispatch(Request $request): Response
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
                fn (Request $request): Response => $this->toResponse(
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
        $route = new Route($methods, $path === '/' ? '/' : rtrim($path, '/'), $handler, $name);
        $this->routes[] = $route;

        return $route;
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

        return array_combine($parameterNames, array_map('rawurldecode', $matches)) ?: [];
    }

    private function resolveHandler(callable|array|string $handler): callable
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }

        if (!is_callable($handler)) {
            throw new InvalidArgumentException('The route handler is not callable.');
        }

        return $handler;
    }

    private function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
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
