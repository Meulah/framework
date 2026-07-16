<?php

declare(strict_types=1);

namespace Meulah\Console;

use InvalidArgumentException;

final class Input
{
    /**
     * @param list<string> $arguments
     * @param array<string, string|bool> $options
     */
    public function __construct(
        private readonly string $command,
        private readonly array $arguments = [],
        private readonly array $options = [],
    ) {
    }

    /** @param list<string> $tokens */
    public static function fromTokens(string $command, array $tokens): self
    {
        $options = [];
        $arguments = [];
        $parseOptions = true;

        foreach ($tokens as $token) {
            if ($parseOptions && $token === '--') {
                $parseOptions = false;
                continue;
            }

            if ($parseOptions && str_starts_with($token, '--')) {
                $option = substr($token, 2);

                if ($option === '') {
                    throw new InvalidArgumentException('An option name cannot be empty.');
                }

                [$name, $value] = array_pad(explode('=', $option, 2), 2, true);

                if ($name === '') {
                    throw new InvalidArgumentException('An option name cannot be empty.');
                }
                $options[$name] = $value;
                continue;
            }

            $arguments[] = $token;
        }

        return new self($command, $arguments, $options);
    }

    public function command(): string
    {
        return $this->command;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function argument(int $position, ?string $default = null): ?string
    {
        return $this->arguments[$position] ?? $default;
    }

    /** @return array<string, string|bool> */
    public function options(): array
    {
        return $this->options;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function option(string $name, string|bool|null $default = null): string|bool|null
    {
        return $this->options[$name] ?? $default;
    }
}
