<?php

declare(strict_types=1);

namespace Meulah\Http;

interface ResponseInterface
{
    public function status(): int;

    public function content(): string;

    /** @return array<string, string> */
    public function headers(): array;

    public function withoutBody(): self;

    public function withHeader(string $name, string $value): self;

    public function send(): void;
}

