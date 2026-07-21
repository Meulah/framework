<?php

declare(strict_types=1);

namespace Meulah\Session;

use Meulah\Http\Middleware;
use Meulah\Http\Request;
use Meulah\Http\RequestHandler;
use Meulah\Http\ResponseInterface;

final class SessionMiddleware implements Middleware
{
    public function __construct(private readonly Session $session)
    {
    }

    public function process(Request $request, RequestHandler $next): ResponseInterface
    {
        try {
            return $next->handle($request);
        } finally {
            $this->session->close();
        }
    }
}
