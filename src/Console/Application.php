<?php

declare(strict_types=1);

namespace Meulah\Console;

use Meulah\Console\Commands\MigrationCommands;

/**
 * Backward-compatible entry point for application launchers.
 * New console composition should use ConsoleApplication directly.
 */
final class Application
{
    private readonly ConsoleApplication $console;

    public function __construct(string $root, ?Output $output = null)
    {
        $this->console = new ConsoleApplication(output: $output);

        foreach (MigrationCommands::forApplication($root) as $command) {
            $this->console->add($command);
        }
    }

    /** @param list<string> $arguments */
    public static function runFrom(string $applicationRoot, array $arguments): int
    {
        return (new self($applicationRoot))->run($arguments);
    }

    public function add(Command $command): void
    {
        $this->console->add($command);
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        return $this->console->run($arguments);
    }
}
