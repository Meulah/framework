<?php

declare(strict_types=1);

use Meulah\Support\Environment;

$environment = (string) Environment::get('APP_ENV', 'production');

return [
    'name' => (string) Environment::get('APP_NAME', 'Meulah'),
    'environment' => $environment,
    'debug' => $environment === 'development',
];
