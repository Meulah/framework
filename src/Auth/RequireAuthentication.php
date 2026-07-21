<?php

declare(strict_types=1);

namespace Meulah\Auth;

use Closure;
use Meulah\Http\Middleware;
use Meulah\Http\Request;
use Meulah\Http\RequestHandler;
use Meulah\Http\ResponseInterface;
use UnexpectedValueException;

final class RequireAuthentication implements Middleware
{
    /** @var Closure(Request): ResponseInterface */
    private readonly Closure $unauthenticated;

    /** @param callable(Request): ResponseInterface $unauthenticated */
    public function __construct(
        private readonly Guard $guard,
        callable $unauthenticated,
    ) {
        $this->unauthenticated = Closure::fromCallable($unauthenticated);
    }

    public function process(Request $request, RequestHandler $next): ResponseInterface
    {
        if ($this->guard->check()) {
            return $next->handle($request);
        }

        $response = ($this->unauthenticated)($request);

        if (!$response instanceof ResponseInterface) {
            throw new UnexpectedValueException(
                'The unauthenticated response callback must return ResponseInterface.',
            );
        }

        return $response;
    }
}
