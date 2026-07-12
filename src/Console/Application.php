<?php

declare(strict_types=1);

namespace Meulah\Console;

use Meulah\Application as Kernel;
use Meulah\Database\Connection;
use Meulah\Database\MigrationFinder;
use Meulah\Database\MigrationRepository;
use Meulah\Database\Migrator;
use RuntimeException;
use Throwable;

final class Application
{
    public function __construct(private readonly string $root)
    {
    }

    public static function runFrom(string $applicationRoot, array $arguments): int
    {
        return (new self(ProjectRoot::explicit($applicationRoot)))->run($arguments);
    }

    public function run(array $arguments): int
    {
        $command = $arguments[1] ?? 'help';
        [$options, $values] = $this->parseArguments(array_slice($arguments, 2));

        try {
            return match ($command) {
                'migrate' => $this->migrate($options),
                'migrate:status' => $this->status($options),
                'migrate:rollback' => $this->rollback($options),
                'migrate:reset' => $this->reset($options),
                'migrate:fresh' => $this->fresh($options),
                'make:migration' => $this->makeMigration($values[0] ?? '', $options),
                'help', '--help', '-h' => $this->help(),
                default => throw new RuntimeException("Unknown command: {$command}"),
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function migrate(array $options): int
    {
        [$migrator, $migrations] = $this->context($options);
        $completed = $migrator->migrate($migrations);

        if ($completed === []) {
            fwrite(STDOUT, 'Nothing to migrate.' . PHP_EOL);
            return 0;
        }

        foreach ($completed as $name) {
            fwrite(STDOUT, "Migrated: {$name}" . PHP_EOL);
        }

        return 0;
    }

    private function rollback(array $options): int
    {
        $kernel = $this->kernel();
        $this->assertDestructiveCommandAllowed($kernel, $options);
        [$migrator, $migrations] = $this->context($options, $kernel);
        $rolledBack = $migrator->rollbackLast($migrations);

        if ($rolledBack === []) {
            fwrite(STDOUT, 'Nothing to rollback.' . PHP_EOL);
            return 0;
        }

        foreach ($rolledBack as $name) {
            fwrite(STDOUT, "Rolled back: {$name}" . PHP_EOL);
        }

        return 0;
    }

    private function reset(array $options): int
    {
        $kernel = $this->kernel();
        $this->assertDestructiveCommandAllowed($kernel, $options);
        [$migrator, $migrations] = $this->context($options, $kernel);
        $rolledBack = $migrator->reset($migrations);

        if ($rolledBack === []) {
            fwrite(STDOUT, 'Nothing to reset.' . PHP_EOL);
            return 0;
        }

        foreach ($rolledBack as $name) {
            fwrite(STDOUT, "Rolled back: {$name}" . PHP_EOL);
        }

        return 0;
    }

    private function fresh(array $options): int
    {
        $kernel = $this->kernel();
        $this->assertDestructiveCommandAllowed($kernel, $options);
        [$migrator, $migrations] = $this->context($options, $kernel);
        $completed = $migrator->fresh($migrations);

        fwrite(STDOUT, 'Dropped all tables.' . PHP_EOL);

        if ($completed === []) {
            fwrite(STDOUT, 'No migrations found.' . PHP_EOL);
            return 0;
        }

        foreach ($completed as $name) {
            fwrite(STDOUT, "Migrated: {$name}" . PHP_EOL);
        }

        return 0;
    }

    private function status(array $options): int
    {
        [$migrator, $migrations] = $this->context($options);
        $rows = $migrator->status($migrations);

        if ($rows === []) {
            fwrite(STDOUT, 'No migrations found.' . PHP_EOL);
            return 0;
        }

        fwrite(STDOUT, sprintf("%-10s %-7s %s\n", 'Status', 'Batch', 'Migration'));

        foreach ($rows as $row) {
            fwrite(STDOUT, sprintf(
                "%-10s %-7s %s\n",
                $row['status'],
                $row['batch'] ?? '-',
                $row['name'],
            ));
        }

        return 0;
    }

    private function makeMigration(string $name, array $options): int
    {
        $name = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));

        if ($name === '') {
            throw new RuntimeException('A migration name is required.');
        }

        $directory = $this->migrationPath($options, $this->kernel());

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create migration directory: {$directory}");
        }

        $file = $directory . DIRECTORY_SEPARATOR . gmdate('Y_m_d_His') . '_' . $name . '.php';

        if (is_file($file)) {
            throw new RuntimeException("Migration already exists: {$file}");
        }

        $template = <<<'PHP'
<?php

declare(strict_types=1);

use Meulah\Database\Connection;
use Meulah\Database\Migration;

return new class implements Migration {
    public function up(Connection $connection): void
    {
        // Apply the schema change.
    }

    public function down(Connection $connection): void
    {
        // Reverse the schema change.
    }
};
PHP;

        if (file_put_contents($file, $template . PHP_EOL) === false) {
            throw new RuntimeException("Unable to write migration: {$file}");
        }

        fwrite(STDOUT, "Created: {$file}" . PHP_EOL);
        return 0;
    }

    private function context(array $options, ?Kernel $kernel = null): array
    {
        $kernel ??= $this->kernel();
        $database = $kernel->config()->array('database');
        $connection = Connection::fromConfig($database);
        $repository = new MigrationRepository(
            $connection,
            (string) ($database['migration_table'] ?? 'meulah_migrations'),
        );
        $migrator = new Migrator($connection, $repository);
        $migrations = (new MigrationFinder())->discover($this->migrationPath($options, $kernel));

        return [$migrator, $migrations];
    }

    private function assertDestructiveCommandAllowed(Kernel $kernel, array $options): void
    {
        if (
            $kernel->config()->string('app.environment') === 'production'
            && !array_key_exists('force', $options)
        ) {
            throw new RuntimeException('Destructive migration commands require --force in production.');
        }
    }

    private function kernel(): Kernel
    {
        /** @var Kernel $kernel */
        $kernel = require $this->root . '/bootstrap.php';
        return $kernel;
    }

    private function migrationPath(array $options, Kernel $kernel): string
    {
        $path = (string) ($options['path'] ?? $kernel->config()->string('database.migrations'));

        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $path) === 1) {
            return rtrim($path, '/\\');
        }

        return $this->root . DIRECTORY_SEPARATOR . trim($path, '/\\');
    }

    private function parseArguments(array $arguments): array
    {
        $options = [];
        $values = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, true);
                $options[$key] = $value;
            } else {
                $values[] = $argument;
            }
        }

        return [$options, $values];
    }

    private function help(): int
    {
        fwrite(STDOUT, <<<'TEXT'
Meulah CLI

Commands:
  make:migration <name>  Create a migration file
  migrate                Run all pending migrations
  migrate:status         Show migration status
  migrate:rollback       Roll back the last batch
  migrate:reset          Roll back every migration
  migrate:fresh          Drop all tables and rerun migrations

Options:
  --path=<directory>     Override the configured migration directory
  --force                Allow destructive commands in production

TEXT);

        return 0;
    }
}
