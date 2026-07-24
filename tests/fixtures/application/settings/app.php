<?php

declare(strict_types=1);

use Meulah\Support\Environment;

return [
    'name' => 'Meulah Tests',
    'environment' => Environment::get('MEULAH_TEST_APP_ENV', 'testing'),
    'debug' => false,
];
