<?php

declare(strict_types=1);

spl_autoload_register(static function (string $className): void {
    $prefix = 'Meulah\\';

    if (!str_starts_with($className, $prefix)) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

