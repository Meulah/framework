<?php

declare(strict_types=1);

namespace Meulah\Database;

use RuntimeException;

final class Migrator
{
    private readonly MigrationRepository $repository;

    public function __construct(
        private readonly Connection $connection,
        ?MigrationRepository $repository = null,
    ) {
        $this->repository = $repository ?? new MigrationRepository($connection);
    }

    public function run(Migration $migration): void
    {
        $migration->up($this->connection);
    }

    public function rollback(Migration $migration): void
    {
        $migration->down($this->connection);
    }

    /** @param list<MigrationFile> $migrations @return list<string> */
    public function migrate(array $migrations): array
    {
        $records = $this->repository->records();
        $pending = array_values(array_filter(
            $migrations,
            static fn (MigrationFile $file): bool => !array_key_exists($file->name, $records),
        ));

        if ($pending === []) {
            return [];
        }

        $batch = $this->repository->nextBatch();
        $completed = [];

        foreach ($pending as $file) {
            $this->run($file->migration);
            $this->repository->record($file->name, $batch);
            $completed[] = $file->name;
        }

        return $completed;
    }

    /** @param list<MigrationFile> $migrations @return list<string> */
    public function rollbackLast(array $migrations): array
    {
        $files = [];

        foreach ($migrations as $file) {
            $files[$file->name] = $file;
        }

        $rolledBack = [];

        foreach ($this->repository->lastBatch() as $name) {
            if (!isset($files[$name])) {
                throw new RuntimeException("Cannot rollback missing migration file: {$name}");
            }

            $this->rollback($files[$name]->migration);
            $this->repository->delete($name);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /** @param list<MigrationFile> $migrations @return list<array{name: string, status: string, batch: int|null}> */
    public function status(array $migrations): array
    {
        $records = $this->repository->records();
        $status = [];

        foreach ($migrations as $file) {
            $status[] = [
                'name' => $file->name,
                'status' => array_key_exists($file->name, $records) ? 'Ran' : 'Pending',
                'batch' => $records[$file->name] ?? null,
            ];
            unset($records[$file->name]);
        }

        foreach ($records as $name => $batch) {
            $status[] = ['name' => $name, 'status' => 'Missing', 'batch' => $batch];
        }

        return $status;
    }
}
