<?php

declare(strict_types=1);

namespace Meulah\Console\Commands;

use Meulah\Console\Command;
use Meulah\Console\Input;
use Meulah\Console\MigrationContext;
use Meulah\Console\Output;
use RuntimeException;

final class MakeMigrationCommand implements Command
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'Create a migration file.';
    }

    public function aliases(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $input->assertOnlyOptions(['path']);
        $input->assertArgumentCount(1, 1);

        $name = strtolower(trim((string) preg_replace(
            '/[^A-Za-z0-9]+/',
            '_',
            $input->argument(0, '') ?? '',
        ), '_'));

        if ($name === '') {
            throw new RuntimeException('A migration name is required.');
        }

        $directory = $this->context->migrationPath($input);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create migration directory: {$directory}");
        }

        $file = $directory . DIRECTORY_SEPARATOR . gmdate('Y_m_d_His') . '_' . $name . '.php';

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

        $contents = $template . PHP_EOL;
        $handle = @fopen($file, 'x');

        if ($handle === false) {
            if (is_file($file)) {
                throw new RuntimeException("Migration already exists: {$file}");
            }

            throw new RuntimeException("Unable to create migration: {$file}");
        }

        try {
            $offset = 0;
            $length = strlen($contents);

            while ($offset < $length) {
                $written = @fwrite($handle, substr($contents, $offset));

                if ($written === false || $written === 0) {
                    throw new RuntimeException("Unable to write migration: {$file}");
                }

                $offset += $written;
            }

            if (!fclose($handle)) {
                $handle = null;
                throw new RuntimeException("Unable to finish writing migration: {$file}");
            }

            $handle = null;
        } catch (\Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($file);
            throw $exception;
        }

        $output->writeln("Created: {$file}");

        return 0;
    }
}
