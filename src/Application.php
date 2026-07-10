<?php

declare(strict_types=1);

namespace Meulah;

use Meulah\Config\Repository;
use Meulah\Exception\ExceptionHandler;
use Meulah\Http\Request;
use Meulah\Http\Response;
use Meulah\Log\ErrorLogLogger;
use Meulah\Routing\Router;
use UnexpectedValueException;
use Throwable;

final class Application
{
    private readonly Repository $config;
    private readonly ExceptionHandler $exceptions;

    public function __construct(
        private readonly Router $router,
        ?Repository $config = null,
        ?ExceptionHandler $exceptions = null,
    ) {
        $this->config = $config ?? new Repository();
        $this->exceptions = $exceptions ?? new ExceptionHandler(false, new ErrorLogLogger());
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function config(): Repository
    {
        return $this->config;
    }

    public function handle(Request $request): Response
    {
        try {
            $result = $this->router->dispatch($request);

            return match (true) {
                $result instanceof Response => $result,
                is_string($result) => Response::html($result),
                $result === null => new Response(),
                default => throw new UnexpectedValueException(
                    'Route handlers must return a Response, string, or null.',
                ),
            };
        } catch (Throwable $exception) {
            return $this->exceptions->render($exception);
        }
    }
}
