<?php

declare(strict_types=1);

namespace Meulah\Auth;

use Closure;
use Meulah\Http\Middleware;
use Meulah\Http\Request;
use Meulah\Http\RequestHandler;
use Meulah\Http\ResponseInterface;
use UnexpectedValueException;

final class RequireGuest implements Middleware
{
    /** @var Closure(Request): ResponseInterface */
    private readonly Closure $authenticated;

    /** @param callable(Request): ResponseInterface $authenticated */
    public function __construct(
        private readonly Guard $guard,
        callable $authenticated,
    ) {
        $this->authenticated = Closure::fromCallable($authenticated);
    }

    public function process(Request $request, RequestHandler $next): ResponseInterface
    {
        if (!$this->guard->check()) {
            return $next->handle($request);
        }

        $response = ($this->authenticated)($request);

        if (!$response instanceof ResponseInterface) {
            throw new UnexpectedValueException(
                'The authenticated response callback must return ResponseInterface.',
            );
        }

        return $response;
    }
}
