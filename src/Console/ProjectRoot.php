<?php

declare(strict_types=1);

namespace Meulah\Console;

use Meulah\Support\Environment;
use RuntimeException;

final class ProjectRoot
{
    /** @var array<string, 'file'|'directory'> */
    private const REQUIRED_MARKERS = [
        'composer.json' => 'file',
        'start/app.php' => 'file',
        'settings/' => 'directory',
        'routes/' => 'directory',
    ];

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

        throw new RuntimeException(self::notFoundMessage());
    }

    public static function explicit(string $root): string
    {
        $directory = self::realDirectory($root);
        $missing = self::missingMarkers($directory);
        $marked = self::isMarkedApplication($directory);

        if ($missing !== [] || !$marked) {
            throw new RuntimeException(self::invalidRootMessage($missing, $marked));
        }

        return $directory;
    }

    private static function realDirectory(string $path): string
    {
        $directory = realpath($path);

        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('The supplied application root does not exist or is not a directory.');
        }

        return $directory;
    }

    private static function walkUp(string $directory): ?string
    {
        while (true) {
            $missing = self::missingMarkers($directory);
            $marked = self::isMarkedApplication($directory);

            if ($missing === [] && $marked) {
                return $directory;
            }

            if ($marked) {
                throw new RuntimeException(self::invalidRootMessage(
                    $missing,
                    true,
                    'No Meulah application was found.',
                ));
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }

    /** @return list<string> */
    private static function missingMarkers(string $directory): array
    {
        $missing = [];

        foreach (self::REQUIRED_MARKERS as $marker => $type) {
            $path = $directory . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, rtrim($marker, '/'));
            $exists = $type === 'file' ? is_file($path) : is_dir($path);

            if (!$exists) {
                $missing[] = $marker;
            }
        }

        return $missing;
    }

    private static function isMarkedApplication(string $directory): bool
    {
        $composerFile = $directory . DIRECTORY_SEPARATOR . 'composer.json';
        $contents = is_file($composerFile) ? file_get_contents($composerFile) : false;
        $composer = $contents === false ? null : json_decode($contents, true);

        return is_array($composer)
            && ($composer['extra']['meulah']['application'] ?? false) === true;
    }

    /** @param list<string> $missing */
    private static function invalidRootMessage(
        array $missing,
        bool $marked,
        string $heading = 'The supplied application root is not a valid Meulah application.',
    ): string
    {
        $lines = [$heading, ''];

        if ($missing !== []) {
            $lines[] = 'Missing required markers:';

            foreach ($missing as $marker) {
                $lines[] = "- {$marker}";
            }
        }

        if (!$marked) {
            if ($missing !== []) {
                $lines[] = '';
            }

            $lines[] = 'composer.json must declare extra.meulah.application as true.';
        }

        return implode(PHP_EOL, $lines);
    }

    private static function notFoundMessage(): string
    {
        $lines = [
            'No Meulah application was found.',
            '',
            'Expected a Meulah application containing:',
        ];

        foreach (array_keys(self::REQUIRED_MARKERS) as $marker) {
            $lines[] = "- {$marker}";
        }

        $lines[] = '';
        $lines[] = 'composer.json must declare extra.meulah.application as true.';
        $lines[] = 'Run this command inside a Meulah application or set MEULAH_APPLICATION_ROOT.';

        return implode(PHP_EOL, $lines);
    }
}
