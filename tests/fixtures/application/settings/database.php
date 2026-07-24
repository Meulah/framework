<?php

declare(strict_types=1);

use Meulah\Support\Environment;

return [
    'driver' => 'sqlite',
    'path' => Environment::get('MEULAH_TEST_DATABASE_PATH', ':memory:'),
    'migrations' => 'database/migrations',
    'migration_table' => 'test_migrations',
];
