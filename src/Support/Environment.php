<?php

declare(strict_types=1);

namespace Meulah\Support;

use RuntimeException;

final class Environment
{
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $values = parse_ini_file($file, false, INI_SCANNER_TYPED);

        if ($values === false) {
            throw new RuntimeException("Unable to parse environment file: {$file}");
        }

        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }
}

