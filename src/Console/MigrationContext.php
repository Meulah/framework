<?php

declare(strict_types=1);

namespace Meulah\Console;

use Meulah\Application as Kernel;
use Meulah\Database\Connection;
use Meulah\Database\MigrationFinder;
use Meulah\Database\MigrationRepository;
use Meulah\Database\Migrator;
use RuntimeException;

final class MigrationContext
{
    private ?Kernel $kernel = null;

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{Migrator, list<\Meulah\Database\MigrationFile>} */
    public function migrator(Input $input): array
    {
        $database = $this->kernel()->config()->array('database');
        $connection = Connection::fromConfig($database);
        $repository = new MigrationRepository(
            $connection,
            (string) ($database['migration_table'] ?? 'meulah_migrations'),
        );
        $migrator = new Migrator($connection, $repository);
        $migrations = (new MigrationFinder())->discover($this->migrationPath($input));

        return [$migrator, $migrations];
    }

    public function migrationPath(Input $input): string
    {
        $path = (string) ($input->option('path') ?? $this->kernel()->config()->string('database.migrations'));

        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $path) === 1) {
            return rtrim($path, '/\\');
        }

        return $this->root . DIRECTORY_SEPARATOR . trim($path, '/\\');
    }

    public function assertDestructiveCommandAllowed(Input $input): void
    {
        if (
            $this->kernel()->config()->string('app.environment') === 'production'
            && $input->option('force') !== true
        ) {
            throw new RuntimeException('Destructive migration commands require --force in production.');
        }
    }

    private function kernel(): Kernel
    {
        if ($this->kernel !== null) {
            return $this->kernel;
        }

        $kernel = require $this->root . '/bootstrap.php';

        if (!$kernel instanceof Kernel) {
            throw new RuntimeException('Application bootstrap must return a Meulah application.');
        }

        return $this->kernel = $kernel;
    }
}
