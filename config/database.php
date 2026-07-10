<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => (string) ($_ENV['DB_HOST'] ?? '127.0.0.1'),
    'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
    'database' => (string) ($_ENV['DB_NAME'] ?? 'meulah'),
    'username' => (string) ($_ENV['DB_USER'] ?? 'root'),
    'password' => (string) ($_ENV['DB_PASS'] ?? ''),
    'charset' => 'utf8mb4',
];

