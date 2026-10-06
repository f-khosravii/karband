<?php

declare(strict_types=1);

namespace Intl\DynamicForms\Definitions;

use Intl\DynamicForms\Exceptions\InvalidForm;

/** Immutable published snapshot; each local service has sequential versions. */
final readonly class FormDefinition
{
    /**
     * @param list<FormField> $fields
     * @param list<FormSection> $sections
     * @param list<RepeatableGroupDefinition> $repeatableGroups
     */
    public function __construct(
        public string $serviceCode,
        public int $version,
        public array $fields = [],
        public array $sections = [],
        public array $repeatableGroups = [],
    ) {
        if (strlen($serviceCode) > 120 || !preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $serviceCode) || $version < 1) {
            throw new InvalidForm('Invalid service code or form version.');
        }
        $totalElements = count($fields) + count($repeatableGroups);
        if ($totalElements < 1 || $totalElements > 100) {
            throw new InvalidForm('Forms must contain 1-100 fields or repeatable groups.');
        }
        $seenTopKeys = [];
        $topFields = [];
        foreach ($fields as $field) {
            if (!$field instanceof FormField || isset($seenTopKeys[$field->key])) {
                throw new InvalidForm('Invalid or duplicated field.');
            }
            if ($field->visibleWhen !== null && !isset($topFields[$field->visibleWhen['field']])) {
                throw new InvalidForm('Conditional fields must reference an earlier field.');
            }
            $seenTopKeys[$field->key] = 'field';
            $topFields[$field->key] = true;
        }

        $groupKeys = [];
        foreach ($repeatableGroups as $group) {
            if (!$group instanceof RepeatableGroupDefinition || isset($seenTopKeys[$group->key])) {
                throw new InvalidForm('Invalid or duplicated repeatable group.');
            }
            $seenTopKeys[$group->key] = 'repeatable_group';
            $groupKeys[$group->key] = true;
        }

        $seenSectionKeys = [];
        foreach ($sections as $section) {
            if (!$section instanceof FormSection || isset($seenSectionKeys[$section->key])) {
                throw new InvalidForm('Invalid or duplicated section.');
            }
            $seenSectionKeys[$section->key] = true;
            foreach ($section->elements as $elem) {
                $kind = $elem['kind'];
                $key = $elem['key'];
                if ($kind === 'field' && !isset($topFields[$key])) {
                    throw new InvalidForm("Section '{$section->key}' references missing field '{$key}'.");
                }
                if ($kind === 'repeatable_group' && !isset($groupKeys[$key])) {
                    throw new InvalidForm("Section '{$section->key}' references missing repeatable group '{$key}'.");
                }
            }
        }

        if (strlen(json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 65536) {
            throw new InvalidForm('Form definition exceeds 64 KiB.');
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowed = ['service_code', 'version', 'fields', 'sections', 'repeatable_groups'];
        if (array_diff(array_keys($data), $allowed) !== [] ||
            !is_string($data['service_code'] ?? null) || !is_int($data['version'] ?? null)) {
            throw new InvalidForm('Invalid form definition shape.');
        }

        $fields = [];
        if (isset($data['fields'])) {
            if (!is_array($data['fields']) || !array_is_list($data['fields'])) {
                throw new InvalidForm('Invalid fields array.');
            }
            foreach ($data['fields'] as $field) {
                if (!is_array($field)) {
                    throw new InvalidForm('Invalid field definition.');
                }
                $fields[] = FormField::fromArray($field);
            }
        }

        $sections = [];
        if (isset($data['sections'])) {
            if (!is_array($data['sections']) || !array_is_list($data['sections'])) {
                throw new InvalidForm('Invalid sections array.');
            }
            foreach ($data['sections'] as $sec) {
                if (!is_array($sec)) {
                    throw new InvalidForm('Invalid section definition.');
                }
                $sections[] = FormSection::fromArray($sec);
            }
        }

        $repeatableGroups = [];
        if (isset($data['repeatable_groups'])) {
            if (!is_array($data['repeatable_groups']) || !array_is_list($data['repeatable_groups'])) {
                throw new InvalidForm('Invalid repeatable_groups array.');
            }
            foreach ($data['repeatable_groups'] as $grp) {
                if (!is_array($grp)) {
                    throw new InvalidForm('Invalid repeatable group definition.');
                }
                $repeatableGroups[] = RepeatableGroupDefinition::fromArray($grp);
            }
        }

        return new self(
            $data['service_code'],
            $data['version'],
            $fields,
            $sections,
            $repeatableGroups,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $res = [
            'service_code' => $this->serviceCode,
            'version' => $this->version,
            'fields' => array_map(static fn (FormField $field): array => $field->toArray(), $this->fields),
        ];
        if ($this->sections !== []) {
            $res['sections'] = array_map(static fn (FormSection $s): array => $s->toArray(), $this->sections);
        }
        if ($this->repeatableGroups !== []) {
            $res['repeatable_groups'] = array_map(static fn (RepeatableGroupDefinition $g): array => $g->toArray(), $this->repeatableGroups);
        }
        return $res;
    }
}
