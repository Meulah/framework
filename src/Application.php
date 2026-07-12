<?php

declare(strict_types=1);

namespace Meulah;

use Meulah\Container\Container;
use Meulah\Config\Repository;
use Meulah\Exception\ExceptionHandler;
use Meulah\Http\CallableRequestHandler;
use Meulah\Http\Middleware;
use Meulah\Http\MiddlewarePipeline;
use Meulah\Http\Request;
use Meulah\Http\ResponseInterface;
use Meulah\Log\ErrorLogLogger;
use Meulah\Routing\Router;
use Throwable;

final class Application
{
    private readonly Repository $config;
    private readonly ExceptionHandler $exceptions;
    /** @var list<Middleware> */
    private array $middleware = [];

    public function __construct(
        private readonly Router $router,
        ?Repository $config = null,
        ?ExceptionHandler $exceptions = null,
    ) {
        $this->config = $config ?? new Repository();
        $this->exceptions = $exceptions ?? new ExceptionHandler(false, new ErrorLogLogger());
        $this->container()->instance(self::class, $this);
        $this->container()->instance(Repository::class, $this->config);
        $this->container()->instance(ExceptionHandler::class, $this->exceptions);
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function config(): Repository
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->router->container();
    }

    public function middleware(Middleware ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);
        return $this;
    }

    public function handle(Request $request): ResponseInterface
    {
        try {
            $destination = new CallableRequestHandler(
                fn (Request $request): ResponseInterface => $this->router->dispatch($request),
            );
            $response = (new MiddlewarePipeline($this->middleware, $destination))->handle($request);

            return $request->method() === 'HEAD' ? $response->withoutBody() : $response;
        } catch (Throwable $exception) {
            return $this->exceptions->render($exception, $request);
        }
    }

    public function renderException(Throwable $exception, ?Request $request = null): ResponseInterface
    {
        return $this->exceptions->render($exception, $request);
    }
}
