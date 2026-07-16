<?php

declare(strict_types=1);

namespace Meulah\Console\Commands;

use Meulah\Console\Command;
use Meulah\Console\Input;
use Meulah\Console\MigrationContext;
use Meulah\Console\Output;

final class MigrationStatusCommand implements Command
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show migration status.';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        [$migrator, $migrations] = $this->context->migrator($input);
        $rows = $migrator->status($migrations);

        if ($rows === []) {
            $output->writeln('No migrations found.');
            return 0;
        }

        $output->write(sprintf("%-10s %-7s %s\n", 'Status', 'Batch', 'Migration'));

        foreach ($rows as $row) {
            $output->write(sprintf(
                "%-10s %-7s %s\n",
                $row['status'],
                $row['batch'] ?? '-',
                $row['name'],
            ));
        }

        return 0;
    }
}
