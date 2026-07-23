<?php

declare(strict_types=1);

namespace Meulah\Console;

use Closure;
use Meulah\Console\Commands\MigrationCommands;
use Meulah\Support\FrameworkVersion;
use RuntimeException;
use Throwable;

final class Launcher
{
    private readonly Output $output;
    private readonly Closure $rootDiscovery;
    private readonly GlobalOptions $globalOptions;

    public function __construct(
        ?Output $output = null,
        ?callable $rootDiscovery = null,
        ?callable $versionResolver = null,
    ) {
        $this->output = $output ?? new Output();
        $this->rootDiscovery = Closure::fromCallable(
            $rootDiscovery ?? [ProjectRoot::class, 'discover'],
        );
        $this->globalOptions = new GlobalOptions(
            $versionResolver ?? [FrameworkVersion::class, 'current'],
        );
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $globalExitCode = $this->globalOptions->handle($arguments, $this->output);

            if ($globalExitCode !== null) {
                return $globalExitCode;
            }

            $requested = $arguments[1] ?? 'list';

            if (!$this->isApplicationCommand($requested)) {
                return $this->renderUnknownCommand($requested);
            }

            return $this->application()->run($arguments);
        } catch (Throwable $exception) {
            $this->output->errorln(
                'Error: ' . $this->output->errorStyle()->error($exception->getMessage()),
            );

            return 1;
        }
    }

    private function renderUnknownCommand(string $requested): int
    {
        $this->output->errorln($this->output->errorStyle()->error(
            "Command '{$requested}' is not defined.",
        ));
        $suggestions = $this->suggestions($requested);

        if (count($suggestions) === 1) {
            $this->output->errorln("Did you mean '{$suggestions[0]}'?");
        } elseif ($suggestions !== []) {
            $this->output->errorln('Did you mean one of these?');

            foreach ($suggestions as $suggestion) {
                $this->output->errorln("  {$suggestion}");
            }
        }

        $this->output->errorln("Run 'meulah --help' for global usage.");

        return 1;
    }

    private function application(): Application
    {
        $root = ($this->rootDiscovery)();

        if (!is_string($root) || trim($root) === '') {
            throw new RuntimeException('Application-root discovery returned an invalid path.');
        }

        return new Application($root, $this->output);
    }

    private function isApplicationCommand(string $requested): bool
    {
        return in_array($requested, $this->applicationCommandNames(), true);
    }

    /** @return list<string> */
    private function applicationCommandNames(): array
    {
        return ['list', 'help', ...MigrationCommands::names()];
    }

    /** @return list<string> */
    private function suggestions(string $unknown): array
    {
        $matches = [];

        foreach ($this->applicationCommandNames() as $name) {
            $distance = levenshtein($unknown, $name);
            $threshold = max(2, (int) floor(strlen($name) * 0.4));

            if ($distance <= $threshold) {
                $matches[$name] = $distance;
            }
        }

        uksort($matches, static function (string $left, string $right) use ($matches): int {
            $distance = $matches[$left] <=> $matches[$right];

            return $distance === 0 ? strcmp($left, $right) : $distance;
        });

        return array_slice(array_keys($matches), 0, 3);
    }
}
