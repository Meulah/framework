<?php

declare(strict_types=1);

namespace Meulah\Database;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

final class Connection
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function mysql(array $config): self
    {
        self::requireDriver('mysql');

        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 3306);
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        return new self(new PDO(
            $dsn,
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
        ));
    }

    public static function postgresql(array $config): self
    {
        self::requireDriver('pgsql');

        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 5432);
        $database = $config['database'] ?? '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        return new self(new PDO(
            $dsn,
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
        ));
    }

    public static function sqlite(array $config): self
    {
        self::requireDriver('sqlite');

        $path = (string) ($config['path'] ?? $config['database'] ?? ':memory:');
        $connection = new self(new PDO('sqlite:' . $path));
        $connection->execute('PRAGMA foreign_keys = ON');

        return $connection;
    }

    public static function fromConfig(array $config): self
    {
        $driver = strtolower((string) ($config['driver'] ?? 'mysql'));

        return match ($driver) {
            'mysql' => self::mysql($config),
            'pgsql', 'postgres', 'postgresql' => self::postgresql($config),
            'sqlite' => self::sqlite($config),
            default => throw new InvalidArgumentException("Unsupported database driver: {$driver}"),
        };
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function execute(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    public function select(string $sql, array $parameters = []): array
    {
        return $this->execute($sql, $parameters)->fetchAll();
    }

    public function first(string $sql, array $parameters = []): object|false
    {
        return $this->execute($sql, $parameters)->fetch();
    }

    public function scalar(string $sql, array $parameters = []): mixed
    {
        return $this->execute($sql, $parameters)->fetchColumn();
    }

    public function insertId(): string|false
    {
        return $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function dropAllTables(): void
    {
        $driver = $this->driver();

        $tables = match ($driver) {
            'mysql' => $this->execute(
                "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'",
            )->fetchAll(PDO::FETCH_COLUMN),
            'pgsql' => $this->execute(
                'SELECT tablename FROM pg_tables WHERE schemaname = current_schema()',
            )->fetchAll(PDO::FETCH_COLUMN),
            'sqlite' => $this->execute(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
            )->fetchAll(PDO::FETCH_COLUMN),
            default => throw new RuntimeException("Dropping tables is not supported for PDO driver: {$driver}"),
        };

        if ($driver === 'mysql') {
            $this->execute('SET FOREIGN_KEY_CHECKS = 0');
        } elseif ($driver === 'sqlite') {
            $this->execute('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach ($tables as $table) {
                $cascade = $driver === 'pgsql' ? ' CASCADE' : '';
                $this->execute('DROP TABLE ' . $this->quoteIdentifier((string) $table) . $cascade);
            }
        } finally {
            if ($driver === 'mysql') {
                $this->execute('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($driver === 'sqlite') {
                $this->execute('PRAGMA foreign_keys = ON');
            }
        }
    }

    public static function identifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Invalid SQL identifier: {$identifier}");
        }

        return $identifier;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return match ($this->driver()) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            'pgsql', 'sqlite' => '"' . str_replace('"', '""', $identifier) . '"',
            default => throw new RuntimeException('Identifier quoting is not supported by this driver.'),
        };
    }

    private static function requireDriver(string $driver): void
    {
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException(
                "PDO driver '{$driver}' is not installed. Available drivers: " .
                (implode(', ', PDO::getAvailableDrivers()) ?: 'none'),
            );
        }
    }
}
