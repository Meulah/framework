<?php

declare(strict_types=1);

namespace Meulah\Console;

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
                    throw new ConsoleInputException('An option name cannot be empty.');
                }

                [$name, $value] = array_pad(explode('=', $option, 2), 2, true);

                if ($name === '') {
                    throw new ConsoleInputException('An option name cannot be empty.');
                }
                if (array_key_exists($name, $options)) {
                    throw new ConsoleInputException("Option '--{$name}' cannot be provided more than once.");
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

    /** @param list<string> $allowed */
    public function assertOnlyOptions(array $allowed): void
    {
        foreach (array_keys($this->options) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new ConsoleInputException(sprintf(
                    "Unknown option '--%s' for command '%s'.",
                    $name,
                    $this->command,
                ));
            }
        }
    }

    public function assertArgumentCount(int $minimum, int $maximum): void
    {
        if ($minimum < 0 || $maximum < $minimum) {
            throw new \InvalidArgumentException('Argument count boundaries are invalid.');
        }

        $count = count($this->arguments);

        if ($count >= $minimum && $count <= $maximum) {
            return;
        }

        $expected = match (true) {
            $maximum === 0 => 'no arguments',
            $minimum === $maximum => sprintf(
                'exactly %d argument%s',
                $minimum,
                $minimum === 1 ? '' : 's',
            ),
            default => sprintf('%d to %d arguments', $minimum, $maximum),
        };

        throw new ConsoleInputException(sprintf(
            "Command '%s' expects %s; %d given.",
            $this->command,
            $expected,
            $count,
        ));
    }

    public function assertFlag(string $name): void
    {
        if (!$this->hasOption($name)) {
            return;
        }

        if ($this->option($name) !== true) {
            throw new ConsoleInputException(sprintf(
                "Option '--%s' is a flag and does not accept a value.",
                $name,
            ));
        }
    }
}
