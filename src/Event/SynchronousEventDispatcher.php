<?php

declare(strict_types=1);

namespace Meulah\Event;

use Closure;
use InvalidArgumentException;
use Meulah\Container\Container;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SplObjectStorage;

final class SynchronousEventDispatcher implements EventDispatcher
{
    /** @var array<class-string, list<callable|string>> */
    private array $listeners = [];

    private readonly Container $container;
    /** @var SplObjectStorage<object, null> */
    private readonly SplObjectStorage $dispatching;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container();
        $this->dispatching = new SplObjectStorage();
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
        $this->assertListenerSignature($event, $listener);

        $this->listeners[$event][] = $listener;
    }

    public function dispatch(object $event): object
    {
        if ($this->dispatching->contains($event)) {
            throw new EventDispatchException(sprintf(
                "Circular dispatch detected for event object '%s'.",
                $event::class,
            ));
        }

        $listeners = $this->listeners[$event::class] ?? [];
        $this->dispatching->attach($event);

        try {
            foreach ($listeners as $listener) {
                $this->resolve($listener)($event);
            }
        } finally {
            $this->dispatching->detach($event);
        }

        return $event;
    }

    private function assertListenerSignature(string $event, callable|string $listener): void
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

        $parameter = $reflection->getParameters()[0] ?? null;

        if ($parameter === null) {
            return;
        }

        if ($parameter->isPassedByReference()) {
            throw new InvalidArgumentException(sprintf(
                "Event listener '%s' cannot receive its event by reference.",
                is_string($listener) ? $listener : $reflection->getName(),
            ));
        }

        $type = $parameter->getType();

        if ($type !== null && !$this->typeAcceptsEvent($type, $event)) {
            throw new InvalidArgumentException(sprintf(
                "Event listener '%s' parameter '$%s' typed %s cannot receive event '%s'.",
                is_string($listener) ? $listener : $reflection->getName(),
                $parameter->getName(),
                (string) $type,
                $event,
            ));
        }
    }

    private function typeAcceptsEvent(ReflectionType $type, string $event): bool
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return in_array($type->getName(), ['mixed', 'object'], true);
            }

            return is_a($event, $type->getName(), true);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->typeAcceptsEvent($member, $event)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (!$this->typeAcceptsEvent($member, $event)) {
                    return false;
                }
            }

            return true;
        }

        return false;
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
