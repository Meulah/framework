<?php

declare(strict_types=1);

namespace Meulah\View;

use RuntimeException;
use Throwable;

final class View
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function render(string $name, array $data = []): string
    {
        if (preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $name) === 1) {
            throw new RuntimeException('View names may not traverse directories.');
        }

        $relativePath = str_replace(['\\\\', '.'], ['/', '/'], trim($name, '/')) . '.php';
        $file = rtrim($this->basePath, '/\\\\') . DIRECTORY_SEPARATOR . $relativePath;

        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$name}");
        }

        $bufferLevel = ob_get_level();
        ob_start();

        try {
            (static function (string $__file, array $__variables): void {
                extract($__variables, EXTR_SKIP);
                require $__file;
            })($file, $data);
            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            throw $exception;
        }
    }
}
