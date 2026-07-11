<?php

declare(strict_types=1);

namespace Meulah\Http;

use Closure;

final class CallableRequestHandler implements RequestHandler
{
    private readonly Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = Closure::fromCallable($handler);
    }

    public function handle(Request $request): ResponseInterface
    {
        return ($this->handler)($request);
    }
}
