<?php

declare(strict_types=1);

namespace Meulah\Validation;

use Meulah\Http\BadRequest;

final class ValidationException extends BadRequest
{
    public function __construct(private readonly ValidationResult $result)
    {
        parent::__construct(
            'The supplied data failed validation.',
            'validation_failed',
            status: 422,
        );
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->result->errors();
    }

    public function error(string $field): ?string
    {
        return $this->result->error($field);
    }
}
