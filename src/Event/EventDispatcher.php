<?php

declare(strict_types=1);

namespace Meulah\Event;

interface EventDispatcher
{
    public function listen(string $event, callable|string $listener): void;

    public function dispatch(object $event): object;
}
