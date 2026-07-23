<?php

declare(strict_types=1);

namespace Meulah\Console;

use Closure;

final class TerminalCapabilities
{
    private readonly Closure $detector;

    public function __construct(?callable $detector = null)
    {
        $this->detector = Closure::fromCallable($detector ?? [self::class, 'detect']);
    }

    public function supportsAnsi(mixed $stream): bool
    {
        return ($this->detector)($stream) === true;
    }

    private static function detect(mixed $stream): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        $interactive = false;

        if (function_exists('stream_isatty')) {
            try {
                $interactive = @stream_isatty($stream);
            } catch (\TypeError) {
                return false;
            }
        } elseif (function_exists('posix_isatty')) {
            try {
                $interactive = @posix_isatty($stream);
            } catch (\TypeError) {
                return false;
            }
        }

        if ($interactive !== true) {
            return false;
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            return true;
        }

        if (!function_exists('sapi_windows_vt100_support')) {
            return false;
        }

        try {
            return @sapi_windows_vt100_support($stream) === true;
        } catch (\TypeError) {
            return false;
        }
    }
}
