<?php

declare(strict_types=1);

namespace Meulah\Authorization;

use Closure;
use Meulah\Auth\Authenticatable;
use Meulah\Auth\Guard;
use Meulah\Container\Container;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;

final class AuthorizationGate implements Gate
{
    /**
     * @var array<string, array{callback: Closure|string, allows_guest: bool}>
     */
    private array $abilities = [];

    public function __construct(
        private readonly Guard $guard,
        private readonly Container $container,
    ) {
    }

    public function define(string $ability, callable|string $callback): void
    {
        $this->assertAbilityName($ability);

        if (array_key_exists($ability, $this->abilities)) {
            throw new AuthorizationDefinitionException(
                sprintf("Authorization ability '%s' is already defined.", $ability),
            );
        }

        [$normalized, $reflection] = $this->normalizeCallback($callback);
        $this->abilities[$ability] = [
            'callback' => $normalized,
            'allows_guest' => $this->callbackAllowsGuest($ability, $reflection),
        ];
    }

    public function allows(string $ability, mixed ...$arguments): bool
    {
        return $this->inspect($ability, ...$arguments)->allowed();
    }

    public function denies(string $ability, mixed ...$arguments): bool
    {
        return $this->inspect($ability, ...$arguments)->denied();
    }

    public function inspect(string $ability, mixed ...$arguments): AuthorizationResult
    {
        $definition = $this->abilities[$ability] ?? null;

        if ($definition === null) {
            throw new AbilityNotDefinedException(
                sprintf("Authorization ability '%s' is not defined.", $ability),
            );
        }

        $actor = $this->guard->user();

        if ($actor === null && !$definition['allows_guest']) {
            return AuthorizationResult::deny();
        }

        $callback = $definition['callback'];

        if (is_string($callback)) {
            $callback = $this->container->get($callback);

            if (!is_callable($callback)) {
                throw new AuthorizationDefinitionException(
                    'The resolved authorization callback must be invokable.',
                );
            }
        }

        $result = $callback($actor, ...$arguments);

        return match (true) {
            $result instanceof AuthorizationResult => $result,
            $result === true => AuthorizationResult::allow(),
            $result === false => AuthorizationResult::deny(),
            default => throw new AuthorizationCallbackException(sprintf(
                "Authorization ability '%s' must return bool or AuthorizationResult; %s returned.",
                $ability,
                get_debug_type($result),
            )),
        };
    }

    public function authorize(string $ability, mixed ...$arguments): void
    {
        $result = $this->inspect($ability, ...$arguments);

        if ($result->denied()) {
            throw new AuthorizationException($ability, $result);
        }
    }

    private function assertAbilityName(string $ability): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]*$/D', $ability) !== 1) {
            throw new AuthorizationDefinitionException(
                'Authorization ability names must be non-empty exact names without whitespace or wildcards.',
            );
        }
    }

    /**
     * @return array{Closure|string, ReflectionFunctionAbstract}
     */
    private function normalizeCallback(callable|string $callback): array
    {
        if ($callback instanceof Closure) {
            return [$callback, new ReflectionFunction($callback)];
        }

        if (!is_string($callback) || !class_exists($callback)) {
            throw new AuthorizationDefinitionException(
                'Authorization callbacks must be closures or invokable class names.',
            );
        }

        $class = new ReflectionClass($callback);

        if (!$class->isInstantiable() || !$class->hasMethod('__invoke')) {
            throw new AuthorizationDefinitionException(
                'Authorization callback classes must be instantiable and invokable.',
            );
        }

        $method = $class->getMethod('__invoke');

        if (!$method->isPublic()) {
            throw new AuthorizationDefinitionException(
                'Authorization callback classes must expose a public __invoke method.',
            );
        }

        return [$callback, new ReflectionMethod($callback, '__invoke')];
    }

    private function callbackAllowsGuest(
        string $ability,
        ReflectionFunctionAbstract $callback,
    ): bool {
        $actor = $callback->getParameters()[0] ?? null;
        $type = $actor?->getType();

        if (
            $actor === null
            || $actor->isPassedByReference()
            || $actor->isVariadic()
            || !$type instanceof ReflectionNamedType
            || $type->isBuiltin()
            || !is_a($type->getName(), Authenticatable::class, true)
        ) {
            throw new AuthorizationDefinitionException(sprintf(
                "Authorization ability '%s' must receive Authenticatable or ?Authenticatable as its first parameter.",
                $ability,
            ));
        }

        return $type->allowsNull();
    }
}
