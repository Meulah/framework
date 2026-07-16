<?php

declare(strict_types=1);

namespace Meulah\Validation\Internal;

use Meulah\Http\UploadedFile;
use Meulah\Http\UploadException;

final class RuleEvaluator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, list<RuleDefinition>> $compiled
     * @return array<string, mixed>
     */
    public function normalize(array $data, array $compiled): array
    {
        $normalized = $data;

        foreach ($compiled as $field => $rules) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $normalized[$field] = $this->normalizeValue($data[$field], $rules);
        }

        return $normalized;
    }

    /** @param list<RuleDefinition> $rules */
    public function normalizeValue(mixed $value, array $rules): mixed
    {
        if ($this->hasRule($rules, 'integer')) {
            [$valid, $integer] = $this->parseInteger($value);

            return $valid ? $integer : $value;
        }

        if ($this->hasRule($rules, 'boolean')) {
            [$valid, $boolean] = $this->parseBoolean($value);

            return $valid ? $boolean : $value;
        }

        return $value;
    }

    /** @param list<RuleDefinition> $rules */
    public function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if ($rule->name === $name) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $source
     * @param list<RuleDefinition> $fieldRules
     */
    public function evaluate(
        string $field,
        mixed $value,
        RuleDefinition $rule,
        array $normalized,
        array $source,
        array $fieldRules,
    ): ?string {
        return match ($rule->name) {
            'required', 'present', 'nullable' => null,
            'string' => is_string($value)
                ? null
                : $this->message($field, 'must be a string.'),
            'integer' => $this->parseInteger($value)[0]
                ? null
                : $this->message($field, 'must be an integer.'),
            'boolean' => $this->parseBoolean($value)[0]
                ? null
                : $this->message($field, 'must be a boolean.'),
            'array' => is_array($value)
                ? null
                : $this->message($field, 'must be an array.'),
            'email' => is_string($value) && filter_var(
                $value,
                FILTER_VALIDATE_EMAIL,
            ) !== false
                ? null
                : $this->message($field, 'must be a valid email address.'),
            'min' => $this->validateMinimum($field, $value, $rule->parameters[0]),
            'max' => $this->validateMaximum($field, $value, $rule->parameters[0]),
            'between' => $this->validateBetween(
                $field,
                $value,
                $rule->parameters[0],
                $rule->parameters[1],
            ),
            'in' => $this->validateIn($field, $value, $rule->parameters),
            'same' => array_key_exists($rule->parameters[0], $source)
                && $value === $normalized[$rule->parameters[0]]
                    ? null
                    : $this->message(
                        $field,
                        "must match {$rule->parameters[0]}.",
                    ),
            'confirmed' => array_key_exists($field . '_confirmation', $source)
                && $value === $this->normalizeValue(
                    $source[$field . '_confirmation'],
                    $fieldRules,
                )
                    ? null
                    : $this->message($field, 'confirmation does not match.'),
            'file' => $value instanceof UploadedFile && $value->isValid()
                ? null
                : $this->message($field, 'must be a valid uploaded file.'),
            'max_size' => $this->validateMaximumFileSize(
                $field,
                $value,
                (int) $rule->parameters[0],
            ),
            'detected_mime' => $this->validateDetectedMime(
                $field,
                $value,
                $rule->parameters,
            ),
            default => throw new \LogicException(
                "Unhandled validation rule '{$rule->name}'.",
            ),
        };
    }

    public function message(string $field, string $message): string
    {
        return sprintf(
            'The %s field %s',
            str_replace('_', ' ', $field),
            $message,
        );
    }

    private function validateMinimum(
        string $field,
        mixed $value,
        string $minimum,
    ): ?string {
        $measurement = $this->measurement($value);

        return $measurement !== null && $measurement >= (float) $minimum
            ? null
            : $this->message(
                $field,
                "must have a value or size of at least {$minimum}.",
            );
    }

    private function validateMaximum(
        string $field,
        mixed $value,
        string $maximum,
    ): ?string {
        $measurement = $this->measurement($value);

        return $measurement !== null && $measurement <= (float) $maximum
            ? null
            : $this->message(
                $field,
                "must have a value or size no greater than {$maximum}.",
            );
    }

    private function validateBetween(
        string $field,
        mixed $value,
        string $minimum,
        string $maximum,
    ): ?string {
        $measurement = $this->measurement($value);

        return $measurement !== null
            && $measurement >= (float) $minimum
            && $measurement <= (float) $maximum
                ? null
                : $this->message(
                    $field,
                    "must have a value or size between {$minimum} and {$maximum}.",
                );
    }

    /** @param list<string> $allowed */
    private function validateIn(
        string $field,
        mixed $value,
        array $allowed,
    ): ?string {
        $typed = array_map(
            fn (string $item): mixed => $this->typedAllowedValue($item, $value),
            $allowed,
        );

        return in_array($value, $typed, true)
            ? null
            : $this->message($field, 'must be one of the allowed values.');
    }

    private function typedAllowedValue(string $value, mixed $expectedType): mixed
    {
        if (is_int($expectedType)) {
            [$valid, $integer] = $this->parseInteger($value);

            return $valid ? $integer : $value;
        }

        if (is_bool($expectedType)) {
            [$valid, $boolean] = $this->parseBoolean($value);

            return $valid ? $boolean : $value;
        }

        return $value;
    }

    private function validateMaximumFileSize(
        string $field,
        mixed $value,
        int $maximum,
    ): ?string {
        return $value instanceof UploadedFile
            && $value->isValid()
            && $value->size() <= $maximum
                ? null
                : $this->message(
                    $field,
                    "must be a valid file no larger than {$maximum} bytes.",
                );
    }

    /** @param list<string> $allowed */
    private function validateDetectedMime(
        string $field,
        mixed $value,
        array $allowed,
    ): ?string {
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            return $this->message($field, 'must be a valid uploaded file.');
        }

        try {
            $mime = $value->detectedMediaType();
        } catch (UploadException) {
            return $this->message(
                $field,
                'has an unreadable detected MIME type.',
            );
        }

        return in_array(
            strtolower($mime),
            array_map('strtolower', $allowed),
            true,
        )
            ? null
            : $this->message(
                $field,
                'must have an allowed detected MIME type.',
            );
    }

    private function measurement(mixed $value): int|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $length = preg_match_all('/./us', $value);

            return $length === false ? null : $length;
        }

        return is_array($value) ? count($value) : null;
    }

    /** @return array{bool, mixed} */
    private function parseInteger(mixed $value): array
    {
        if (is_int($value)) {
            return [true, $value];
        }

        if (
            is_string($value)
            && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1
        ) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer !== false) {
                return [true, $integer];
            }
        }

        return [false, $value];
    }

    /** @return array{bool, mixed} */
    private function parseBoolean(mixed $value): array
    {
        return match ($value) {
            true, 1, '1', 'true' => [true, true],
            false, 0, '0', 'false' => [true, false],
            default => [false, $value],
        };
    }
}
