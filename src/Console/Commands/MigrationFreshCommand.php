<?php

declare(strict_types=1);

namespace Meulah\Console\Commands;

use Meulah\Console\Command;
use Meulah\Console\Input;
use Meulah\Console\MigrationContext;
use Meulah\Console\Output;

final class MigrationFreshCommand implements Command
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function name(): string
    {
        return 'migrate:fresh';
    }

    public function description(): string
    {
        return 'Drop all tables and rerun migrations.';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $this->context->assertDestructiveCommandAllowed($input);
        [$migrator, $migrations] = $this->context->migrator($input);
        $completed = $migrator->fresh($migrations);

        $output->writeln('Dropped all tables.');

        if ($completed === []) {
            $output->writeln('No migrations found.');
            return 0;
        }

        foreach ($completed as $name) {
            $output->writeln("Migrated: {$name}");
        }

        return 0;
    }
}
