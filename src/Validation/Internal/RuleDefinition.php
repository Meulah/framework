<?php

declare(strict_types=1);

namespace Meulah\Validation\Internal;

final class RuleDefinition
{
    /** @param list<string> $parameters */
    public function __construct(
        public readonly string $name,
        public readonly array $parameters,
    ) {
    }
}
