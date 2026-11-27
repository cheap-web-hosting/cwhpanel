<?php

declare(strict_types=1);

namespace App\Foundation;

class Validator
{
    private array $errors = [];

    /**
     * Minimal validation. Rules are pipe-delimited per field:
     * required, email, min:N, max:N, alpha_dash, confirmed (checks <field>_confirmation).
     */
    public function validate(array $data, array $rules): array
    {
        $this->errors = [];
        $validated = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;

            foreach (explode('|', $ruleString) as $rule) {
                $this->checkRule($field, $value, $rule, $data);
            }

            if (!array_key_exists($field, $this->errors)) {
                $validated[$field] = $value;
            }
        }

        if ($this->errors) {
            throw new ValidationException($this->errors);
        }

        return $validated;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function checkRule(string $field, $value, string $rule, array $data): void
    {
        [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

        $trimmed = is_string($value) ? trim($value) : $value;

        switch ($name) {
            case 'required':
                if ($trimmed === null || $trimmed === '' || (is_array($trimmed) && empty($trimmed))) {
                    $this->errors[$field][] = "The {$field} field is required.";
                }
                break;

            case 'email':
                if ($trimmed !== '' && !filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$field][] = "The {$field} must be a valid email address.";
                }
                break;

            case 'min':
                if (is_string($trimmed) && mb_strlen($trimmed) < (int) $param) {
                    $this->errors[$field][] = "The {$field} must be at least {$param} characters.";
                }
                break;

            case 'max':
                if (is_string($trimmed) && mb_strlen($trimmed) > (int) $param) {
                    $this->errors[$field][] = "The {$field} must not exceed {$param} characters.";
                }
                break;

            case 'alpha_dash':
                if (is_string($trimmed) && $trimmed !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $trimmed)) {
                    $this->errors[$field][] = "The {$field} may only contain letters, numbers, dashes and underscores.";
                }
                break;

            case 'confirmed':
                $confirmation = $data[$field . '_confirmation'] ?? null;
                if ($trimmed !== $confirmation) {
                    $this->errors[$field][] = "The {$field} confirmation does not match.";
                }
                break;

            case 'integer':
                // NOTE: filter_var('0', FILTER_VALIDATE_INT) returns int 0,
                // which is falsy — `!filter_var(...)` rejected every zero
                // (all unlimited/off numeric package fields). Compare against
                // false strictly so 0 is valid and "05"/"1.5"/"abc" are not.
                if ($trimmed !== null && $trimmed !== '' && filter_var($trimmed, FILTER_VALIDATE_INT) === false) {
                    $this->errors[$field][] = "The {$field} must be an integer.";
                }
                break;
        }
    }
}
