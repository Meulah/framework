<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Meulah\Auth\Authenticatable;
use Meulah\Authorization\AuthorizationResult;

final class AuthorizationCallLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class OwnsRecordAbility
{
    public function __construct(private readonly AuthorizationCallLog $log)
    {
    }

    public function __invoke(
        Authenticatable $actor,
        string $ownerIdentifier,
        string $recordIdentifier,
    ): AuthorizationResult {
        $this->log->entries[] = $recordIdentifier;

        return $actor->authIdentifier() === $ownerIdentifier
            ? AuthorizationResult::allow()
            : AuthorizationResult::deny('The record is owned by another user.', 'not_owner');
    }
}
