<?php

declare(strict_types=1);

namespace Karband\Documents\Definitions;

use Karband\Documents\Exceptions\InvalidDocumentDefinition;

/** A single document slot. Multiple documents require different slot codes. */
final readonly class DocumentRequirement
{
    /**
     * @param list<string> $allowedMimeTypes
     * @param array{field:string,equals:mixed}|null $requiredWhen
     * @param list<string>|null $requiredAt
     */
    public function __construct(
        public string $code,
        public string $label,
        public bool $required,
        public array $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'],
        public int $maxBytes = 10_485_760,
        public ?array $requiredWhen = null,
        public ?array $requiredAt = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $code)) {
            throw new InvalidDocumentDefinition('Invalid document requirement code.');
        }
        if (trim($label) === '' || strlen($label) > 255) {
            throw new InvalidDocumentDefinition('Invalid document requirement label.');
        }
        if ($maxBytes < 1 || $maxBytes > 52_428_800) {
            throw new InvalidDocumentDefinition('Max upload must be between 1 byte and 50 MiB.');
        }
        if ($allowedMimeTypes === [] || count($allowedMimeTypes) > 20 || array_values(array_unique($allowedMimeTypes)) !== $allowedMimeTypes) {
            throw new InvalidDocumentDefinition('Allowed MIME types must be a unique non-empty list.');
        }
        foreach ($allowedMimeTypes as $mime) {
            if (!is_string($mime) || !preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~D', $mime)) {
                throw new InvalidDocumentDefinition('Invalid MIME type.');
            }
        }
        if ($this->requiredWhen !== null) {
            if (array_diff(array_keys($this->requiredWhen), ['field', 'equals']) !== [] ||
                !isset($this->requiredWhen['field']) || !is_string($this->requiredWhen['field']) ||
                !preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $this->requiredWhen['field']) ||
                !array_key_exists('equals', $this->requiredWhen)) {
                throw new InvalidDocumentDefinition('Invalid required_when condition schema.');
            }
        }
        if ($this->requiredAt !== null) {
            if (!array_is_list($this->requiredAt) || $this->requiredAt === [] || count($this->requiredAt) > 20) {
                throw new InvalidDocumentDefinition('Invalid required_at checkpoints list.');
            }
            foreach ($this->requiredAt as $cp) {
                if (!is_string($cp) || !preg_match('/^[a-z0-9_.-]{1,120}$/D', $cp)) {
                    throw new InvalidDocumentDefinition('Invalid required_at checkpoint name.');
                }
            }
            if (array_values(array_unique($this->requiredAt)) !== $this->requiredAt) {
                throw new InvalidDocumentDefinition('Duplicate checkpoint in required_at.');
            }
        }
    }

    /**
     * @param array<string,mixed> $facts
     */
    public function isRequired(array $facts = [], ?string $currentCheckpoint = null): bool
    {
        if ($this->required) {
            return true;
        }

        $conditionMet = $this->requiredWhen === null || (
            array_key_exists($this->requiredWhen['field'], $facts)
            && $facts[$this->requiredWhen['field']] === $this->requiredWhen['equals']
        );

        $checkpointMet = $this->requiredAt !== null && $this->requiredAt !== []
            && $currentCheckpoint !== null
            && in_array($currentCheckpoint, $this->requiredAt, true);

        if ($this->requiredWhen !== null && $this->requiredAt !== null && $this->requiredAt !== []) {
            return $conditionMet && $checkpointMet;
        }

        if ($this->requiredWhen !== null) {
            return $conditionMet;
        }

        if ($this->requiredAt !== null && $this->requiredAt !== []) {
            return $checkpointMet;
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'code' => $this->code,
            'label' => $this->label,
            'required' => $this->required,
            'allowed_mime_types' => $this->allowedMimeTypes,
            'max_bytes' => $this->maxBytes,
        ];
        if ($this->requiredWhen !== null) {
            $data['required_when'] = $this->requiredWhen;
        }
        if ($this->requiredAt !== null) {
            $data['required_at'] = $this->requiredAt;
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowedKeys = ['code', 'label', 'required', 'allowed_mime_types', 'max_bytes', 'required_when', 'required_at'];
        if (array_diff(array_keys($data), $allowedKeys) !== [] || !is_string($data['code'] ?? null) ||
            !is_string($data['label'] ?? null) || !is_bool($data['required'] ?? null) ||
            !is_array($data['allowed_mime_types'] ?? null) || !array_is_list($data['allowed_mime_types']) ||
            !is_int($data['max_bytes'] ?? null)) {
            throw new InvalidDocumentDefinition('Invalid document requirement schema.');
        }

        $requiredWhen = $data['required_when'] ?? null;
        if ($requiredWhen !== null && !is_array($requiredWhen)) {
            throw new InvalidDocumentDefinition('Invalid required_when in document requirement.');
        }

        $requiredAt = $data['required_at'] ?? null;
        if ($requiredAt !== null && (!is_array($requiredAt) || !array_is_list($requiredAt))) {
            throw new InvalidDocumentDefinition('Invalid required_at in document requirement.');
        }

        return new self(
            $data['code'],
            $data['label'],
            $data['required'],
            $data['allowed_mime_types'],
            $data['max_bytes'],
            $requiredWhen,
            $requiredAt,
        );
    }
}
