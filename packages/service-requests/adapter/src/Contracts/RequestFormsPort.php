<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

interface RequestFormsPort
{
    /** @return array{version: int, revision: int} */
    public function reserve(string $serviceCode, string $resourceType, string $resourceId): array;

    /** @param list<string> $fieldKeys */
    public function assertPublishedFieldsContain(string $serviceCode, int $formVersion, array $fieldKeys): void;

    /** @param list<string> $fieldKeys */
    public function assertValidScopeFields(string $serviceCode, int $formVersion, array $fieldKeys): void;

    /**
     * @param array<string, mixed> $answers
     * @return array{version: int, revision: int, answers: array<string, mixed>}
     */
    public function save(string $serviceCode, string $resourceType, string $resourceId, array $answers, int $expectedRevision): array;

    /** @return array{version: int, revision: int, answers: array<string, mixed>} */
    public function read(string $serviceCode, string $resourceType, string $resourceId): array;

    /** @param array<string, mixed> $answers */
    public function validate(string $serviceCode, int $formVersion, array $answers): void;

    public function connectionName(): ?string;
}
