<?php

declare(strict_types=1);

use Meulah\Support\Environment;

$driver = strtolower((string) Environment::get('DB_DRIVER', 'mysql'));
$defaultPort = in_array($driver, ['pgsql', 'postgres', 'postgresql'], true) ? 5432 : 3306;
$configuredPort = Environment::get('DB_PORT');
$port = $configuredPort === null || $configuredPort === '' ? $defaultPort : (int) $configuredPort;
$sqlitePath = (string) Environment::get('DB_PATH', 'database.sqlite');

if (
    $sqlitePath !== ':memory:'
    && preg_match('#^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $sqlitePath) !== 1
) {
    $sqlitePath = dirname(__DIR__) . '/' . ltrim($sqlitePath, '/\\');
}

return [
    'driver' => $driver,
    'host' => (string) Environment::get('DB_HOST', '127.0.0.1'),
    'port' => $port,
    'database' => (string) Environment::get('DB_NAME', 'meulah'),
    'username' => (string) Environment::get('DB_USER', 'root'),
    'password' => (string) Environment::get('DB_PASS', ''),
    'charset' => 'utf8mb4',
    'path' => $sqlitePath,
    'migrations' => (string) Environment::get('DB_MIGRATIONS', 'database/migrations'),
    'migration_table' => (string) Environment::get('DB_MIGRATION_TABLE', 'meulah_migrations'),
];
