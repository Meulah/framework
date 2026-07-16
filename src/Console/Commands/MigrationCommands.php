<?php

declare(strict_types=1);

namespace Meulah\Console\Commands;

use Meulah\Console\Command;
use Meulah\Console\MigrationContext;
use Meulah\Console\ProjectRoot;

final class MigrationCommands
{
    /** @return list<Command> */
    public static function forApplication(string $root): array
    {
        $context = new MigrationContext(ProjectRoot::explicit($root));

        return [
            new MakeMigrationCommand($context),
            new MigrateCommand($context),
            new MigrationStatusCommand($context),
            new MigrationRollbackCommand($context),
            new MigrationResetCommand($context),
            new MigrationFreshCommand($context),
        ];
    }

    private function __construct()
    {
    }
}
