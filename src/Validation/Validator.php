<?php

declare(strict_types=1);

namespace Meulah\Validation;

use Meulah\Validation\Internal\RuleEvaluator;
use Meulah\Validation\Internal\RuleParser;

final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, list<string>> $rules
     */
    public function validate(array $data, array $rules): ValidationResult
    {
        $evaluator = new RuleEvaluator();
        $compiled = (new RuleParser())->compile($rules);
        $normalized = $evaluator->normalize($data, $compiled);
        $validated = [];
        $errors = [];

        foreach ($compiled as $field => $fieldRules) {
            $present = array_key_exists($field, $data);
            $nullable = $evaluator->hasRule($fieldRules, 'nullable');

            if (!$present) {
                foreach ($fieldRules as $rule) {
                    if ($rule->name === 'required') {
                        $errors[$field][] = $evaluator->message($field, 'is required.');
                    } elseif ($rule->name === 'present') {
                        $errors[$field][] = $evaluator->message($field, 'must be present.');
                    }
                }

                continue;
            }

            $value = $normalized[$field];

            if (
                $evaluator->hasRule($fieldRules, 'required')
                && $evaluator->isEmpty($value)
            ) {
                $errors[$field][] = $evaluator->message($field, 'is required.');
                continue;
            }

            if ($value === null && $nullable) {
                $validated[$field] = null;
                continue;
            }

            foreach ($fieldRules as $rule) {
                $error = $evaluator->evaluate(
                    $field,
                    $value,
                    $rule,
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
}
