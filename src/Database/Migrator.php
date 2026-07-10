<?php

declare(strict_types=1);

namespace Meulah\Database;

final class Migrator
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function run(Migration $migration): void
    {
        $this->connection->transaction(
            static fn (Connection $connection) => $migration->up($connection),
        );
    }

    public function rollback(Migration $migration): void
    {
        $this->connection->transaction(
            static fn (Connection $connection) => $migration->down($connection),
        );
    }
}

