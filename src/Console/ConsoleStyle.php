<?php

declare(strict_types=1);

namespace Meulah\Console;

final class ConsoleStyle
{
    private const RESET = "\033[0m";
    private const BOLD_CYAN = '1;36';
    private const GREEN = '32';
    private const YELLOW = '33';
    private const RED = '31';
    private const MUTED = '90';

    public function __construct(private readonly bool $enabled)
    {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function title(string $text): string
    {
        return $this->apply(self::BOLD_CYAN, $text);
    }

    public function heading(string $text): string
    {
        return $this->apply(self::BOLD_CYAN, $text);
    }

    public function command(string $text): string
    {
        return $this->apply(self::GREEN, $text);
    }

    public function option(string $text): string
    {
        return $this->apply(self::YELLOW, $text);
    }

    public function muted(string $text): string
    {
        return $this->apply(self::MUTED, $text);
    }

    public function success(string $text): string
    {
        return $this->apply(self::GREEN, $text);
    }

    public function warning(string $text): string
    {
        return $this->apply(self::YELLOW, $text);
    }

    public function error(string $text): string
    {
        return $this->apply(self::RED, $text);
    }

    public function version(string $text): string
    {
        return $this->apply(self::YELLOW, $text);
    }

    private function apply(string $code, string $text): string
    {
        if (!$this->enabled || $text === '') {
            return $text;
        }

        return "\033[{$code}m{$text}" . self::RESET;
    }
}
