<?php

declare(strict_types=1);

namespace Meulah\Http;

final class InvalidInput extends BadRequest
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'invalid_input');
    }
}

