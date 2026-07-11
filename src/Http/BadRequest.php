<?php

declare(strict_types=1);

namespace Meulah\Http;

use RuntimeException;
use Throwable;

class BadRequest extends RuntimeException
{
    public function __construct(
        string $message = 'Bad request.',
        private readonly string $errorCode = 'bad_request',
        private readonly ?string $detail = null,
        private readonly int $status = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    public function status(): int
    {
        return $this->status;
    }
}

