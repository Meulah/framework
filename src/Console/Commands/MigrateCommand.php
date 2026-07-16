<?php

declare(strict_types=1);

namespace Meulah\Console\Commands;

use Meulah\Console\Command;
use Meulah\Console\Input;
use Meulah\Console\MigrationContext;
use Meulah\Console\Output;

final class MigrateCommand implements Command
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run all pending migrations.';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        [$migrator, $migrations] = $this->context->migrator($input);
        $completed = $migrator->migrate($migrations);

        if ($completed === []) {
            $output->writeln('Nothing to migrate.');
            return 0;
        }

        foreach ($completed as $name) {
            $output->writeln("Migrated: {$name}");
        }

        return 0;
    }
}
