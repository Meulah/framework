<?php

declare(strict_types=1);

namespace Meulah\Console;

use Closure;

final class GlobalOptions
{
    private readonly Closure $versionResolver;

    public function __construct(callable $versionResolver)
    {
        $this->versionResolver = Closure::fromCallable($versionResolver);
    }

    /**
     * Handle an option that does not require an application.
     *
     * A null result means that the first token is an application command.
     *
     * @param list<string> $arguments
     */
    public function handle(array $arguments, Output $output): ?int
    {
        $requested = $arguments[1] ?? null;

        if (in_array($requested, ['--help', '-h'], true)) {
            $this->assertArgumentCount($arguments, $requested);
            $this->renderHelp($output);

            return 0;
        }

        if (in_array($requested, ['--version', '-V'], true)) {
            $this->assertArgumentCount($arguments, $requested);
            $version = ($this->versionResolver)();

            if (!is_string($version) || trim($version) === '') {
                throw new ConsoleInputException('The framework version could not be resolved.');
            }

            $output->writeln('Meulah CLI ' . $version);

            return 0;
        }

        if (is_string($requested) && str_starts_with($requested, '-')) {
            throw new ConsoleInputException(
                "Unknown global option '{$requested}'. Run 'meulah --help' for usage.",
            );
        }

        return null;
    }

    private function renderHelp(Output $output): void
    {
        $output->writeln('Meulah CLI');
        $output->writeln();
        $output->writeln('Usage:');
        $output->writeln('  meulah [global option]');
        $output->writeln('  meulah <command> [arguments] [options]');
        $output->writeln();
        $output->writeln('Global options:');
        $output->writeln('  -h, --help     Show global help.');
        $output->writeln('  -V, --version  Show the framework version.');
        $output->writeln();
        $output->writeln('Application commands require a Meulah application. Run them inside an application');
        $output->writeln('or set MEULAH_APPLICATION_ROOT.');
    }

    /** @param list<string> $arguments */
    private function assertArgumentCount(array $arguments, string $option): void
    {
        if (count($arguments) > 2) {
            throw new ConsoleInputException("Global option '{$option}' does not accept arguments.");
        }
    }
}
