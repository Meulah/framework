<?php

declare(strict_types=1);

namespace Meulah\Database;

final class MigrationFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly Migration $migration,
    ) {
    }
}

