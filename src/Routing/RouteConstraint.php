<?php

declare(strict_types=1);

namespace Meulah\Routing;

final class RouteConstraint
{
    /** @var list<string> */
    private const DELIMITERS = ['#', '~', '%', '!', '@', ';', '`', ',', ':', '=', '-'];

    public static function validate(string $parameter, string $pattern): void
    {
        if ($pattern === '') {
            throw new RouteDefinitionException('A route parameter constraint cannot be empty.');
        }

        if (preg_match("/\\(\\?(?:P?<|<[A-Za-z_]|'[A-Za-z_])/", $pattern) === 1) {
            throw new RouteDefinitionException(sprintf(
                "The constraint for route parameter '%s' cannot contain a named capture.",
                $parameter,
            ));
        }

        $expression = self::expression($parameter, $pattern);

        if (@preg_match($expression, '') === false) {
            throw new RouteDefinitionException(sprintf(
                "The constraint for route parameter '%s' is not a valid regular expression.",
                $parameter,
            ));
        }
    }

    public static function matches(string $parameter, string $pattern, string $value): bool
    {
        $result = preg_match(self::expression($parameter, $pattern), $value);

        if ($result === false) {
            throw new RouteDefinitionException(sprintf(
                "The constraint for route parameter '%s' could not be evaluated.",
                $parameter,
            ));
        }

        return $result === 1;
    }

    private static function expression(string $parameter, string $pattern): string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (!str_contains($pattern, $delimiter)) {
                return $delimiter . '\\A(?:' . $pattern . ')\\z' . $delimiter . 'D';
            }
        }

        throw new RouteDefinitionException(sprintf(
            "The constraint for route parameter '%s' contains too many regular-expression delimiters.",
            $parameter,
        ));
    }

    private function __construct()
    {
    }
}
