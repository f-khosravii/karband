<?php

declare(strict_types=1);

namespace Karband\DynamicForms\Definitions;

use Karband\DynamicForms\Exceptions\InvalidForm;

/** Only declarative, allowlisted properties. Conditional fields may refer to earlier fields. */
final readonly class FormField
{
    public const TYPES = ['text', 'textarea', 'email', 'number', 'select', 'checkbox', 'date'];

    /**
     * @param list<string> $options
     * @param array{field:string,equals:string|int|float|bool|null}|null $visibleWhen
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public bool $required = false,
        public array $options = [],
        public ?array $visibleWhen = null,
        public ?string $rule = null,
        public ?int $maxLength = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
    ) {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key)) {
            throw new InvalidForm('Invalid field key.');
        }
        if ($key === '_item_id') {
            throw new InvalidForm("Field key cannot be reserved identifier '_item_id'.");
        }
        if (trim($label) === '' || strlen($label) > 255 || !preg_match('//u', $label)) {
            throw new InvalidForm("Invalid label for {$key}.");
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidForm("Unsupported field type for {$key}.");
        }
        if ($type === 'select') {
            if ($options === [] || !array_is_list($options) || count($options) > 100) {
                throw new InvalidForm("Select {$key} needs 1-100 options.");
            }
            foreach ($options as $option) {
                if (!is_string($option) || trim($option) === '' || strlen($option) > 255 || !preg_match('//u', $option)) {
                    throw new InvalidForm("Invalid select option for {$key}.");
                }
            }
            if (count($options) !== count(array_unique($options))) {
                throw new InvalidForm("Select {$key} needs distinct options.");
            }
        } elseif ($options !== []) {
            throw new InvalidForm('Only select fields can have options.');
        }
        if ($visibleWhen !== null && (
            count($visibleWhen) !== 2 ||
            !array_key_exists('field', $visibleWhen) ||
            !array_key_exists('equals', $visibleWhen) ||
            !is_string($visibleWhen['field']) ||
            !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $visibleWhen['field']) ||
            (!is_null($visibleWhen['equals']) && !is_scalar($visibleWhen['equals']))
        )) {
            throw new InvalidForm("Invalid conditional visibility for {$key}.");
        }
        if ($rule !== null && (!preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $rule) || strlen($rule) > 120)) {
            throw new InvalidForm("Invalid registered rule name for {$key}.");
        }
        if ($maxLength !== null && ($maxLength < 1 || $maxLength > 10000 ||
            !in_array($type, ['text', 'textarea', 'email'], true))) {
            throw new InvalidForm("Invalid max length for {$key}.");
        }
        if (($min !== null || $max !== null) && $type !== 'number') {
            throw new InvalidForm('Number limits apply only to number fields.');
        }
        if (($min !== null && !is_finite((float) $min)) || ($max !== null && !is_finite((float) $max)) ||
            ($min !== null && $max !== null && $min > $max)) {
            throw new InvalidForm("Invalid numeric range for {$key}.");
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowed = ['key','label','type','required','options','visible_when','rule','max_length','min','max'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidForm('Unknown field definition property.');
        }
        foreach (['key', 'label', 'type'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new InvalidForm("Missing or invalid {$key}.");
            }
        }
        if (!is_bool($data['required'] ?? false) || !is_array($data['options'] ?? []) ||
            (!is_null($data['visible_when'] ?? null) && !is_array($data['visible_when'])) ||
            (!is_null($data['rule'] ?? null) && !is_string($data['rule'])) ||
            (!is_null($data['max_length'] ?? null) && !is_int($data['max_length']))) {
            throw new InvalidForm('Invalid field property type.');
        }
        foreach (['min', 'max'] as $limit) {
            if (isset($data[$limit]) && !is_int($data[$limit]) && !is_float($data[$limit])) {
                throw new InvalidForm('Invalid numeric limit.');
            }
        }
        return new self(
            $data['key'], $data['label'], $data['type'], $data['required'] ?? false,
            $data['options'] ?? [], $data['visible_when'] ?? null, $data['rule'] ?? null,
            $data['max_length'] ?? null, $data['min'] ?? null, $data['max'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key, 'label' => $this->label, 'type' => $this->type,
            'required' => $this->required, 'options' => $this->options,
            'visible_when' => $this->visibleWhen, 'rule' => $this->rule,
            'max_length' => $this->maxLength, 'min' => $this->min, 'max' => $this->max,
        ];
    }
}
