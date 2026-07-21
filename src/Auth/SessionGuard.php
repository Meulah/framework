<?php

declare(strict_types=1);

namespace Meulah\Auth;

use InvalidArgumentException;
use Meulah\Session\Session;
use stdClass;

final class SessionGuard implements Guard
{
    private bool $resolved = false;
    private ?string $resolvedForSession = null;
    private ?Authenticatable $resolvedUser = null;
    private ?string $resolvedIdentifier = null;

    public function __construct(
        private readonly Session $session,
        private readonly UserProvider $users,
        private readonly string $sessionKey,
    ) {
        if ($this->sessionKey === '') {
            throw new InvalidArgumentException('The authentication session key cannot be empty.');
        }
    }

    public function user(): ?Authenticatable
    {
        $sessionId = $this->session->id();

        if ($this->resolved && $this->resolvedForSession === $sessionId) {
            return $this->resolvedUser;
        }

        $missing = new stdClass();
        $stored = $this->session->get($this->sessionKey, $missing);

        if ($stored === $missing) {
            $this->remember(null, null, $sessionId);
            return null;
        }

        $identifier = $this->identifier($stored, 'Stored');
        $user = $this->users->retrieveById($identifier);

        if ($user === null) {
            $this->remember(null, null, $sessionId);
            return null;
        }

        $resolvedIdentifier = $this->identifier($user->authIdentifier(), 'Resolved');

        if ($resolvedIdentifier !== $identifier) {
            throw new InvalidAuthenticatableException(
                'The user provider returned an Authenticatable with a different authentication identifier.',
            );
        }

        $this->remember($user, $identifier, $sessionId);
        return $user;
    }

    public function id(): ?string
    {
        $this->user();
        return $this->resolvedIdentifier;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function login(Authenticatable $user): void
    {
        $identifier = $this->identifier($user->authIdentifier(), 'Authenticatable');
        $this->forgetResolvedUser();
        $this->session->regenerate();
        $this->session->put($this->sessionKey, $identifier);
        $this->remember($user, $identifier, $this->session->id());
    }

    public function logout(): void
    {
        $this->forgetResolvedUser();
        $this->session->remove($this->sessionKey);
        $this->session->regenerate();
        $this->remember(null, null, $this->session->id());
    }

    private function forgetResolvedUser(): void
    {
        $this->resolved = false;
        $this->resolvedForSession = null;
        $this->resolvedUser = null;
        $this->resolvedIdentifier = null;
    }

    private function identifier(mixed $identifier, string $source): string
    {
        if (!is_string($identifier) || $identifier === '') {
            throw new InvalidAuthenticatableException(sprintf(
                '%s authentication identifiers must be non-empty strings.',
                $source,
            ));
        }

        return $identifier;
    }

    private function remember(
        ?Authenticatable $user,
        ?string $identifier,
        string $sessionId,
    ): void {
        $this->resolvedUser = $user;
        $this->resolvedIdentifier = $identifier;
        $this->resolvedForSession = $sessionId;
        $this->resolved = true;
    }
}
