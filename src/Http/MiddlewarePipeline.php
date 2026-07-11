<?php

declare(strict_types=1);

namespace Meulah\Http;

final class MiddlewarePipeline implements RequestHandler
{
    /** @param list<Middleware> $middleware */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandler $destination,
    ) {
    }

    public function handle(Request $request): Response
    {
        $handler = $this->destination;

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = $handler;
            $handler = new CallableRequestHandler(
                static fn (Request $request): Response => $middleware->process($request, $next),
            );
        }

        return $handler->handle($request);
    }
}

