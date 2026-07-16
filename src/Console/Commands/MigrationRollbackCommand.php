<?php

declare(strict_types=1);

namespace Meulah\Console\Commands;

use Meulah\Console\Command;
use Meulah\Console\Input;
use Meulah\Console\MigrationContext;
use Meulah\Console\Output;

final class MigrationRollbackCommand implements Command
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Roll back the latest migration batch.';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $this->context->assertDestructiveCommandAllowed($input);
        [$migrator, $migrations] = $this->context->migrator($input);
        $rolledBack = $migrator->rollbackLast($migrations);

        if ($rolledBack === []) {
            $output->writeln('Nothing to rollback.');
            return 0;
        }

        foreach ($rolledBack as $name) {
            $output->writeln("Rolled back: {$name}");
        }

        return 0;
    }
}
