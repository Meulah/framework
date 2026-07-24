<?php

declare(strict_types=1);

namespace Meulah\Console;

use Meulah\Console\Commands\MigrationCommands;

/** Application-command composition for one validated Meulah application root. */
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
