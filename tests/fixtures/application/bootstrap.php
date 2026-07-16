<?php

declare(strict_types=1);

use Meulah\Application;
use Meulah\Config\Repository;
use Meulah\Routing\Router;
use Meulah\Support\Environment;

return new Application(
    new Router(),
    new Repository([
        'app' => [
            'environment' => Environment::get('MEULAH_TEST_APP_ENV', 'testing'),
            'debug' => false,
        ],
        'database' => [
            'driver' => 'sqlite',
            'path' => Environment::get('MEULAH_TEST_DATABASE_PATH', ':memory:'),
            'migrations' => 'database/migrations',
            'migration_table' => 'test_migrations',
        ],
    ]),
);
