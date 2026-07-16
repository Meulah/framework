<?php

declare(strict_types=1);

namespace Meulah\Validation;

final class ValidationResult
{
    /**
     * @param array<string, mixed> $validated
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private readonly array $validated,
        private readonly array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}
