<?php

declare(strict_types=1);

namespace Meulah\Routing;

use InvalidArgumentException;

final class RouteParameters
{
    /** @param array<string, string> $parameters */
    public function __construct(private readonly array $parameters)
    {
        foreach ($this->parameters as $name => $value) {
            if (
                !is_string($name)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1
                || !is_string($value)
            ) {
                throw new InvalidArgumentException(
                    'Route parameters must contain valid string names and string values.',
                );
            }
        }
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->parameters;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->parameters);
    }

    public function require(string $name): string
    {
        if (!$this->has($name)) {
            throw new MissingRouteParameterException(sprintf(
                "Route parameter '%s' is not available for this request.",
                $name,
            ));
        }

        return $this->parameters[$name];
    }
}
