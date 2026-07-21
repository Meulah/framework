<?php

declare(strict_types=1);

namespace Meulah\Authorization;

use RuntimeException;

final class AuthorizationException extends RuntimeException
{
    public function __construct(
        private readonly string $ability,
        private readonly AuthorizationResult $result,
    ) {
        parent::__construct('This action is not authorized.');
    }

    public function ability(): string
    {
        return $this->ability;
    }

    public function result(): AuthorizationResult
    {
        return $this->result;
    }
}
