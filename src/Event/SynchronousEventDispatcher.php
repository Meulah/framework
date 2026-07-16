<?php

declare(strict_types=1);

namespace Meulah\Event;

use Closure;
use InvalidArgumentException;
use Meulah\Container\Container;
use ReflectionClass;
use ReflectionFunction;

final class SynchronousEventDispatcher implements EventDispatcher
{
    /** @var array<class-string, list<callable|string>> */
    private array $listeners = [];

    private readonly Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container();
    }

    public function listen(string $event, callable|string $listener): void
    {
        if ($event === '' || !class_exists($event)) {
            throw new InvalidArgumentException("Event '{$event}' must be an existing class.");
        }

        $event = (new ReflectionClass($event))->getName();

        if (is_string($listener)) {
            $listener = trim($listener);

            if ($listener === '') {
                throw new InvalidArgumentException('An event listener class or callable cannot be empty.');
            }

            if (class_exists($listener)) {
                $reflection = new ReflectionClass($listener);

                if (
                    !$reflection->isInstantiable()
                    || !$reflection->hasMethod('__invoke')
                    || !$reflection->getMethod('__invoke')->isPublic()
                ) {
                    throw new InvalidArgumentException(sprintf(
                        "Event listener class '%s' must be instantiable and invokable.",
                        $listener,
                    ));
                }

                $listener = $reflection->getName();
            } elseif (!is_callable($listener)) {
                throw new InvalidArgumentException(sprintf(
                    "Event listener '%s' is not a callable or resolvable class.",
                    $listener,
                ));
            }
        }
        $this->assertListenerSignature($listener);

        $this->listeners[$event][] = $listener;
    }

    public function dispatch(object $event): object
    {
        $listeners = $this->listeners[$event::class] ?? [];

        foreach ($listeners as $listener) {
            $this->resolve($listener)($event);
        }

        return $event;
    }

    private function assertListenerSignature(callable|string $listener): void
    {
        $reflection = is_string($listener) && class_exists($listener)
            ? (new ReflectionClass($listener))->getMethod('__invoke')
            : new ReflectionFunction(Closure::fromCallable($listener));

        if ($reflection->getNumberOfRequiredParameters() > 1) {
            throw new InvalidArgumentException(sprintf(
                "Event listener '%s' may require at most one argument.",
                is_string($listener) ? $listener : $reflection->getName(),
            ));
        }
    }

    private function resolve(callable|string $listener): callable
    {
        if (!is_string($listener) || (is_callable($listener) && !class_exists($listener))) {
            return $listener;
        }

        $resolved = $this->container->get($listener);

        if (!is_callable($resolved)) {
            throw new InvalidArgumentException(sprintf(
                "Resolved event listener '%s' must be invokable.",
                $listener,
            ));
        }

        return $resolved;
    }
}
