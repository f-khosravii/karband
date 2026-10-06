<?php

declare(strict_types=1);

namespace Karband\Documents\Engine;

use Karband\Documents\Definitions\DocumentDefinition;
use Karband\Documents\Definitions\DocumentRequirement;
use Karband\Documents\ValueObjects\UploadDescriptor;

final readonly class DocumentValidator
{
    /** @return list<string> */
    public function validateUpload(DocumentRequirement $slot, UploadDescriptor $upload): array
    {
        $errors = [];
        if (!in_array($upload->mimeType, $slot->allowedMimeTypes, true)) {
            $errors[] = 'mime_not_allowed';
        }
        if ($upload->bytes > $slot->maxBytes) {
            $errors[] = 'file_too_large';
        }
        return $errors;
    }

    /**
     * @param list<string> $presentSlots
     * @param array<string,mixed> $facts
     * @return list<string>
     */
    public function missingRequired(
        DocumentDefinition $definition,
        array $presentSlots,
        array $facts = [],
        ?string $currentCheckpoint = null,
    ): array {
        $present = array_fill_keys($presentSlots, true);
        $missing = [];
        foreach ($definition->requirements as $requirement) {
            if ($requirement->isRequired($facts, $currentCheckpoint) && !isset($present[$requirement->code])) {
                $missing[] = $requirement->code;
            }
        }
        return $missing;
    }
}
