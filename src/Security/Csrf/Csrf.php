<?php

declare(strict_types=1);

namespace Meulah\Security\Csrf;

use RuntimeException;
use Meulah\Session\Session;

final class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';
    public const TOKEN_BYTES = 32;
    public const TOKEN_LENGTH = self::TOKEN_BYTES * 2;

    private const TOKEN_KEY = '__meulah_csrf_token';
    private const SESSION_KEY = '__meulah_csrf_session';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $fingerprint = $this->sessionFingerprint();
        $token = $this->session->get(self::TOKEN_KEY);
        $storedFingerprint = $this->session->get(self::SESSION_KEY);

        if (
            $this->hasTokenShape($token)
            && $this->hasTokenShape($storedFingerprint)
            && hash_equals($storedFingerprint, $fingerprint)
        ) {
            return $token;
        }

        return $this->regenerate();
    }

    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->session->put(self::TOKEN_KEY, $token);
        $this->session->put(self::SESSION_KEY, $this->sessionFingerprint());

        return $token;
    }

    public function isValid(mixed $token): bool
    {
        if (!$this->hasTokenShape($token)) {
            return false;
        }

        $fingerprint = $this->sessionFingerprint();
        $storedToken = $this->session->get(self::TOKEN_KEY);
        $storedFingerprint = $this->session->get(self::SESSION_KEY);

        return $this->hasTokenShape($storedToken)
            && $this->hasTokenShape($storedFingerprint)
            && hash_equals($storedFingerprint, $fingerprint)
            && hash_equals($storedToken, $token);
    }

    public function field(): string
    {
        $token = htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<input type="hidden" name="' . self::FIELD . '" value="' . $token . '">';
    }

    private function sessionFingerprint(): string
    {
        $id = $this->session->id();

        if ($id === '') {
            throw new RuntimeException('Cannot create a CSRF token without a session identifier.');
        }

        return hash('sha256', $id);
    }

    private function hasTokenShape(mixed $token): bool
    {
        return is_string($token)
            && strlen($token) === self::TOKEN_LENGTH
            && preg_match('/^[a-f0-9]{' . self::TOKEN_LENGTH . '}$/D', $token) === 1;
    }
}
