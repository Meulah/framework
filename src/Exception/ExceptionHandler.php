<?php

declare(strict_types=1);

namespace Meulah\Exception;

use Meulah\Http\Response;
use Meulah\Http\BadRequest;
use Meulah\Http\Request;
use Meulah\Log\Logger;
use Meulah\Routing\MethodNotAllowed;
use Meulah\Routing\RouteNotFound;
use Meulah\Security\Csrf\CsrfTokenMismatch;
use Meulah\Validation\ValidationException;
use Throwable;

final class ExceptionHandler
{
    public function __construct(
        private readonly bool $debug,
        private readonly Logger $logger,
    ) {
    }

    public function render(Throwable $exception, ?Request $request = null): Response
    {
        if ($exception instanceof CsrfTokenMismatch) {
            if ($request?->expectsJson()) {
                return Response::json(['error' => [
                    'code' => 'csrf_token_mismatch',
                    'message' => $exception->getMessage(),
                ]], 419);
            }

            return Response::html('<h1>419</h1><p>Page expired.</p>', 419);
        }

        if ($exception instanceof BadRequest) {
            if ($request?->expectsJson()) {
                $error = [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                ];

                if ($exception instanceof ValidationException) {
                    $error['fields'] = $exception->errors();
                }

                if ($this->debug && $exception->detail() !== null) {
                    $error['detail'] = $exception->detail();
                }

                return Response::json(['error' => $error], $exception->status());
            }

            $title = match ($exception->status()) {
                413 => 'Payload too large.',
                422 => 'The supplied data is invalid.',
                default => 'Bad request.',
            };
            return Response::html(
                "<h1>{$exception->status()}</h1><p>{$title}</p>",
                $exception->status(),
            );
        }

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
