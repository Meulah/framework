<?php

declare(strict_types=1);

namespace Meulah\Database;

final class MigrationRepository
{
    private readonly string $table;

    public function __construct(
        private readonly Connection $connection,
        string $table = 'meulah_migrations',
    ) {
        $this->table = Connection::identifier($table);
    }

    public function ensureTable(): void
    {
        $this->connection->execute(
            "CREATE TABLE IF NOT EXISTS {$this->table} (" .
            'migration VARCHAR(255) PRIMARY KEY, ' .
            'batch INTEGER NOT NULL, ' .
            'migrated_at VARCHAR(32) NOT NULL' .
            ')',
        );
    }

    /** @return array<string, int> */
    public function records(): array
    {
        $this->ensureTable();
        $records = [];

        foreach ($this->connection->select(
            "SELECT migration, batch FROM {$this->table} ORDER BY migration ASC",
        ) as $row) {
            $records[(string) $row->migration] = (int) $row->batch;
        }

        return $records;
    }

    public function nextBatch(): int
    {
        $this->ensureTable();
        return (int) $this->connection->scalar(
            "SELECT COALESCE(MAX(batch), 0) + 1 FROM {$this->table}",
        );
    }

    public function record(string $migration, int $batch): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (migration, batch, migrated_at) " .
            'VALUES (:migration, :batch, :migrated_at)',
            [
                'migration' => $migration,
                'batch' => $batch,
                'migrated_at' => gmdate('Y-m-d H:i:s'),
            ],
        );
    }

    public function delete(string $migration): void
    {
        $this->connection->execute(
            "DELETE FROM {$this->table} WHERE migration = :migration",
            ['migration' => $migration],
        );
    }

    /** @return list<string> */
    public function lastBatch(): array
    {
        $this->ensureTable();
        $batch = (int) $this->connection->scalar(
            "SELECT COALESCE(MAX(batch), 0) FROM {$this->table}",
        );

        if ($batch === 0) {
            return [];
        }

        return array_map(
            static fn (object $row): string => (string) $row->migration,
            $this->connection->select(
                "SELECT migration FROM {$this->table} WHERE batch = :batch ORDER BY migration DESC",
                ['batch' => $batch],
            ),
        );
    }
}

