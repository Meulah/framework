<?php

declare(strict_types=1);

namespace Meulah\Console;

use Throwable;

final class ConsoleApplication
{
    private readonly CommandRegistry $commands;
    private readonly Output $output;

    public function __construct(
        ?CommandRegistry $commands = null,
        ?Output $output = null,
    ) {
        $this->commands = $commands ?? new CommandRegistry();
        $this->output = $output ?? new Output();
    }

    public function add(Command $command): void
    {
        $this->commands->add($command);
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $requested = $arguments[1] ?? 'list';

        try {
            if (in_array($requested, ['list', '--help', '-h'], true)) {
                $this->assertGlobalArgumentCount($arguments, 2, $requested);

                return $this->renderList();
            }

            if ($requested === 'help') {
                $commandName = $arguments[2] ?? null;
                $this->assertGlobalArgumentCount($arguments, $commandName === null ? 2 : 3, 'help');

                return $commandName === null
                    ? $this->renderList()
                    : $this->renderCommandHelp($commandName);
            }

            $command = $this->commands->get($requested);

            if ($command === null) {
                return $this->renderUnknownCommand($requested);
            }

            $tokens = array_slice($arguments, 2);
            $input = Input::fromTokens($requested, $tokens);

            if ($input->hasOption('help') || in_array('-h', $input->arguments(), true)) {
                $input->assertOnlyOptions(
                    $input->hasOption('help') ? ['help'] : [],
                );

                if ($input->hasOption('help') && $input->option('help') !== true) {
                    throw new ConsoleInputException(
                        "Option '--help' is a flag and does not accept a value.",
                    );
                }

                return $this->renderHelp($command);
            }

            return $command->execute($input, $this->output);
        } catch (Throwable $exception) {
            $this->output->errorln(
                'Error: ' . $this->output->errorStyle()->error($exception->getMessage()),
            );
            return 1;
        }
    }

    private function renderList(): int
    {
        $commands = $this->commands->commands();
        $names = array_map(static fn (Command $command): string => $command->name(), $commands);
        $width = max([4, ...array_map('strlen', $names)]);

        $this->output->writeln('Meulah CLI');
        $this->output->writeln();
        $this->output->writeln('Usage:');
        $this->output->writeln('  php meulah <command> [arguments] [options]');
        $this->output->writeln();
        $this->output->writeln('Commands:');
        $this->output->writeln(sprintf("  %-{$width}s  %s", 'help', 'Show help for a command.'));
        $this->output->writeln(sprintf("  %-{$width}s  %s", 'list', 'List available commands.'));

        foreach ($commands as $command) {
            $this->output->writeln(sprintf(
                "  %-{$width}s  %s",
                $command->name(),
                $command->description(),
            ));
        }

        return 0;
    }

    private function renderCommandHelp(string $name): int
    {
        $command = $this->commands->get($name);

        return $command === null
            ? $this->renderUnknownCommand($name)
            : $this->renderHelp($command);
    }

    private function renderHelp(Command $command): int
    {
        $this->output->writeln($command->name());
        $this->output->writeln();
        $this->output->writeln($command->description());
        $this->output->writeln();
        $this->output->writeln('Usage:');
        $this->output->writeln('  php meulah ' . $command->name() . ' [arguments] [options]');

        if ($command->aliases() !== []) {
            $this->output->writeln();
            $this->output->writeln('Aliases: ' . implode(', ', $command->aliases()));
        }

        $this->output->writeln();
        $this->output->writeln('Options:');
        $this->output->writeln('  --help  Show this command help.');

        return 0;
    }

    private function renderUnknownCommand(string $name): int
    {
        $this->output->errorln($this->output->errorStyle()->error(
            "Command '{$name}' is not defined.",
        ));
        $suggestions = $this->commands->suggestions($name);

        if (count($suggestions) === 1) {
            $this->output->errorln("Did you mean '{$suggestions[0]}'?");
        } elseif ($suggestions !== []) {
            $this->output->errorln('Did you mean one of these?');

            foreach ($suggestions as $suggestion) {
                $this->output->errorln("  {$suggestion}");
            }
        }

        return 1;
    }

    /** @param list<string> $arguments */
    private function assertGlobalArgumentCount(array $arguments, int $maximum, string $command): void
    {
        $provided = count($arguments) - $maximum;

        if (count($arguments) > $maximum) {
            throw new ConsoleInputException(sprintf(
                "Command '%s' does not accept %d additional argument%s.",
                $command,
                $provided,
                $provided === 1 ? '' : 's',
            ));
        }
    }
}
