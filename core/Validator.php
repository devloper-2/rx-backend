<?php

declare(strict_types=1);

/**
 * Validator — Validate input arrays against a rule set.
 *
 * Usage:
 *   $v = Validator::make($data, [
 *       'email'    => 'required|email',
 *       'password' => 'required|min:8|max:64',
 *       'age'      => 'required|integer|min:18|max:120',
 *       'role'     => 'required|in:admin,user,moderator',
 *       'avatar'   => 'nullable|string',
 *   ]);
 *
 *   if (!$v->passes()) {
 *       Response::send(422, [], 'Validation Failed', false, $v->errors());
 *   }
 */
class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];
    private array $messages;

    private function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data     = $data;
        $this->rules    = $rules;
        $this->messages = $messages;
        $this->run();
    }

    public static function make(array $data, array $rules, array $messages = []): static
    {
        return new static($data, $rules, $messages);
    }

    // ── Run all rules ────────────────────────────────────────────────────────
    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules    = explode('|', $ruleString);
            $value    = $this->data[$field] ?? null;
            $nullable = in_array('nullable', $rules, true);

            foreach ($rules as $rule) {
                if ($rule === 'nullable') continue;

                // Skip non-required empty fields
                if ($rule !== 'required' && ($value === null || $value === '') && $nullable) {
                    continue;
                }

                [$ruleName, $ruleParam] = $this->parseRule($rule);
                $this->applyRule($field, $value, $ruleName, $ruleParam);
            }
        }
    }

    private function parseRule(string $rule): array
    {
        if (str_contains($rule, ':')) {
            [$name, $param] = explode(':', $rule, 2);
            return [$name, $param];
        }
        return [$rule, null];
    }

    private function applyRule(string $field, mixed $value, string $rule, ?string $param): void
    {
        $label = ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {

        // BELOW ALL RULES ARE FOR TESTING
            
            case 'country_code_validation':
                if ($value !== null && !preg_match('/^\+[0-9]{1,5}$/', (string)$value)) {
                    $this->addError($field, "{$label} must start with + and contain 1 to 5 digits.");
                }
            break;

            case 'password_validation':
                if ($value !== null) {
                    $errors = [];
                    if (strlen((string)$value) < 8) {
                        $errors[] = "at least 8 characters";
                    }
                    if (strlen((string)$value) > 20) {
                        $errors[] = "no more than 20 characters";
                    }
                    if (!preg_match('/[A-Z]/', (string)$value)) {
                        $errors[] = "at least one uppercase letter";
                    }
                    if (!preg_match('/[a-z]/', (string)$value)) {
                        $errors[] = "at least one lowercase letter";
                    }
                    if (!preg_match('/[0-9]/', (string)$value)) {
                        $errors[] = "at least one number";
                    }
                    if (!preg_match('/[\W_]/', (string)$value)) {
                        $errors[] = "at least one special character";
                    }
                    if ($errors) {
                        $this->addError($field, "{$label} must contain " . implode(', ', $errors) . ".");
                    }
                }
                break;

            case 'phone_validation':
                if ($value !== null) {
                    $countryCode = $this->data['countrycode'] ?? null;
                    if ($countryCode === '+91') {
                        if (!preg_match('/^[0-9]{10}$/', (string)$value)) {
                            $this->addError($field, "{$label} must contain exactly 10 digits for country code +91.");
                        }
                    } elseif (!preg_match('/^[0-9]{7,20}$/', (string)$value)) {
                        $this->addError($field, "{$label} must contain only numbers and be between 7 and 20 digits.");
                    }
                }
                break;

            case 'unique_phone':
    if ($value !== null) {
        $db  = Database::getInstance();
        $row = $db->getRow(
            'SELECT id FROM users WHERE phone = :phone AND countrycode = :code',
            [
                ':phone' => $value,
                ':code'  => $this->data['countrycode'] ?? ''
            ]
        );
        if ($row) {
            $this->addError($field, "{$label} already registered.");
        }
    }
break;

        // ABOVE ALL RULES ARE FOR TESTING

            case 'required':
                if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
                    $this->addError($field, "{$label} is required.");
                }
                break;

            case 'string':
                if ($value !== null && !is_string($value)) {
                    $this->addError($field, "{$label} must be a string.");
                }
                break;

            case 'integer':
            case 'int':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->addError($field, "{$label} must be an integer.");
                }
                break;

            case 'numeric':
                if ($value !== null && !is_numeric($value)) {
                    $this->addError($field, "{$label} must be numeric.");
                }
                break;

            case 'boolean':
            case 'bool':
                if ($value !== null && !in_array($value, [true, false, 1, 0, '1', '0'], true)) {
                    $this->addError($field, "{$label} must be a boolean.");
                }
                break;

            case 'email':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "{$label} must be a valid email address.");
                }
                break;

            case 'url':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addError($field, "{$label} must be a valid URL.");
                }
                break;

            case 'min':
                if ($value !== null) {
                    if (is_string($value) && mb_strlen($value) < (int) $param) {
                        $this->addError($field, "{$label} must be at least {$param} characters.");
                    } elseif (is_numeric($value) && (float) $value < (float) $param) {
                        $this->addError($field, "{$label} must be at least {$param}.");
                    }
                }
                break;

            case 'max':
                if ($value !== null) {
                    if (is_string($value) && mb_strlen($value) > (int) $param) {
                        $this->addError($field, "{$label} may not be greater than {$param} characters.");
                    } elseif (is_numeric($value) && (float) $value > (float) $param) {
                        $this->addError($field, "{$label} may not be greater than {$param}.");
                    }
                }
                break;

            case 'in':
                if ($value !== null) {
                    $allowed = explode(',', $param);
                    if (!in_array($value, $allowed, true)) {
                        $this->addError($field, "{$label} must be one of: {$param}.");
                    }
                }
                break;

            case 'not_in':
                if ($value !== null) {
                    $forbidden = explode(',', $param);
                    if (in_array($value, $forbidden, true)) {
                        $this->addError($field, "{$label} contains an invalid value.");
                    }
                }
                break;

            case 'regex':
                if ($value !== null && !preg_match($param, (string) $value)) {
                    $this->addError($field, "{$label} format is invalid.");
                }
                break;

            case 'date':
                if ($value !== null && strtotime((string) $value) === false) {
                    $this->addError($field, "{$label} must be a valid date.");
                }
                break;

            case 'alpha':
                if ($value !== null && !ctype_alpha((string) $value)) {
                    $this->addError($field, "{$label} may only contain letters.");
                }
                break;

            case 'alpha_num':
                if ($value !== null && !ctype_alnum((string) $value)) {
                    $this->addError($field, "{$label} may only contain letters and numbers.");
                }
                break;

            case 'array':
                if ($value !== null && !is_array($value)) {
                    $this->addError($field, "{$label} must be an array.");
                }
                break;

            case 'confirmed':
                $confirmation = $this->data[$field . '_confirmation'] ?? null;
                if ($value !== $confirmation) {
                    $this->addError($field, "{$label} confirmation does not match.");
                }
                break;

            case 'unique_email':
                // Example custom rule — check DB uniqueness
                if ($value !== null) {
                    $db  = Database::getInstance();
                    $row = $db->getRow('SELECT id FROM users WHERE email = :email', [':email' => $value]);
                    if ($row) {
                        $this->addError($field, "{$label} is already taken.");
                    }
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        // Use custom message if provided
        $key = $field . '.*';
        $this->errors[$field][] = $this->messages[$key] ?? $message;
    }

    // ── Result accessors ─────────────────────────────────────────────────────
    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        foreach ($this->errors as $messages) {
            return $messages[0] ?? '';
        }
        return '';
    }
}
