<?php

declare(strict_types=1);

namespace Meulah\Validation\Internal;

use Meulah\Validation\ValidationRuleException;

final class RuleParser
{
    private const RULES = [
        'required', 'present', 'nullable', 'string', 'integer', 'boolean',
        'array', 'email', 'min', 'max', 'between', 'in', 'same',
        'confirmed', 'file', 'max_size', 'detected_mime',
    ];

    private const TYPE_RULES = ['string', 'integer', 'boolean', 'array', 'file'];

    /**
     * @param array<string, list<string>> $rules
     * @return array<string, list<RuleDefinition>>
     */
    public function compile(array $rules): array
    {
        $compiled = [];

        foreach ($rules as $field => $definitions) {
            if (!is_string($field) || trim($field) === '') {
                throw new ValidationRuleException(
                    'Validation rule fields must be non-empty strings.',
                );
            }

            if (!is_array($definitions)) {
                throw new ValidationRuleException(sprintf(
                    "Validation rules for field '%s' must be an array.",
                    $field,
                ));
            }


            if (!array_is_list($definitions)) {
                throw new ValidationRuleException(sprintf(
                    "Validation rules for field '%s' must be a list.",
                    $field,
                ));
            }
            if ($definitions === []) {
                throw new ValidationRuleException(sprintf(
                    "Validation rules for field '%s' cannot be empty.",
                    $field,
                ));
            }

            $fieldRules = [];
            $seen = [];

            foreach (array_values($definitions) as $index => $definition) {
                $position = $index + 1;

                if (!is_string($definition) || trim($definition) === '') {
                    throw new ValidationRuleException(sprintf(
                        "Validation rule #%d for field '%s' must be a non-empty string.",
                        $position,
                        $field,
                    ));
                }

                [$name, $parameterList] = array_pad(
                    explode(':', trim($definition), 2),
                    2,
                    null,
                );
                $name = strtolower($name);
                $parameters = $parameterList === null ? [] : explode(',', $parameterList);

                if (!in_array($name, self::RULES, true)) {
                    throw new ValidationRuleException(sprintf(
                        "Unknown validation rule '%s' at position %d for field '%s'.",
                        $name,
                        $position,
                        $field,
                    ));
                }

                if (isset($seen[$name])) {
                    throw new ValidationRuleException(sprintf(
                        "Validation rule '%s' is duplicated for field '%s'.",
                        $name,
                        $field,
                    ));
                }

                $this->assertParameters($field, $name, $parameters, $position);
                $seen[$name] = true;
                $fieldRules[] = new RuleDefinition($name, $parameters);
            }

            $this->assertCompatible($field, $fieldRules);
            $compiled[$field] = $fieldRules;
        }

        return $compiled;
    }

    /** @param list<string> $parameters */
    private function assertParameters(
        string $field,
        string $rule,
        array $parameters,
        int $position,
    ): void {
        $none = [
            'required', 'present', 'nullable', 'string', 'integer', 'boolean',
            'array', 'email', 'confirmed', 'file',
        ];

        if (in_array($rule, $none, true) && $parameters !== []) {
            $this->invalidRule($field, $rule, $position, 'does not accept parameters');
        }

        if (in_array($rule, ['min', 'max'], true)) {
            if (count($parameters) !== 1 || !$this->isNumber($parameters[0])) {
                $this->invalidRule($field, $rule, $position, 'expects one finite numeric parameter');
            }
        }

        if ($rule === 'between') {
            if (
                count($parameters) !== 2
                || !$this->isNumber($parameters[0])
                || !$this->isNumber($parameters[1])
                || (float) $parameters[0] > (float) $parameters[1]
            ) {
                $this->invalidRule(
                    $field,
                    $rule,
                    $position,
                    'expects ordered finite minimum and maximum numbers',
                );
            }
        }

        if ($rule === 'in') {
            if (
                $parameters === []
                || in_array('', $parameters, true)
                || count($parameters) !== count(array_unique($parameters))
            ) {
                $this->invalidRule(
                    $field,
                    $rule,
                    $position,
                    'expects unique non-empty allowed values',
                );
            }
        }

        if ($rule === 'same') {
            if (
                count($parameters) !== 1
                || $parameters[0] === ''
                || trim($parameters[0]) !== $parameters[0]
            ) {
                $this->invalidRule(
                    $field,
                    $rule,
                    $position,
                    'expects one exact field name without surrounding whitespace',
                );
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
                $this->invalidRule(
                    $field,
                    $rule,
                    $position,
                    'expects one platform-sized non-negative byte count',
                );
            }
        }

        if ($rule === 'detected_mime') {
            if ($parameters === []) {
                $this->invalidRule(
                    $field,
                    $rule,
                    $position,
                    'expects one or more exact MIME types',
                );
            }

            $normalized = [];

            foreach ($parameters as $mime) {
                if (
                    $mime === ''
                    || trim($mime) !== $mime
                    || preg_match(
                        '/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/D',
                        $mime,
                    ) !== 1
                ) {
                    $this->invalidRule(
                        $field,
                        $rule,
                        $position,
                        'expects exact MIME types without parameters or whitespace',
                    );
                }

                $normalized[] = strtolower($mime);
            }

            if (count($normalized) !== count(array_unique($normalized))) {
                $this->invalidRule(
                    $field,
                    $rule,
                    $position,
                    'expects unique MIME types',
                );
            }
        }
    }

    /** @param list<RuleDefinition> $rules */
    private function assertCompatible(string $field, array $rules): void
    {
        $names = array_map(
            static fn (RuleDefinition $rule): string => $rule->name,
            $rules,
        );
        $types = array_values(array_intersect(self::TYPE_RULES, $names));

        if (count($types) > 1) {
            throw new ValidationRuleException(sprintf(
                "Validation field '%s' has incompatible type rules: %s.",
                $field,
                implode(', ', $types),
            ));
        }

        if (in_array('required', $names, true) && in_array('nullable', $names, true)) {
            throw new ValidationRuleException(sprintf(
                "Validation field '%s' cannot combine required and nullable.",
                $field,
            ));
        }

        $type = $types[0] ?? null;

        if (in_array('email', $names, true) && $type !== null && $type !== 'string') {
            throw new ValidationRuleException(sprintf(
                "Validation field '%s' can combine email only with the string type rule.",
                $field,
            ));
        }

        if (
            $type !== null
            && $type !== 'file'
            && (
                in_array('max_size', $names, true)
                || in_array('detected_mime', $names, true)
            )
        ) {
            throw new ValidationRuleException(sprintf(
                "Validation field '%s' can use file metadata rules only with the file type rule.",
                $field,
            ));
        }

        if (
            $type !== null
            && !in_array($type, ['string', 'integer', 'array'], true)
            && (
                in_array('min', $names, true)
                || in_array('max', $names, true)
                || in_array('between', $names, true)
            )
        ) {
            throw new ValidationRuleException(sprintf(
                "Validation field '%s' can use value or size rules only with string, integer, or array.",
                $field,
            ));
        }
    }

    private function invalidRule(
        string $field,
        string $rule,
        int $position,
        string $expectation,
    ): never {
        throw new ValidationRuleException(sprintf(
            "Validation rule '%s' at position %d for field '%s' %s.",
            $rule,
            $position,
            $field,
            $expectation,
        ));
    }

    private function isNumber(string $value): bool
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) !== 1) {
            return false;
        }

        return is_finite((float) $value);
    }
}
