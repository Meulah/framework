<?php

declare(strict_types=1);

namespace Meulah\Console;

use Meulah\Support\Environment;
use RuntimeException;

final class ProjectRoot
{
    public static function discover(?string $start = null): string
    {
        $configured = Environment::get('MEULAH_APPLICATION_ROOT');

        if (is_string($configured) && trim($configured) !== '') {
            return self::explicit($configured);
        }

        $start ??= getcwd() ?: '';
        $directory = self::realDirectory($start);

        $discovered = self::walkUp($directory);

        if ($discovered !== null) {
            return $discovered;
        }

        $installedApplication = dirname(__DIR__, 5);

        if (self::isApplication($installedApplication)) {
            return realpath($installedApplication) ?: $installedApplication;
        }

        throw new RuntimeException(
            'No Meulah application was found. Run the command inside an application or set MEULAH_APPLICATION_ROOT.',
        );
    }

    public static function explicit(string $root): string
    {
        $directory = self::realDirectory($root);

        if (!self::isApplication($directory)) {
            throw new RuntimeException("Directory is not a marked Meulah application: {$directory}");
        }

        return $directory;
    }

    private static function realDirectory(string $path): string
    {
        $directory = realpath($path);

        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException("Application directory does not exist: {$path}");
        }

        return $directory;
    }

    private static function walkUp(string $directory): ?string
    {
        while (true) {
            if (self::isApplication($directory)) {
                return $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }

    private static function isApplication(string $directory): bool
    {
        if (
            !is_file($directory . '/composer.json')
            || !is_file($directory . '/bootstrap.php')
            || !is_dir($directory . '/config')
            || !is_dir($directory . '/routes')
        ) {
            return false;
        }

        $contents = file_get_contents($directory . '/composer.json');
        $composer = $contents === false ? null : json_decode($contents, true);

        return is_array($composer)
            && ($composer['extra']['meulah']['application'] ?? false) === true;
    }
}
