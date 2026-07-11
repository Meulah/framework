<?php

declare(strict_types=1);

namespace Meulah\Database;

use RuntimeException;

final class MigrationFinder
{
    /** @return list<MigrationFile> */
    public function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];

        foreach ($files as $file) {
            $migration = (static fn (string $__file): mixed => require $__file)($file);

            if (!$migration instanceof Migration) {
                throw new RuntimeException("Migration file must return a Migration instance: {$file}");
            }

            $migrations[] = new MigrationFile(
                pathinfo($file, PATHINFO_FILENAME),
                $file,
                $migration,
            );
        }

        return $migrations;
    }
}

