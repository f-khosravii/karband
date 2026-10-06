<?php

declare(strict_types=1);

namespace Karband\Documents\Definitions;

use Karband\Documents\Exceptions\InvalidDocumentDefinition;

final readonly class DocumentDefinition
{
    /** @param list<DocumentRequirement> $requirements */
    public function __construct(public string $serviceCode, public int $version, public array $requirements)
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $serviceCode) || $version < 1) {
            throw new InvalidDocumentDefinition('Invalid service code or document definition version.');
        }
        if (!array_is_list($requirements) || count($requirements) > 100) {
            throw new InvalidDocumentDefinition('Requirements must be a list with up to 100 items.');
        }
        $seen = [];
        foreach ($requirements as $requirement) {
            if (!$requirement instanceof DocumentRequirement || isset($seen[$requirement->code])) {
                throw new InvalidDocumentDefinition('Requirements must have distinct codes.');
            }
            $seen[$requirement->code] = true;
        }
    }

    public function requirement(string $code): ?DocumentRequirement
    {
        foreach ($this->requirements as $item) {
            if ($item->code === $code) {
                return $item;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['service_code' => $this->serviceCode, 'version' => $this->version,
            'requirements' => array_map(static fn (DocumentRequirement $r): array => $r->toArray(), $this->requirements)];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (array_diff(array_keys($data), ['service_code', 'version', 'requirements']) !== [] ||
            !is_string($data['service_code'] ?? null) || !is_int($data['version'] ?? null) ||
            !is_array($data['requirements'] ?? null) || !array_is_list($data['requirements'])) {
            throw new InvalidDocumentDefinition('Invalid document definition schema.');
        }
        return new self($data['service_code'], $data['version'], array_map(
            static function ($item): DocumentRequirement {
                if (!is_array($item)) {
                    throw new InvalidDocumentDefinition('Invalid requirement entry.');
                }
                return DocumentRequirement::fromArray($item);
            }, $data['requirements']
        ));
    }
}
