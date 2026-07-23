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
        $tokens = array_slice($arguments, 1);

        if ($tokens === []) {
            return null;
        }

        $requested = $tokens[0];
        $informationOptions = ['--help', '-h', '--version', '-V'];
        $styleOptions = ['--ansi', '--no-ansi'];

        if (!in_array($requested, [...$informationOptions, ...$styleOptions], true)) {
            if (str_starts_with($requested, '-')) {
                throw new ConsoleInputException(
                    "Unknown global option '{$requested}'. Run 'meulah --help' for usage.",
                );
            }

            return null;
        }

        $output->configureAnsi(
            in_array('--no-ansi', $tokens, true)
                ? false
                : (in_array('--ansi', $tokens, true) ? true : null),
        );

        $information = null;

        foreach ($tokens as $token) {
            if (in_array($token, $styleOptions, true)) {
                continue;
            }

            if (in_array($token, $informationOptions, true)) {
                if ($information !== null) {
                    throw new ConsoleInputException('Only one global information option may be used.');
                }

                $information = $token;
                continue;
            }

            if (str_starts_with($token, '-')) {
                throw new ConsoleInputException(
                    "Unknown global option '{$token}'. Run 'meulah --help' for usage.",
                );
            }

            throw new ConsoleInputException("Global options do not accept argument '{$token}'.");
        }

        if ($information === null) {
            throw new ConsoleInputException(
                "Global color options must be used with '--help' or '--version'.",
            );
        }

        if (in_array($information, ['--help', '-h'], true)) {
            $this->renderHelp($output);

            return 0;
        }

        $version = ($this->versionResolver)();

        if (!is_string($version) || trim($version) === '') {
            throw new ConsoleInputException('The framework version could not be resolved.');
        }

        $style = $output->style();
        $output->writeln($style->title('Meulah CLI') . '  ' . $style->version($version));

        return 0;
    }

    private function renderHelp(Output $output): void
    {
        $style = $output->style();

        $output->writeln($style->title('Meulah CLI'));
        $output->writeln();
        $output->writeln($style->heading('Usage:'));
        $output->writeln('  ' . $style->command('meulah') . ' ' . $style->muted('[global option]'));
        $output->writeln('  ' . $style->command('meulah') . ' ' . $style->muted('<command> [arguments] [options]'));
        $output->writeln();
        $output->writeln($style->heading('Global options:'));
        $this->renderOption($output, $style, 2, '-h, --help', 'Show global help.');
        $this->renderOption($output, $style, 2, '-V, --version', 'Show the framework version.');
        $this->renderOption($output, $style, 6, '--ansi', 'Force ANSI colors.');
        $this->renderOption($output, $style, 6, '--no-ansi', 'Disable ANSI colors.');
        $output->writeln();
        $output->writeln($style->muted('Application commands require a Meulah application.'));
        $output->writeln($style->muted('Run them inside an application or set MEULAH_APPLICATION_ROOT.'));
    }

    private function renderOption(
        Output $output,
        ConsoleStyle $style,
        int $indent,
        string $option,
        string $description,
    ): void {
        $spacing = max(2, 17 - $indent - strlen($option));

        $output->writeln(
            str_repeat(' ', $indent)
                . $style->option($option)
                . str_repeat(' ', $spacing)
                . $style->muted($description),
        );
    }
}
