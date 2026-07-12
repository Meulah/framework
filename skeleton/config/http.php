<?php

declare(strict_types=1);

use Meulah\Support\Environment;

return [
    'max_body_size' => (int) Environment::get('HTTP_MAX_BODY_SIZE', 10_485_760),
];
