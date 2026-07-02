<?php

declare(strict_types=1);

/**
 * Validator — Advanced rule-based validation system
 */
class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];

    private function __construct(array $data, array $rules)
    {
        $this->data  = $data;
        $this->rules = $rules;
        $this->validate();
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function validate(): void
    {
        foreach ($this->rules as $field => $ruleString) {

            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $label = ucfirst(str_replace('_', ' ', $field));

            foreach ($rules as $rule) {

                $param = null;

                if (str_contains($rule, ':')) {
                    [$rule, $param] = explode(':', $rule, 2);
                }

                switch ($rule) {

                    case 'required':
                        if ($value === null || $value === '') {
                            $this->addError($field, "{$label} is required.");
                        }
                        break;

                    case 'nullable':
                        if ($value === null || $value === '') {
                            break 2;
                        }
                        break;

                    case 'string':
                        if ($value !== null && !is_string($value)) {
                            $this->addError($field, "{$label} must be a string.");
                        }
                        break;

                    case 'numeric':
                        if ($value !== null && !is_numeric($value)) {
                            $this->addError($field, "{$label} must be numeric.");
                        }
                        break;

                    case 'integer':
                        if ($value !== null && filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $this->addError($field, "{$label} must be an integer.");
                        }
                        break;

                    case 'boolean':
                        if ($value !== null && !is_bool($value)) {
                            $this->addError($field, "{$label} must be true or false.");
                        }
                        break;

                    case 'email':
                        if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $this->addError($field, "{$label} must be a valid email.");
                        }
                        break;

                    case 'min':
                        if ($value !== null && strlen((string)$value) < (int)$param) {
                            $this->addError($field, "{$label} must be at least {$param} characters.");
                        }
                        break;

                    case 'max':
                        if ($value !== null && strlen((string)$value) > (int)$param) {
                            $this->addError($field, "{$label} must not exceed {$param} characters.");
                        }
                        break;

                    case 'in':
                        if ($value !== null) {
                            $allowed = explode(',', $param);
                            if (!in_array((string)$value, $allowed, true)) {
                                $this->addError($field, "{$label} must be one of: " . implode(', ', $allowed));
                            }
                        }
                        break;

                    case 'mobile':
                        //if ($value !== null && !preg_match('/^\+[0-9]{8,15}$/', (string)$value)) {
                        //    $this->addError($field, "{$label} must be valid (e.g. +919999999999).");
                        //}

                        if ($value !== null && !preg_match('/^(\+?[0-9]{1,4})?[0-9]{8,15}$/', (string)$value)) {
                            $this->addError($field, "{$label} must be valid (e.g. +919999999999 or 9999999999).");
                        }

                        break;

                    case 'strong_password':
                        if ($value !== null) {
                            $errors = [];

                            if (strlen((string)$value) < 8) {
                                $errors[] = "8+ characters";
                            }
                            if (!preg_match('/[A-Z]/', (string)$value)) {
                                $errors[] = "1 uppercase";
                            }
                            if (!preg_match('/[a-z]/', (string)$value)) {
                                $errors[] = "1 lowercase";
                            }
                            if (!preg_match('/[0-9]/', (string)$value)) {
                                $errors[] = "1 number";
                            }
                            if (!preg_match('/[\W]/', (string)$value)) {
                                $errors[] = "1 special character";
                            }

                            if ($errors) {
                                $this->addError($field, "{$label} must contain " . implode(', ', $errors));
                            }
                        }
                        break;

                    case 'confirmed':
                        $confirmField = $field . '_confirmation';
                        if (($this->data[$confirmField] ?? null) !== $value) {
                            $this->addError($field, "{$label} confirmation does not match.");
                        }
                        break;

                    case 'unique':
                        if ($value !== null && $param) {
                            [$table, $column] = explode(',', $param);
                            $db = Database::getInstance();

                            $sql = "SELECT id FROM {$table} WHERE {$column} = :val";

                            if ($this->tableHasDeletedAt($table)) {
                                $sql .= " AND deleted_at IS NULL";
                            }

                            $row = $db->getRow($sql, [':val' => $value]);

                            if ($row) {
                                $this->addError($field, "{$label} already exists.");
                            }
                        }
                        break;

                    case 'exists':
                        if ($value !== null && $param) {
                            [$table, $column] = explode(',', $param);
                            $db = Database::getInstance();

                            $sql = "SELECT id FROM {$table} WHERE {$column} = :val";

                            if ($this->tableHasDeletedAt($table)) {
                                $sql .= " AND deleted_at IS NULL";
                            }

                            $row = $db->getRow($sql, [':val' => $value]);

                            if (!$row) {
                                $this->addError($field, "{$label} does not exist.");
                            }
                        }
                        break;
                    case 'doctor_exists':
                        if ($value !== null) {
                            $db = Database::getInstance();

                            $row = $db->getRow(
                                "SELECT id FROM doctors WHERE id = :id",
                                [':id' => $value]
                            );

                            if (!$row) {
                                $this->addError($field, "Doctor not found.");
                            }
                        }
                        break;

                    case 'date':
                        if ($value !== null && strtotime($value) === false) {
                            $this->addError($field, "{$label} must be a valid date.");
                        }
                        break;

                    case 'file':
                        if (!isset($_FILES[$field])) {
                            $this->addError($field, "{$label} file is required.");
                        }
                        break;

                    case 'image':
                        if (isset($_FILES[$field])) {
                            $type = $_FILES[$field]['type'] ?? '';
                            if (!str_starts_with($type, 'image/')) {
                                $this->addError($field, "{$label} must be an image.");
                            }
                        }
                        break;
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function tableHasDeletedAt(string $table): bool
    {
        static $tables = [
            'doctors',
            'patients',
            'clinics',
            // add only tables which actually have deleted_at
        ];

        return in_array($table, $tables, true);
    }

}