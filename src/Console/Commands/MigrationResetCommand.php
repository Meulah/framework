<?php

declare(strict_types=1);

namespace Meulah\Console\Commands;

use Meulah\Console\Command;
use Meulah\Console\Input;
use Meulah\Console\MigrationContext;
use Meulah\Console\Output;

final class MigrationResetCommand implements Command
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function name(): string
    {
        return 'migrate:reset';
    }

    public function description(): string
    {
        return 'Roll back every migration.';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $input->assertOnlyOptions(['path', 'force']);
        $input->assertArgumentCount(0, 0);
        $this->context->assertDestructiveCommandAllowed($input);
        [$migrator, $migrations] = $this->context->migrator($input);
        $rolledBack = $migrator->reset($migrations);

        if ($rolledBack === []) {
            $output->writeln('Nothing to reset.');
            return 0;
        }

        foreach ($rolledBack as $name) {
            $output->writeln("Rolled back: {$name}");
        }

        return 0;
    }
}
