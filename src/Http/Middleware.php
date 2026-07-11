<?php

declare(strict_types=1);

namespace Meulah\Http;

interface Middleware
{
    public function process(Request $request, RequestHandler $next): ResponseInterface;
}
