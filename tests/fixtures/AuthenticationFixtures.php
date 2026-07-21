<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Meulah\Auth\Authenticatable;
use Meulah\Auth\UserProvider;

final class FakeAuthenticatable implements Authenticatable
{
    public function __construct(private readonly string $identifier)
    {
    }

    public function authIdentifier(): string
    {
        return $this->identifier;
    }
}

final class FakeUserProvider implements UserProvider
{
    /** @var array<string, Authenticatable> */
    private array $users = [];

    /** @var list<string> */
    public array $retrieved = [];

    public function add(Authenticatable $user, ?string $lookupIdentifier = null): void
    {
        $identifier = $lookupIdentifier ?? $user->authIdentifier();
        $this->users[$this->key($identifier)] = $user;
    }

    public function retrieveById(string $identifier): ?Authenticatable
    {
        $this->retrieved[] = $identifier;
        return $this->users[$this->key($identifier)] ?? null;
    }

    private function key(string $identifier): string
    {
        return strlen($identifier) . ':' . $identifier;
    }
}
