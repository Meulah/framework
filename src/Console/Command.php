<?php

declare(strict_types=1);

namespace Meulah\Console;

interface Command
{
    public function name(): string;

    public function description(): string;

    /** @return list<string> */
    public function aliases(): array;

    public function execute(Input $input, Output $output): int;
}
