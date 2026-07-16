<?php

declare(strict_types=1);

namespace Meulah\Validation;

use Meulah\Http\UploadedFile;
use Meulah\Http\UploadException;

final class Validator
{
    private const RULES = [
        'required', 'present', 'nullable', 'string', 'integer', 'boolean',
        'array', 'email', 'min', 'max', 'between', 'in', 'same',
        'confirmed', 'file', 'max_size', 'detected_mime',
    ];

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<string>> $rules
     */
    public function validate(array $data, array $rules): ValidationResult
    {
        $compiled = $this->compile($rules);
        $normalized = $this->normalize($data, $compiled);
        $validated = [];
        $errors = [];

        foreach ($compiled as $field => $fieldRules) {
            $present = array_key_exists($field, $data);
            $nullable = $this->hasRule($fieldRules, 'nullable');

            if (!$present) {
                foreach ($fieldRules as $rule) {
                    if ($rule['name'] === 'required') {
                        $errors[$field][] = $this->message($field, 'is required.');
                    } elseif ($rule['name'] === 'present') {
                        $errors[$field][] = $this->message($field, 'must be present.');
                    }
                }

                continue;
            }

            $value = $normalized[$field];

            if ($this->hasRule($fieldRules, 'required') && $this->isEmpty($value)) {
                $errors[$field][] = $this->message($field, 'is required.');
                continue;
            }

            if ($value === null && $nullable) {
                $validated[$field] = null;
                continue;
            }

            foreach ($fieldRules as $rule) {
                $error = $this->validateRule(
                    $field,
                    $value,
                    $rule['name'],
                    $rule['parameters'],
                    $normalized,
                    $data,
                    $fieldRules,
                );

                if ($error !== null) {
                    $errors[$field][] = $error;
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        return new ValidationResult($validated, $errors);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<string>> $rules
     * @return array<string, mixed>
     */
    public function validateOrFail(array $data, array $rules): array
    {
        $result = $this->validate($data, $rules);

        if (!$result->isValid()) {
            throw new ValidationException($result);
        }

        return $result->validated();
    }

    /**
     * @param array<string, list<string>> $rules
     * @return array<string, list<array{name: string, parameters: list<string>}>>
     */
    private function compile(array $rules): array
    {
        $compiled = [];

        foreach ($rules as $field => $definitions) {
            if (!is_string($field) || trim($field) === '') {
                throw new ValidationRuleException('Validation rule fields must be non-empty strings.');
            }

            if (!is_array($definitions)) {
                throw new ValidationRuleException(sprintf(
                    "Validation rules for field '%s' must be an array.",
                    $field,
                ));
            }

            if ($definitions === []) {
                throw new ValidationRuleException(sprintf(
                    "Validation rules for field '%s' cannot be empty.",
                    $field,
                ));
            }

            foreach ($definitions as $definition) {
                if (!is_string($definition) || trim($definition) === '') {
                    throw new ValidationRuleException(sprintf(
                        "Validation rules for field '%s' must be non-empty strings.",
                        $field,
                    ));
                }

                [$name, $parameterList] = array_pad(explode(':', trim($definition), 2), 2, null);
                $name = strtolower($name);
                $parameters = $parameterList === null ? [] : explode(',', $parameterList);

                if (!in_array($name, self::RULES, true)) {
                    throw new ValidationRuleException(sprintf(
                        "Unknown validation rule '%s' for field '%s'.",
                        $name,
                        $field,
                    ));
                }

                $this->assertParameters($field, $name, $parameters);
                $compiled[$field][] = ['name' => $name, 'parameters' => $parameters];
            }
        }

        return $compiled;
    }

    /** @param list<string> $parameters */
    private function assertParameters(string $field, string $rule, array $parameters): void
    {
        $none = [
            'required', 'present', 'nullable', 'string', 'integer', 'boolean',
            'array', 'email', 'confirmed', 'file',
        ];

        if (in_array($rule, $none, true) && $parameters !== []) {
            $this->invalidRule($field, $rule, 'does not accept parameters');
        }

        if (in_array($rule, ['min', 'max'], true)) {
            if (count($parameters) !== 1 || !$this->isNumber($parameters[0])) {
                $this->invalidRule($field, $rule, 'expects one numeric parameter');
            }
        }

        if ($rule === 'between') {
            if (
                count($parameters) !== 2
                || !$this->isNumber($parameters[0])
                || !$this->isNumber($parameters[1])
                || (float) $parameters[0] > (float) $parameters[1]
            ) {
                $this->invalidRule($field, $rule, 'expects ordered minimum and maximum numbers');
            }
        }

        if ($rule === 'in' && ($parameters === [] || in_array('', $parameters, true))) {
            $this->invalidRule($field, $rule, 'expects one or more non-empty values');
        }

        if ($rule === 'same') {
            if (count($parameters) !== 1 || trim($parameters[0]) === '') {
                $this->invalidRule($field, $rule, 'expects one field name');
            }
        }

        if ($rule === 'max_size') {
            if (
                count($parameters) !== 1
                || preg_match('/^(?:0|[1-9][0-9]*)$/', $parameters[0]) !== 1
                || filter_var(
                    $parameters[0],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0]],
                ) === false
            ) {
                $this->invalidRule($field, $rule, 'expects one platform-sized non-negative byte count');
            }
        }

        if ($rule === 'detected_mime' && ($parameters === [] || in_array('', $parameters, true))) {
            $this->invalidRule($field, $rule, 'expects one or more MIME types');
        }
    }

    private function invalidRule(string $field, string $rule, string $expectation): never
    {
        throw new ValidationRuleException(sprintf(
            "Validation rule '%s' for field '%s' %s.",
            $rule,
            $field,
            $expectation,
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<array{name: string, parameters: list<string>}>> $compiled
     * @return array<string, mixed>
     */
    private function normalize(array $data, array $compiled): array
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

    /** @param list<array{name: string, parameters: list<string>}> $rules */
    private function normalizeValue(mixed $value, array $rules): mixed
    {
        if ($this->hasRule($rules, 'integer')) {
            [$valid, $integer] = $this->parseInteger($value);

            if ($valid) {
                return $integer;
            }
        }

        if ($this->hasRule($rules, 'boolean')) {
            [$valid, $boolean] = $this->parseBoolean($value);

            if ($valid) {
                return $boolean;
            }
        }

        return $value;
    }

    /** @param list<array{name: string, parameters: list<string>}> $rules */
    private function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if ($rule['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $parameters
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $source
     * @param list<array{name: string, parameters: list<string>}> $fieldRules
     */
    private function validateRule(
        string $field,
        mixed $value,
        string $rule,
        array $parameters,
        array $normalized,
        array $source,
        array $fieldRules,
    ): ?string {
        return match ($rule) {
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
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : $this->message($field, 'must be a valid email address.'),
            'min' => $this->validateMinimum($field, $value, $parameters[0]),
            'max' => $this->validateMaximum($field, $value, $parameters[0]),
            'between' => $this->validateBetween($field, $value, $parameters[0], $parameters[1]),
            'in' => $this->validateIn($field, $value, $parameters),
            'same' => array_key_exists($parameters[0], $source)
                && $value === $normalized[$parameters[0]]
                ? null
                : $this->message($field, "must match {$parameters[0]}."),
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
            'max_size' => $this->validateMaximumFileSize($field, $value, (int) $parameters[0]),
            'detected_mime' => $this->validateDetectedMime($field, $value, $parameters),
            default => throw new \LogicException("Unhandled validation rule '{$rule}'."),
        };
    }

    private function validateMinimum(string $field, mixed $value, string $minimum): ?string
    {
        $measurement = $this->measurement($value);

        return $measurement !== null && $measurement >= (float) $minimum
            ? null
            : $this->message($field, "must have a value or size of at least {$minimum}.");
    }

    private function validateMaximum(string $field, mixed $value, string $maximum): ?string
    {
        $measurement = $this->measurement($value);

        return $measurement !== null && $measurement <= (float) $maximum
            ? null
            : $this->message($field, "must have a value or size no greater than {$maximum}.");
    }

    private function validateBetween(string $field, mixed $value, string $minimum, string $maximum): ?string
    {
        $measurement = $this->measurement($value);

        return $measurement !== null
            && $measurement >= (float) $minimum
            && $measurement <= (float) $maximum
                ? null
                : $this->message($field, "must have a value or size between {$minimum} and {$maximum}.");
    }

    /** @param list<string> $allowed */
    private function validateIn(string $field, mixed $value, array $allowed): ?string
    {
        $allowed = array_map(fn (string $item): mixed => $this->typedAllowedValue($item, $value), $allowed);

        return in_array($value, $allowed, true)
            ? null
            : $this->message($field, 'must be one of: ' . implode(', ', $allowed) . '.');
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

    private function validateMaximumFileSize(string $field, mixed $value, int $maximum): ?string
    {
        return $value instanceof UploadedFile && $value->isValid() && $value->size() <= $maximum
            ? null
            : $this->message($field, "must be a valid file no larger than {$maximum} bytes.");
    }

    /** @param list<string> $allowed */
    private function validateDetectedMime(string $field, mixed $value, array $allowed): ?string
    {
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            return $this->message($field, 'must be a valid uploaded file.');
        }

        try {
            $mime = $value->detectedMediaType();
        } catch (UploadException) {
            return $this->message($field, 'has an unreadable detected MIME type.');
        }

        return in_array(strtolower($mime), array_map('strtolower', $allowed), true)
            ? null
            : $this->message($field, 'must have one of these detected MIME types: ' . implode(', ', $allowed) . '.');
    }

    private function measurement(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
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

        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) === 1) {
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

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function isNumber(string $value): bool
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) !== 1) {
            return false;
        }

        return is_finite((float) $value);
    }

    private function message(string $field, string $message): string
    {
        return sprintf('The %s field %s', str_replace('_', ' ', $field), $message);
    }
}
