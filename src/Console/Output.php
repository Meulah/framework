<?php

declare(strict_types=1);

namespace Meulah\Console;

use Closure;
use Meulah\Support\Environment;

final class Output
{
    private string $capturedOutput = '';
    private string $capturedErrorOutput = '';
    private readonly TerminalCapabilities $terminal;
    private readonly Closure $environment;
    private ?bool $ansiOverride = null;

    public function __construct(
        private readonly bool $buffered = false,
        private readonly mixed $stdout = null,
        private readonly mixed $stderr = null,
        ?TerminalCapabilities $terminal = null,
        ?callable $environment = null,
    ) {
        $this->terminal = $terminal ?? new TerminalCapabilities();
        $this->environment = Closure::fromCallable(
            $environment ?? static fn (string $key): mixed => Environment::get($key),
        );
    }

    public static function buffered(): self
    {
        return new self(true);
    }

    public function configureAnsi(?bool $enabled): void
    {
        $this->ansiOverride = $enabled;
    }

    public function style(): ConsoleStyle
    {
        $stream = $this->buffered ? $this->stdout : ($this->stdout ?? STDOUT);

        return new ConsoleStyle($this->ansiEnabled($stream));
    }

    public function errorStyle(): ConsoleStyle
    {
        $stream = $this->buffered ? $this->stderr : ($this->stderr ?? STDERR);

        return new ConsoleStyle($this->ansiEnabled($stream));
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

    private function ansiEnabled(mixed $stream): bool
    {
        if ($this->ansiOverride !== null) {
            return $this->ansiOverride;
        }

        if (
            ($this->environment)('NO_COLOR') !== null
            || ($this->environment)('CI') !== null
        ) {
            return false;
        }

        return $this->terminal->supportsAnsi($stream);
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
