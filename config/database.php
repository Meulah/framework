<?php

declare(strict_types=1);

use Meulah\Support\Environment;

return [
    'driver' => 'mysql',
    'host' => (string) Environment::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Environment::get('DB_PORT', 3306),
    'database' => (string) Environment::get('DB_NAME', 'meulah'),
    'username' => (string) Environment::get('DB_USER', 'root'),
    'password' => (string) Environment::get('DB_PASS', ''),
    'charset' => 'utf8mb4',
];
