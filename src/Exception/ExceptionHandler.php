<?php

declare(strict_types=1);

namespace Meulah\Exception;

use Meulah\Http\Response;
use Meulah\Log\Logger;
use Meulah\Routing\MethodNotAllowed;
use Meulah\Routing\RouteNotFound;
use Throwable;

final class ExceptionHandler
{
    public function __construct(
        private readonly bool $debug,
        private readonly Logger $logger,
    ) {
    }

    public function render(Throwable $exception): Response
    {
        if ($exception instanceof MethodNotAllowed) {
            return new Response('Method Not Allowed', 405, [
                'Allow' => implode(', ', $exception->allowedMethods),
            ]);
        }

        if ($exception instanceof RouteNotFound) {
            return Response::html('<h1>404</h1><p>Page not found.</p>', 404);
        }

        $this->logger->error($exception);

        return $this->debug
            ? $this->debugResponse($exception)
            : Response::html('<h1>500</h1><p>Something went wrong.</p>', 500);
    }

    private function debugResponse(Throwable $exception): Response
    {
        $escape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        $class = $escape($exception::class);
        $message = $escape($exception->getMessage());
        $file = $escape($exception->getFile());
        $line = $exception->getLine();

        return Response::html(
            "<h1>Application error</h1><p><strong>{$class}</strong>: {$message}</p>" .
            "<p>{$file}:{$line}</p>",
            500,
        );
    }
}

