<?php

declare(strict_types=1);

namespace Karband\DynamicForms\Definitions;

use Karband\DynamicForms\Exceptions\InvalidForm;

/** Immutable definition for a list of repeated child field items. */
final readonly class RepeatableGroupDefinition
{
    /**
     * @param list<FormField> $fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $description = null,
        public int $minItems = 0,
        public int $maxItems = 100,
        public array $fields = [],
    ) {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key)) {
            throw new InvalidForm("Invalid repeatable group key: {$key}.");
        }
        if ($key === '_item_id') {
            throw new InvalidForm("Repeatable group key cannot be reserved identifier '_item_id'.");
        }
        if (trim($label) === '' || strlen($label) > 255 || !preg_match('//u', $label)) {
            throw new InvalidForm("Invalid label for repeatable group {$key}.");
        }
        if ($description !== null && (strlen($description) > 1000 || !preg_match('//u', $description))) {
            throw new InvalidForm("Invalid description for repeatable group {$key}.");
        }
        if ($minItems < 0 || $maxItems < 1 || $maxItems > 100 || $minItems > $maxItems) {
            throw new InvalidForm("Invalid min/max items range for repeatable group {$key}.");
        }
        if ($fields === [] || count($fields) > 100 || !array_is_list($fields)) {
            throw new InvalidForm("Repeatable group {$key} must contain 1-100 child fields.");
        }
        $seenChild = [];
        foreach ($fields as $field) {
            if (!$field instanceof FormField) {
                throw new InvalidForm("Invalid child field in repeatable group {$key}.");
            }
            if ($field->key === '_item_id') {
                throw new InvalidForm("Child field key cannot be reserved identifier '_item_id' in group {$key}.");
            }
            if (isset($seenChild[$field->key])) {
                throw new InvalidForm("Duplicate child field key '{$field->key}' in repeatable group {$key}.");
            }
            if ($field->visibleWhen !== null && !isset($seenChild[$field->visibleWhen['field']])) {
                throw new InvalidForm("Conditional child field '{$field->key}' must reference an earlier child field in group {$key}.");
            }
            $seenChild[$field->key] = true;
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowed = ['key', 'label', 'description', 'min_items', 'max_items', 'fields'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidForm('Unknown property in repeatable group definition.');
        }
        if (!isset($data['key'], $data['label']) || !is_string($data['key']) || !is_string($data['label'])) {
            throw new InvalidForm('Missing or invalid key/label in repeatable group definition.');
        }
        if (isset($data['description']) && !is_null($data['description']) && !is_string($data['description'])) {
            throw new InvalidForm('Invalid description type in repeatable group definition.');
        }
        if (isset($data['min_items']) && !is_int($data['min_items'])) {
            throw new InvalidForm('Invalid min_items type in repeatable group definition.');
        }
        if (isset($data['max_items']) && !is_int($data['max_items'])) {
            throw new InvalidForm('Invalid max_items type in repeatable group definition.');
        }
        if (!isset($data['fields']) || !is_array($data['fields']) || !array_is_list($data['fields'])) {
            throw new InvalidForm('Invalid fields array in repeatable group definition.');
        }
        $fields = [];
        foreach ($data['fields'] as $field) {
            if (!is_array($field)) {
                throw new InvalidForm('Invalid child field definition in repeatable group.');
            }
            $fields[] = FormField::fromArray($field);
        }
        return new self(
            $data['key'],
            $data['label'],
            $data['description'] ?? null,
            $data['min_items'] ?? 0,
            $data['max_items'] ?? 100,
            $fields,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $res = [
            'key' => $this->key,
            'label' => $this->label,
            'min_items' => $this->minItems,
            'max_items' => $this->maxItems,
            'fields' => array_map(static fn (FormField $f): array => $f->toArray(), $this->fields),
        ];
        if ($this->description !== null) {
            $res['description'] = $this->description;
        }
        return $res;
    }
}
