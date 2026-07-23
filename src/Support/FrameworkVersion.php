<?php

declare(strict_types=1);

namespace Meulah\Support;

use Composer\InstalledVersions;
use RuntimeException;

final class FrameworkVersion
{
    private const PACKAGE = 'meulah/framework';

    public static function current(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            throw new RuntimeException('Composer runtime version metadata is unavailable.');
        }

        $version = InstalledVersions::getPrettyVersion(self::PACKAGE);

        if (!is_string($version) || trim($version) === '') {
            throw new RuntimeException('Composer could not resolve the Meulah framework version.');
        }

        return $version;
    }

    private function __construct()
    {
    }
}
