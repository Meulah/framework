<?php

declare(strict_types=1);

namespace Meulah\Session;

use InvalidArgumentException;
use Meulah\Http\SameSite;

final class NativeSession implements Session
{
    private const FLASH_KEY = '__meulah_flash';

    private bool $started = false;
    private bool $flashAged = false;
    private readonly SameSite $sameSite;

    public function __construct(
        private readonly string $name = 'MEULAHSESSID',
        private readonly bool $secure = true,
        private readonly bool $httpOnly = true,
        SameSite|string $sameSite = SameSite::Lax,
        private readonly string $path = '/',
        private readonly int $lifetime = 0,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $this->name) !== 1) {
            throw new InvalidArgumentException('Native session names must contain only letters and numbers and start with a letter.');
        }

        if ($this->path === '' || preg_match('/[\x00-\x1F\x7F;]/', $this->path) === 1) {
            throw new InvalidArgumentException('Invalid native session cookie path.');
        }

        if ($this->lifetime < 0) {
            throw new InvalidArgumentException('Native session lifetime cannot be negative.');
        }

        if (is_string($sameSite)) {
            $sameSite = SameSite::tryFrom($sameSite)
                ?? throw new InvalidArgumentException("Unsupported SameSite value: {$sameSite}");
        }

        if ($sameSite === SameSite::None && !$this->secure) {
            throw new InvalidArgumentException('SameSite=None sessions must use Secure cookies.');
        }

        $this->sameSite = $sameSite;
    }

    public function start(): void
    {
        if ($this->started && session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== $this->name) {
                throw new SessionException(sprintf(
                    "Cannot use session '%s' while native session '%s' is active.",
                    $this->name,
                    session_name(),
                ));
            }

            $this->started = true;
            $this->ageFlashData();
            return;
        }

        if (headers_sent($file, $line)) {
            throw new SessionException("Cannot start a session after output was sent at {$file}:{$line}.");
        }

        if (session_name($this->name) === false) {
            throw new SessionException("Unable to set native session name '{$this->name}'.");
        }

        $started = @session_start([
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'use_trans_sid' => 0,
            'cookie_lifetime' => $this->lifetime,
            'cookie_path' => $this->path,
            'cookie_secure' => $this->secure ? 1 : 0,
            'cookie_httponly' => $this->httpOnly ? 1 : 0,
            'cookie_samesite' => $this->sameSite->value,
        ]);

        if (!$started || session_status() !== PHP_SESSION_ACTIVE) {
            throw new SessionException('Unable to start the native PHP session.');
        }

        $this->started = true;
        $this->ageFlashData();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);
        $this->start();

        return array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->assertKey($key);
        $this->start();
        $_SESSION[$key] = $value;
        $this->removeFlashMarker($key);
    }

    public function remove(string $key): void
    {
        $this->assertKey($key);
        $this->start();
        unset($_SESSION[$key]);
        $this->removeFlashMarker($key);
    }

    public function regenerate(): void
    {
        $this->start();

        if (!@session_regenerate_id(true)) {
            throw new SessionException('Unable to regenerate the native session identifier.');
        }
    }

    public function invalidate(): void
    {
        $this->start();
        $_SESSION = [];

        if (!@session_regenerate_id(true)) {
            throw new SessionException('Unable to invalidate the native session.');
        }
    }

    public function flash(string $key, mixed $value): void
    {
        $this->assertKey($key);
        $this->start();
        $_SESSION[$key] = $value;
        $metadata = $this->flashMetadata();
        $metadata['old'] = array_values(array_diff($metadata['old'], [$key]));

        if (!in_array($key, $metadata['new'], true)) {
            $metadata['new'][] = $key;
        }

        $_SESSION[self::FLASH_KEY] = $metadata;
    }

    public function id(): string
    {
        $this->start();
        return session_id();
    }

    public function isStarted(): bool
    {
        return $this->started && session_status() === PHP_SESSION_ACTIVE;
    }

    public function close(): void
    {
        if ($this->started && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->started = false;
        $this->flashAged = false;
    }

    private function assertKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Session keys cannot be empty.');
        }

        if ($key === self::FLASH_KEY) {
            throw new InvalidArgumentException("Session key '{$key}' is reserved by Meulah.");
        }
    }

    private function ageFlashData(): void
    {
        if ($this->flashAged) {
            return;
        }

        $metadata = $this->flashMetadata();

        foreach ($metadata['old'] as $key) {
            unset($_SESSION[$key]);
        }

        $_SESSION[self::FLASH_KEY] = [
            'old' => $metadata['new'],
            'new' => [],
        ];
        $this->flashAged = true;
    }

    /** @return array{old: list<string>, new: list<string>} */
    private function flashMetadata(): array
    {
        $metadata = $_SESSION[self::FLASH_KEY] ?? null;

        if (!is_array($metadata)) {
            return ['old' => [], 'new' => []];
        }

        return [
            'old' => $this->stringList($metadata['old'] ?? []),
            'new' => $this->stringList($metadata['new'] ?? []),
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter($value, 'is_string')));
    }

    private function removeFlashMarker(string $key): void
    {
        $metadata = $this->flashMetadata();
        $metadata['old'] = array_values(array_diff($metadata['old'], [$key]));
        $metadata['new'] = array_values(array_diff($metadata['new'], [$key]));
        $_SESSION[self::FLASH_KEY] = $metadata;
    }
}
