<?php

declare(strict_types=1);

namespace Karband\DocumentsLaravel\Services;

use Karband\Documents\Engine\DocumentValidator;

/**
 * FOR TRUSTED APPLICATION SERVICES ONLY, NOT A ROUTE OR CLIENT CALL.
 * A host TrustedFactProvider may call this to compute `documents_complete` before Workflow transitions.
 * If a set has not been reserved, completeness is false even for empty requirements.
 */
final readonly class TrustedDocumentFacts
{
    public function __construct(private DocumentStorage $documents, private DocumentValidator $validator) {}

    public function isComplete(string $serviceCode, string $resourceType, string $resourceId): bool
    {
        DocumentStorage::assertResource($serviceCode, $resourceType, $resourceId);
        $db = $this->documents->connection;
        $set = $db->table('document_sets')->where('service_code', $serviceCode)
            ->where('resource_type', $resourceType)->where('resource_id', $resourceId)->first();
        if ($set === null) {
            return false;
        }
        $definition = $this->documents->published($serviceCode, (int) $set->definition_version);
        $slots = $db->table('document_files')->where('set_id', $set->id)
            ->distinct()->pluck('requirement_code')->all();
        return $this->validator->missingRequired($definition, $slots) === [];
    }
}
