<?php

declare(strict_types=1);

use Meulah\Support\Environment;

$environment = (string) Environment::get('APP_ENV', 'production');

return [
    'environment' => $environment,
    'debug' => $environment === 'development',
];
