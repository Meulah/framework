<?php

declare(strict_types=1);

namespace Meulah;

use Meulah\Http\Request;
use Meulah\Http\Response;
use Meulah\Routing\MethodNotAllowed;
use Meulah\Routing\RouteNotFound;
use Meulah\Routing\Router;
use Throwable;

final class Application
{
    public function __construct(
        private readonly Router $router,
        private readonly bool $debug = false,
    ) {
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        try {
            $result = $this->router->dispatch($request);

            return match (true) {
                $result instanceof Response => $result,
                is_string($result) => Response::html($result),
                $result === null => new Response(),
                default => Response::html('Route handlers must return a Response, string, or null.', 500),
            };
        } catch (MethodNotAllowed $exception) {
            return new Response('Method Not Allowed', 405, [
                'Allow' => implode(', ', $exception->allowedMethods),
            ]);
        } catch (RouteNotFound) {
            return Response::html('<h1>404</h1><p>Page not found.</p>', 404);
        } catch (Throwable $exception) {
            if ($this->debug) {
                $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return Response::html("<h1>Application error</h1><p>{$message}</p>", 500);
            }

            error_log($exception->__toString());
            return Response::html('<h1>500</h1><p>Something went wrong.</p>', 500);
        }
    }
}

