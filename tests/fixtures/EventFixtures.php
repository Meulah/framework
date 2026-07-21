<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class UserRegistered
{
    public function __construct(public readonly string $user)
    {
    }
}

final class EventLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class SendWelcomeEmail
{
    public function __construct(private readonly EventLog $log)
    {
    }

    public function __invoke(UserRegistered $event): void
    {
        $this->log->entries[] = 'welcome:' . $event->user;
    }
}

class ParentEvent
{
}

final class ChildEvent extends ParentEvent
{
}

final class MutableEvent
{
    public string $value = 'initial';
}
