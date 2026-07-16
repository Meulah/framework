<?php

declare(strict_types=1);

namespace Meulah\Console;

final class Output
{
    private string $capturedOutput = '';
    private string $capturedErrorOutput = '';

    public function __construct(
        private readonly bool $buffered = false,
        private readonly mixed $stdout = null,
        private readonly mixed $stderr = null,
    ) {
    }

    public static function buffered(): self
    {
        return new self(true);
    }

    public function write(string $message): void
    {
        if ($this->buffered) {
            $this->capturedOutput .= $message;
            return;
        }

        fwrite($this->stdout ?? STDOUT, $message);
    }

    public function writeln(string $message = ''): void
    {
        $this->write($message . PHP_EOL);
    }

    public function error(string $message): void
    {
        if ($this->buffered) {
            $this->capturedErrorOutput .= $message;
            return;
        }

        fwrite($this->stderr ?? STDERR, $message);
    }

    public function errorln(string $message = ''): void
    {
        $this->error($message . PHP_EOL);
    }

    public function output(): string
    {
        return $this->capturedOutput;
    }

    public function errorOutput(): string
    {
        return $this->capturedErrorOutput;
    }
}
