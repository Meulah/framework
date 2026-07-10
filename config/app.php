<?php

declare(strict_types=1);

$environment = (string) ($_ENV['APP_ENV'] ?? 'production');

return [
    'environment' => $environment,
    'debug' => $environment === 'development',
];

