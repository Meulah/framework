<?php

declare(strict_types=1);

namespace Meulah\Session;

use InvalidArgumentException;
use Meulah\Http\Cookie;
use Meulah\Http\SameSite;
use WeakReference;

final class NativeSession implements Session
{
    private const FLASH_KEY = '__meulah_flash';

    private static ?WeakReference $activeOwner = null;

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
        private readonly ?string $domain = null,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $this->name) !== 1) {
            throw new InvalidArgumentException('Native session names must contain only letters and numbers and start with a letter.');
        }

        if (
            $this->path === ''
            || !str_starts_with($this->path, '/')
            || preg_match('/[\x00-\x20\x7F-\xFF;]/', $this->path) === 1
        ) {
            throw new InvalidArgumentException('Invalid native session cookie path.');
        }

        if ($this->domain !== null) {
            $this->assertDomain($this->domain);
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
            if (self::$activeOwner?->get() !== $this) {
                throw new SessionException(
                    "Native session '{$this->name}' is no longer owned by this NativeSession instance.",
                );
            }

            $this->assertActiveConfiguration();
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

            $owner = self::$activeOwner?->get();

            if (!$owner instanceof self) {
                throw new SessionException(sprintf(
                    "Active native session '%s' is not managed by a NativeSession instance.",
                    $this->name,
                ));
            }

            if ($owner !== $this) {
                throw new SessionException(sprintf(
                    "Native session '%s' is already managed by another NativeSession instance.",
                    $this->name,
                ));
            }

            $this->assertActiveConfiguration();
            $this->started = true;
            self::$activeOwner = WeakReference::create($this);
            $this->ageFlashData();
            return;
        }

        $this->assertHeadersAvailable('start');

        if (session_name($this->name) === false) {
            throw new SessionException("Unable to set native session name '{$this->name}'.");
        }

        $cookiePresent = array_key_exists($this->name, $_COOKIE);
        $cookieValue = $cookiePresent ? $_COOKIE[$this->name] : null;
        $currentId = $cookiePresent ? $cookieValue : session_id();
        $restoreCookie = false;

        if (!is_string($currentId) || ($currentId !== '' && preg_match('/^[A-Za-z0-9,-]{1,256}$/', $currentId) !== 1)) {
            if ($cookiePresent) {
                unset($_COOKIE[$this->name]);
                $restoreCookie = true;
            }

            if (session_id('') === false) {
                throw new SessionException('Unable to discard an invalid native session identifier.');
            }
        } elseif ($currentId !== session_id() && session_id($currentId) === false) {
            throw new SessionException('Unable to select the incoming native session identifier.');
        }

        try {
            $started = @session_start([
                'use_cookies' => 1,
                'use_strict_mode' => 1,
                'use_only_cookies' => 1,
                'use_trans_sid' => 0,
                'cookie_lifetime' => $this->lifetime,
                'cookie_domain' => $this->domain ?? '',
                'cookie_path' => $this->path,
                'cookie_secure' => $this->secure ? 1 : 0,
                'cookie_httponly' => $this->httpOnly ? 1 : 0,
                'cookie_samesite' => $this->sameSite->value,
            ]);
        } finally {
            if ($restoreCookie) {
                $_COOKIE[$this->name] = $cookieValue;
            }
        }

        if (!$started || session_status() !== PHP_SESSION_ACTIVE) {
            throw $this->nativeFailure('Unable to start the native PHP session.');
        }

        $this->started = true;
        self::$activeOwner = WeakReference::create($this);
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

        $this->assertHeadersAvailable('regenerate');
        if (!@session_regenerate_id(true)) {
            throw $this->nativeFailure('Unable to regenerate the native session identifier.');
        }
    }

    public function invalidate(): void
    {
        $this->start();
        $this->assertHeadersAvailable('invalidate');
        $_SESSION = [];

        if (!@session_regenerate_id(true)) {
            throw $this->nativeFailure('Unable to invalidate the native session.');

        }
        header('Set-Cookie: ' . Cookie::forget(
            name: $this->name,
            path: $this->path,
            secure: $this->secure,
            httpOnly: $this->httpOnly,
            sameSite: $this->sameSite,
            domain: $this->domain,
        )->toHeader(), false);

        $this->flashAged = true;
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

    public function keep(string ...$keys): void
    {
        $this->start();
        $metadata = $this->flashMetadata();

        foreach ($keys as $key) {
            $this->assertKey($key);

            if (!in_array($key, $metadata['old'], true)) {
                continue;
            }

            $metadata['old'] = array_values(array_diff($metadata['old'], [$key]));

            if (!in_array($key, $metadata['new'], true)) {
                $metadata['new'][] = $key;
            }
        }

        $_SESSION[self::FLASH_KEY] = $metadata;
    }

    public function reflash(): void
    {
        $this->start();
        $metadata = $this->flashMetadata();
        $metadata['new'] = array_values(array_unique([...$metadata['new'], ...$metadata['old']]));
        $metadata['old'] = [];
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
            if (!@session_write_close()) {
                throw $this->nativeFailure('Unable to close the native PHP session.');
            }
        }

        if ($this->started) {
            $_SESSION = [];

            if (@session_id('') === false) {
                throw $this->nativeFailure('Unable to clear the native session identifier.');
            }

        }
        $owner = self::$activeOwner?->get();
        if ($owner === $this) {
            self::$activeOwner = null;
        }

        $this->started = false;
        $this->flashAged = false;
    }

    private function assertActiveConfiguration(): void
    {
        $parameters = session_get_cookie_params();
        $sameSite = (string) ($parameters['samesite'] ?? '');

        if (
            (int) $parameters['lifetime'] === $this->lifetime
            && (string) $parameters['path'] === $this->path
            && (string) $parameters['domain'] === ($this->domain ?? '')
            && (bool) $parameters['secure'] === $this->secure
            && (bool) $parameters['httponly'] === $this->httpOnly
            && strcasecmp($sameSite, $this->sameSite->value) === 0
            && (bool) ini_get('session.use_strict_mode')
            && (bool) ini_get('session.use_cookies')
            && (bool) ini_get('session.use_only_cookies')
            && !(bool) ini_get('session.use_trans_sid')
        ) {
            return;
        }

        throw new SessionException(sprintf(
            "Active native session '%s' has different cookie configuration.",
            $this->name,
        ));
    }

    private function nativeFailure(string $message): SessionException
    {
        return new SessionException($message);
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

    private function assertHeadersAvailable(string $operation): void
    {
        if (headers_sent()) {
            throw new SessionException("Cannot {$operation} the native PHP session after response output has started.");
        }
    }

    private function assertDomain(string $domain): void
    {
        if (
            $domain === ''
            || strlen($domain) > 253
            || preg_match('/[\x00-\x20\x7F;,\x80-\xFF]/', $domain) === 1
        ) {
            throw new InvalidArgumentException('Invalid native session cookie domain.');
        }

        foreach (explode('.', $domain) as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $label) !== 1
            ) {
                throw new InvalidArgumentException('Invalid native session cookie domain.');
            }
        }
    }
}
