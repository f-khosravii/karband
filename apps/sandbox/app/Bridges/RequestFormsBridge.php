<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\DynamicForms\Engine\FormValidator;
use Karband\DynamicFormsLaravel\Services\FormAnswers;
use Karband\DynamicFormsLaravel\Services\FormRegistry;
use Karband\DynamicFormsLaravel\Services\FormStorage;
use Karband\ServiceRequests\Policies\CorrectionPath;
use Karband\ServiceRequestsLaravel\Contracts\RequestFormsPort;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;

final readonly class RequestFormsBridge implements RequestFormsPort
{
    public function __construct(
        private FormStorage $storage,
        private FormAnswers $forms,
        private FormRegistry $registry,
        private FormValidator $validator = new FormValidator(),
    ) {}

    public function reserve(string $serviceCode, string $resourceType, string $resourceId): array
    {
        return $this->forms->reserve($serviceCode, $resourceType, $resourceId);
    }

    public function assertPublishedFieldsContain(string $serviceCode, int $formVersion, array $fieldKeys): void
    {
        $definition = $this->registry->loadPublished($serviceCode, $formVersion);
        $keys = array_map(static fn ($f): string => $f->key, $definition->fields);
        foreach ($fieldKeys as $field) {
            if (!in_array($field, $keys, true)) {
                throw new RequestConflict('Recipient mapping must point to a published form field.');
            }
        }
    }

    public function assertValidScopeFields(string $serviceCode, int $formVersion, array $fieldKeys): void
    {
        $form = $this->registry->loadPublished($serviceCode, $formVersion);
        $fields = array_map(static fn ($f): string => $f->key, $form->fields);
        $groups = [];
        $groupFields = [];
        foreach ($form->repeatableGroups ?? [] as $group) {
            $groups[] = $group->key;
            $groupFields[$group->key] = array_map(static fn ($f): string => $f->key, $group->fields);
        }
        foreach ($fieldKeys as $field) {
            try {
                $path = CorrectionPath::parse($field);
            } catch (\InvalidArgumentException) {
                throw new RequestConflict('Unknown field in correction allowlist.');
            }
            if ($path->kind === CorrectionPath::KIND_FLAT_FIELD) {
                if (!in_array($path->field, $fields, true)) {
                    throw new RequestConflict('Unknown field in correction allowlist.');
                }
            } else {
                if (!in_array($path->group, $groups, true)) {
                    throw new RequestConflict('Unknown field in correction allowlist.');
                }
                if ($path->kind === CorrectionPath::KIND_CHILD_FIELD) {
                    $validChildren = $groupFields[$path->group] ?? [];
                    if (!in_array($path->field, $validChildren, true)) {
                        throw new RequestConflict('Unknown field in correction allowlist.');
                    }
                }
            }
        }
    }

    public function save(string $serviceCode, string $resourceType, string $resourceId, array $answers, int $expectedRevision): array
    {
        return $this->forms->save($serviceCode, $resourceType, $resourceId, $answers, $expectedRevision);
    }

    public function read(string $serviceCode, string $resourceType, string $resourceId): array
    {
        return $this->forms->read($serviceCode, $resourceType, $resourceId);
    }

    public function validate(string $serviceCode, int $formVersion, array $answers): void
    {
        $form = $this->registry->loadPublished($serviceCode, $formVersion);
        $result = $this->validator->validate($form, $answers);
        if (!$result->valid()) {
            throw new RequestConflict('Form must be valid before submission.');
        }
    }

    public function connectionName(): ?string
    {
        return $this->storage->connection->getName();
    }
}
