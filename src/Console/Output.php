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

        $this->writeTo($this->stdout ?? STDOUT, $message, 'standard output');
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

        $this->writeTo($this->stderr ?? STDERR, $message, 'error output');
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

    private function writeTo(mixed $stream, string $message, string $destination): void
    {
        $length = strlen($message);
        $offset = 0;

        while ($offset < $length) {
            try {
                $written = @fwrite($stream, substr($message, $offset));
            } catch (\TypeError $exception) {
                throw new OutputException(
                    "Unable to write to console {$destination}; the configured stream is invalid.",
                    0,
                    $exception,
                );
            }

            if ($written === false || $written === 0) {
                throw new OutputException("Unable to write to console {$destination}.");
            }

            $offset += $written;
        }
    }
}
