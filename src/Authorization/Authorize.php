<?php

declare(strict_types=1);

namespace Meulah\Authorization;

use Closure;
use Meulah\Http\Middleware;
use Meulah\Http\Request;
use Meulah\Http\RequestHandler;
use Meulah\Http\Response;
use Meulah\Http\ResponseInterface;
use Meulah\Routing\RouteParameterAwareMiddleware;
use Meulah\Routing\RouteParameters;

final class Authorize implements RouteParameterAwareMiddleware
{
    /** @var Closure(Request, RouteParameters): mixed */
    private readonly Closure $arguments;

    /** @var null|Closure(Request, AuthorizationResult): mixed */
    private readonly ?Closure $denied;

    private ?RouteParameters $routeParameters = null;

    /**
     * @param callable(Request, RouteParameters): list<mixed> $arguments
     * @param null|callable(Request, AuthorizationResult): ResponseInterface $denied
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly string $ability,
        callable $arguments,
        ?callable $denied = null,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]*$/D', $this->ability) !== 1) {
            throw new AuthorizationMiddlewareException(
                'Authorization middleware requires a valid exact ability name.',
            );
        }

        $this->arguments = Closure::fromCallable($arguments);
        $this->denied = $denied === null ? null : Closure::fromCallable($denied);
    }

    public function withRouteParameters(RouteParameters $parameters): Middleware
    {
        $middleware = clone $this;
        $middleware->routeParameters = $parameters;

        return $middleware;
    }

    public function process(Request $request, RequestHandler $next): ResponseInterface
    {
        if ($this->routeParameters === null) {
            throw new AuthorizationMiddlewareException(
                'Authorization middleware must run within a matched route.',
            );
        }

        $arguments = ($this->arguments)($request, $this->routeParameters);

        if (!is_array($arguments) || !array_is_list($arguments)) {
            throw new AuthorizationMiddlewareException(
                'The authorization argument resolver must return a list.',
            );
        }

        $result = $this->gate->inspect($this->ability, ...$arguments);

        if ($result->allowed()) {
            return $next->handle($request);
        }

        if ($this->denied !== null) {
            $response = ($this->denied)($request, $result);

            if (!$response instanceof ResponseInterface) {
                throw new AuthorizationMiddlewareException(
                    'The authorization denial callback must return ResponseInterface.',
                );
            }

            return $response;
        }

        if ($request->expectsJson()) {
            return Response::json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Forbidden.',
                ],
            ], 403);
        }

        return Response::html('Forbidden.', 403);
    }
}
