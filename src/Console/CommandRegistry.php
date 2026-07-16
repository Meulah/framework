<?php

declare(strict_types=1);

namespace Meulah\Console;

use InvalidArgumentException;

final class CommandRegistry
{
    /** @var array<string, Command> */
    private array $commands = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function add(Command $command): void
    {
        $name = trim($command->name());

        if ($name === '') {
            throw new InvalidArgumentException('A command needs a non-empty name.');
        }

        if ($name !== $command->name()) {
            throw new InvalidArgumentException("Command names cannot contain surrounding whitespace: '{$name}'.");
        }

        $declaredAliases = $command->aliases();
        $aliases = array_map('trim', $declaredAliases);

        if ($aliases !== $declaredAliases) {
            throw new InvalidArgumentException("Command '{$name}' aliases cannot contain surrounding whitespace.");
        }

        if (count($aliases) !== count(array_unique($aliases))) {
            throw new InvalidArgumentException("Command '{$name}' has duplicate aliases.");
        }

        $this->assertAvailable($name);

        foreach ($aliases as $alias) {
            if ($alias === '') {
                throw new InvalidArgumentException("Command '{$name}' has an empty alias.");
            }

            if ($alias === $name) {
                throw new InvalidArgumentException("Command '{$name}' cannot alias its own name.");
            }

            $this->assertAvailable($alias);
        }

        $this->commands[$name] = $command;

        foreach ($aliases as $alias) {
            $this->aliases[$alias] = $name;
        }
    }

    public function get(string $name): ?Command
    {
        $name = $this->aliases[$name] ?? $name;

        return $this->commands[$name] ?? null;
    }

    /** @return list<Command> */
    public function commands(): array
    {
        $commands = $this->commands;
        ksort($commands);

        return array_values($commands);
    }

    /** @return list<string> */
    public function suggestions(string $unknown): array
    {
        $matches = [];

        foreach (array_keys($this->commands) as $name) {
            $distance = levenshtein($unknown, $name);

            $threshold = max(2, (int) floor(strlen($name) * 0.4));

            if ($distance <= $threshold) {
                $matches[$name] = $distance;
            }
        }

        foreach ($this->aliases as $alias => $name) {
            $distance = levenshtein($unknown, $alias);
            $threshold = max(2, (int) floor(strlen($alias) * 0.4));

            if ($distance <= $threshold && (!isset($matches[$name]) || $distance < $matches[$name])) {
                $matches[$name] = $distance;
            }
        }

        asort($matches);

        return array_slice(array_keys($matches), 0, 3);
    }

    private function assertAvailable(string $name): void
    {
        if (isset($this->commands[$name]) || isset($this->aliases[$name])) {
            throw new InvalidArgumentException("Command name or alias '{$name}' is already registered.");
        }
    }
}
