<?php

declare(strict_types=1);

namespace Meulah\Auth;

use InvalidArgumentException;
use ValueError;

final class NativePasswordHasher implements PasswordHasher
{
    /** @var array<string, int> */
    private readonly array $options;

    private readonly string|int $algorithm;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        string|int $algorithm = PASSWORD_DEFAULT,
        array $options = [],
    ) {
        $this->assertSupportedAlgorithm($algorithm);
        $this->assertValidOptions($algorithm, $options);

        $this->algorithm = $algorithm;
        $this->options = $options;
    }

    public function hash(string $plainText): string
    {
        try {
            $hash = @password_hash($plainText, $this->algorithm, $this->options);
        } catch (ValueError $exception) {
            throw new PasswordHashingException('Unable to hash the supplied password.');
        }

        if (!is_string($hash) || $hash === '') {
            throw new PasswordHashingException('Unable to hash the supplied password.');
        }

        return $hash;
    }

    public function verify(string $plainText, string $hash): bool
    {
        if (!$this->isRecognizedHash($hash)) {
            return false;
        }

        try {
            return password_verify($plainText, $hash);
        } catch (ValueError $exception) {
            return false;
        }
    }

    public function needsRehash(string $hash): bool
    {
        if (!$this->isRecognizedHash($hash)) {
            return true;
        }

        try {
            return password_needs_rehash($hash, $this->algorithm, $this->options);
        } catch (ValueError $exception) {
            return true;
        }
    }

    private function assertSupportedAlgorithm(string|int $algorithm): void
    {
        $supported = [PASSWORD_DEFAULT];
        $argon2id = $this->argon2id();

        if ($argon2id !== null) {
            $supported[] = $argon2id;
        }

        if (!in_array($algorithm, $supported, true)) {
            throw new InvalidArgumentException('Unsupported password hashing algorithm.');
        }
    }

    /** @param array<string, mixed> $options */
    private function assertValidOptions(string|int $algorithm, array $options): void
    {
        $argon2id = $this->argon2id();

        if ($argon2id !== null && $algorithm === $argon2id) {
            $this->assertIntegerOptions($options, [
                'memory_cost' => 8,
                'time_cost' => 1,
                'threads' => 1,
            ]);
            return;
        }

        if (defined('PASSWORD_BCRYPT') && $algorithm === constant('PASSWORD_BCRYPT')) {
            $this->assertIntegerOptions($options, ['cost' => 4], ['cost' => 31]);
            return;
        }

        if ($options !== []) {
            throw new InvalidArgumentException('Unsupported password hashing option.');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, int> $minimums
     * @param array<string, int> $maximums
     */
    private function assertIntegerOptions(
        array $options,
        array $minimums,
        array $maximums = [],
    ): void {
        foreach ($options as $name => $value) {
            if (!is_string($name) || !array_key_exists($name, $minimums)) {
                throw new InvalidArgumentException('Unsupported password hashing option.');
            }

            if (!is_int($value) || $value < $minimums[$name]) {
                throw new InvalidArgumentException('Password hashing options must be integers within their supported range.');
            }

            if (isset($maximums[$name]) && $value > $maximums[$name]) {
                throw new InvalidArgumentException('Password hashing options must be integers within their supported range.');
            }
        }
    }

    private function argon2id(): string|int|null
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            return null;
        }

        $algorithm = constant('PASSWORD_ARGON2ID');

        return in_array($algorithm, password_algos(), true) ? $algorithm : null;
    }

    private function isRecognizedHash(string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        $information = password_get_info($hash);
        $algorithm = $information['algo'] ?? null;

        return in_array($algorithm, password_algos(), true);
    }
}
