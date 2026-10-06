<?php

declare(strict_types=1);

namespace Karband\DynamicForms\Engine;

use Karband\DynamicForms\Contracts\RuleRegistry;
use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Definitions\FormField;
use Karband\DynamicForms\Definitions\RepeatableGroupDefinition;
use Karband\DynamicForms\Exceptions\InvalidForm;
use Karband\DynamicForms\ValueObjects\ValidationResult;

/** Validate untrusted JSON-like answers against a pinned published snapshot. */
final readonly class FormValidator
{
    public function __construct(private RuleRegistry $rules = new EmptyRuleRegistry())
    {
    }

    /** Ensure a definition never contains a missing rule, even before it is published. */
    public function assertPublishable(FormDefinition $form): void
    {
        foreach ($form->fields as $field) {
            if ($field->rule !== null) {
                $this->rules->resolve($field->rule);
            }
        }
        foreach ($form->repeatableGroups as $group) {
            foreach ($group->fields as $childField) {
                if ($childField->rule !== null) {
                    $this->rules->resolve($childField->rule);
                }
            }
        }
    }

    /** @param array<string,mixed> $input */
    public function validate(FormDefinition $form, array $input): ValidationResult
    {
        try {
            $payload = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return new ValidationResult([], ['_form' => 'Answers must be valid JSON values.']);
        }
        if (strlen($payload) > 65536) {
            return new ValidationResult([], ['_form' => 'Answers exceed 64 KiB.']);
        }

        $errors = [];
        $answers = [];

        $knownFields = array_fill_keys(array_map(static fn (FormField $f): string => $f->key, $form->fields), true);
        $knownGroups = array_fill_keys(array_map(static fn (RepeatableGroupDefinition $g): string => $g->key, $form->repeatableGroups), true);

        foreach ($input as $key => $_) {
            if (!is_string($key) || (!isset($knownFields[$key]) && !isset($knownGroups[$key]))) {
                $errors[(string) $key] = 'Unknown field.';
            }
        }

        // 1. Validate top-level fields
        foreach ($form->fields as $field) {
            $visible = $field->visibleWhen === null ||
                (array_key_exists($field->visibleWhen['field'], $answers) &&
                    $answers[$field->visibleWhen['field']] === $field->visibleWhen['equals']);
            if (!$visible) {
                if (array_key_exists($field->key, $input)) {
                    $errors[$field->key] = 'Hidden fields must not be submitted.';
                }
                continue;
            }
            if (!array_key_exists($field->key, $input) || $input[$field->key] === null ||
                ($input[$field->key] === '' && $field->type !== 'checkbox')) {
                if ($field->required) {
                    $errors[$field->key] = 'This field is required.';
                }
                continue;
            }
            $value = $input[$field->key];
            $error = $this->validateValue($field, $value);
            if ($error !== null) {
                $errors[$field->key] = $error;
                continue;
            }
            if ($field->rule !== null) {
                try {
                    $error = $this->rules->resolve($field->rule)->validate($value, $answers);
                } catch (\Throwable) {
                    throw new InvalidForm("Custom validation rule failed for {$field->key}.");
                }
                if ($error !== null) {
                    $errors[$field->key] = $error;
                    continue;
                }
            }
            $answers[$field->key] = $value;
        }

        // 2. Validate repeatable groups
        foreach ($form->repeatableGroups as $group) {
            $gKey = $group->key;
            if (!array_key_exists($gKey, $input) || $input[$gKey] === null) {
                if ($group->minItems > 0) {
                    $errors[$gKey] = "At least {$group->minItems} item(s) required.";
                } else {
                    $answers[$gKey] = [];
                }
                continue;
            }

            $items = $input[$gKey];
            if (!is_array($items) || !array_is_list($items)) {
                $errors[$gKey] = 'Expected a list of items.';
                continue;
            }

            $itemCount = count($items);
            if ($itemCount < $group->minItems) {
                $errors[$gKey] = "At least {$group->minItems} item(s) required.";
                continue;
            }
            if ($itemCount > $group->maxItems) {
                $errors[$gKey] = "At most {$group->maxItems} item(s) allowed.";
                continue;
            }

            $validatedGroupItems = [];
            $seenItemIds = [];
            $knownChildKeys = array_fill_keys(array_map(static fn (FormField $f): string => $f->key, $group->fields), true);
            $knownChildKeys['_item_id'] = true;

            foreach ($items as $idx => $item) {
                if (!is_array($item) || array_is_list($item)) {
                    $errors["{$gKey}.{$idx}"] = 'Expected an associative item object.';
                    continue;
                }

                $itemId = $item['_item_id'] ?? null;
                if (!is_string($itemId) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $itemId) || $itemId === '_add' || $itemId === '_remove') {
                    $errors["{$gKey}.{$idx}._item_id"] = 'Missing or invalid item ID.';
                    continue;
                }

                if (isset($seenItemIds[$itemId])) {
                    $errors["{$gKey}.{$itemId}._item_id"] = 'Duplicate item ID.';
                    continue;
                }
                $seenItemIds[$itemId] = true;

                $itemPathPrefix = "{$gKey}.{$itemId}";

                // Unknown child keys check
                foreach ($item as $k => $_) {
                    if (!is_string($k) || !isset($knownChildKeys[$k])) {
                        $errors["{$itemPathPrefix}.{$k}"] = 'Unknown field.';
                    }
                }

                $validatedItem = ['_item_id' => $itemId];

                foreach ($group->fields as $childField) {
                    $fKey = $childField->key;
                    $cVisible = $childField->visibleWhen === null ||
                        (array_key_exists($childField->visibleWhen['field'], $validatedItem) &&
                            $validatedItem[$childField->visibleWhen['field']] === $childField->visibleWhen['equals']);

                    if (!$cVisible) {
                        if (array_key_exists($fKey, $item)) {
                            $errors["{$itemPathPrefix}.{$fKey}"] = 'Hidden fields must not be submitted.';
                        }
                        continue;
                    }

                    if (!array_key_exists($fKey, $item) || $item[$fKey] === null ||
                        ($item[$fKey] === '' && $childField->type !== 'checkbox')) {
                        if ($childField->required) {
                            $errors["{$itemPathPrefix}.{$fKey}"] = 'This field is required.';
                        }
                        continue;
                    }

                    $val = $item[$fKey];
                    $err = $this->validateValue($childField, $val);
                    if ($err !== null) {
                        $errors["{$itemPathPrefix}.{$fKey}"] = $err;
                        continue;
                    }

                    if ($childField->rule !== null) {
                        try {
                            $err = $this->rules->resolve($childField->rule)->validate($val, $validatedItem);
                        } catch (\Throwable) {
                            throw new InvalidForm("Custom validation rule failed for {$gKey}.{$itemId}.{$fKey}.");
                        }
                        if ($err !== null) {
                            $errors["{$itemPathPrefix}.{$fKey}"] = $err;
                            continue;
                        }
                    }

                    $validatedItem[$fKey] = $val;
                }

                $validatedGroupItems[] = $validatedItem;
            }

            $answers[$gKey] = $validatedGroupItems;
        }

        return new ValidationResult($answers, $errors);
    }

    private function validateValue(FormField $field, mixed $value): ?string
    {
        switch ($field->type) {
            case 'text':
            case 'textarea':
            case 'email':
                if (!is_string($value) || !preg_match('//u', $value) ||
                    ($field->maxLength !== null && mbSafeLength($value) > $field->maxLength)) {
                    return 'Invalid text value or length.';
                }
                if ($field->required && trim($value) === '') {
                    return 'This field is required.';
                }
                if ($field->type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return 'Invalid email address.';
                }
                return null;
            case 'number':
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) ||
                    ($field->min !== null && $value < $field->min) ||
                    ($field->max !== null && $value > $field->max)) {
                    return 'Invalid numeric value.';
                }
                return null;
            case 'select':
                return is_string($value) && in_array($value, $field->options, true) ? null : 'Invalid option.';
            case 'checkbox':
                return is_bool($value) ? null : 'Expected a boolean.';
            case 'date':
                if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) {
                    return 'Expected an ISO date (YYYY-MM-DD).';
                }
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                return $date !== false && $date->format('Y-m-d') === $value ? null : 'Invalid date.';
        }
        return 'Unsupported value type.';
    }
}

/** No mbstring extension required: UTF-8 text length from PCRE. */
function mbSafeLength(string $value): int
{
    return preg_match_all('/./us', $value) ?: 0;
}
