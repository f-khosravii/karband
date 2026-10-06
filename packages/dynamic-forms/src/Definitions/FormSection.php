<?php

declare(strict_types=1);

namespace Intl\DynamicForms\Definitions;

use Intl\DynamicForms\Exceptions\InvalidForm;

/** Immutable presentation/semantic grouping section. */
final readonly class FormSection
{
    /**
     * @param list<array{kind:string,key:string}> $elements
     */
    public function __construct(
        public string $key,
        public string $title,
        public ?string $description = null,
        public array $elements = [],
    ) {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key)) {
            throw new InvalidForm("Invalid section key: {$key}.");
        }
        if (trim($title) === '' || strlen($title) > 255 || !preg_match('//u', $title)) {
            throw new InvalidForm("Invalid title for section {$key}.");
        }
        if ($description !== null && (strlen($description) > 1000 || !preg_match('//u', $description))) {
            throw new InvalidForm("Invalid description for section {$key}.");
        }
        if (!array_is_list($elements)) {
            throw new InvalidForm("Section {$key} elements must be a list.");
        }
        foreach ($elements as $element) {
            if (!is_array($element) || !isset($element['kind'], $element['key']) ||
                !is_string($element['kind']) || !is_string($element['key'])) {
                throw new InvalidForm("Invalid element shape in section {$key}.");
            }
            if (!in_array($element['kind'], ['field', 'repeatable_group'], true)) {
                throw new InvalidForm("Unknown element kind '{$element['kind']}' in section {$key}.");
            }
            if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $element['key'])) {
                throw new InvalidForm("Invalid element key '{$element['key']}' in section {$key}.");
            }
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowed = ['key', 'title', 'description', 'elements'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidForm('Unknown property in section definition.');
        }
        if (!isset($data['key'], $data['title']) || !is_string($data['key']) || !is_string($data['title'])) {
            throw new InvalidForm('Missing or invalid key/title in section definition.');
        }
        if (isset($data['description']) && !is_null($data['description']) && !is_string($data['description'])) {
            throw new InvalidForm('Invalid description type in section definition.');
        }
        if (isset($data['elements']) && (!is_array($data['elements']) || !array_is_list($data['elements']))) {
            throw new InvalidForm('Invalid elements array in section definition.');
        }
        return new self(
            $data['key'],
            $data['title'],
            $data['description'] ?? null,
            $data['elements'] ?? [],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $res = [
            'key' => $this->key,
            'title' => $this->title,
            'elements' => $this->elements,
        ];
        if ($this->description !== null) {
            $res['description'] = $this->description;
        }
        return $res;
    }
}
