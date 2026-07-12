<?php

declare(strict_types=1);

namespace Meulah\Container;

use Closure;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

final class Container
{
    /** @var array<string, string|Closure> */
    private array $bindings = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var list<string> */
    private array $resolving = [];

    public function __construct()
    {
        $this->instances[self::class] = $this;
    }

    public function bind(string $abstract, callable|string|null $concrete = null): self
    {
        return $this->register($abstract, $concrete, false);
    }

    public function singleton(string $abstract, callable|string|null $concrete = null): self
    {
        return $this->register($abstract, $concrete, true);
    }

    public function instance(string $abstract, object $instance): self
    {
        $this->assertCompatible($abstract, $instance, 'Instance');
        $this->instances[$abstract] = $instance;
        unset($this->bindings[$abstract], $this->shared[$abstract]);

        return $this;
    }

    public function has(string $abstract): bool
    {
        if (isset($this->instances[$abstract]) || isset($this->bindings[$abstract])) {
            return true;
        }

        if (!class_exists($abstract)) {
            return false;
        }

        return (new ReflectionClass($abstract))->isInstantiable();
    }

    public function get(string $abstract): object
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (in_array($abstract, $this->resolving, true)) {
            throw new BindingResolutionException(sprintf(
                'Circular dependency detected: %s.',
                implode(' -> ', [...$this->resolving, $abstract]),
            ));
        }

        $this->resolving[] = $abstract;

        try {
            $object = isset($this->bindings[$abstract])
                ? $this->resolveBinding($abstract)
                : $this->build($abstract);

            if ($this->shared[$abstract] ?? false) {
                $this->instances[$abstract] = $object;
            }

            return $object;
        } finally {
            array_pop($this->resolving);
        }
    }

    private function register(string $abstract, callable|string|null $concrete, bool $shared): self
    {
        if ($abstract === '') {
            throw new BindingResolutionException('A container binding needs a non-empty type name.');
        }

        $concrete ??= $abstract;
        $this->bindings[$abstract] = is_string($concrete)
            ? $concrete
            : Closure::fromCallable($concrete);
        $this->shared[$abstract] = $shared;
        unset($this->instances[$abstract]);

        return $this;
    }

    private function resolveBinding(string $abstract): object
    {
        $concrete = $this->bindings[$abstract];

        try {
            $object = is_string($concrete)
                ? ($concrete === $abstract ? $this->build($concrete) : $this->get($concrete))
                : $concrete($this);
        } catch (BindingResolutionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BindingResolutionException(
                sprintf("Factory for '%s' failed: %s", $abstract, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_object($object)) {
            throw new BindingResolutionException(sprintf(
                "Factory for '%s' must return an object.",
                $abstract,
            ));
        }

        $this->assertCompatible($abstract, $object, 'Binding');

        return $object;
    }

    private function assertCompatible(string $abstract, object $object, string $source): void
    {
        if ((interface_exists($abstract) || class_exists($abstract)) && !$object instanceof $abstract) {
            throw new BindingResolutionException(sprintf(
                "%s for '%s' has incompatible type '%s'.",
                $source,
                $abstract,
                $object::class,
            ));
        }
    }

    private function build(string $className): object
    {
        if (!class_exists($className)) {
            $kind = interface_exists($className) ? 'interface' : 'type';
            throw new BindingResolutionException(sprintf(
                "Cannot resolve %s '%s'; register an explicit binding.",
                $kind,
                $className,
            ));
        }

        $reflection = new ReflectionClass($className);

        if (!$reflection->isInstantiable()) {
            throw new BindingResolutionException(sprintf(
                "Cannot instantiate '%s'; register an explicit binding.",
                $className,
            ));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = array_map(
            fn (ReflectionParameter $parameter): mixed => $this->resolveParameter($parameter, $reflection),
            $constructor->getParameters(),
        );

        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveParameter(ReflectionParameter $parameter, ReflectionClass $class): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $this->normalizeTypeName($type->getName(), $class);

            if (!$parameter->isDefaultValueAvailable()
                || isset($this->bindings[$dependency])
                || isset($this->instances[$dependency])) {
                if ($this->has($dependency)) {
                    return $this->get($dependency);
                }
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type?->allowsNull()) {
            return null;
        }

        $description = $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType
            ? 'union or intersection type'
            : ($type instanceof ReflectionNamedType ? $type->getName() : 'untyped value');

        throw new BindingResolutionException(sprintf(
            "Cannot resolve parameter '$%s' (%s) while constructing '%s'.",
            $parameter->getName(),
            $description,
            $class->getName(),
        ));
    }

    private function normalizeTypeName(string $name, ReflectionClass $class): string
    {
        return match ($name) {
            'self', 'static' => $class->getName(),
            'parent' => $class->getParentClass()?->getName() ?? $name,
            default => $name,
        };
    }
}
