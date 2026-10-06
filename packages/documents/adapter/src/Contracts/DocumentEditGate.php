<?php

declare(strict_types=1);

namespace Karband\DocumentsLaravel\Contracts;

interface DocumentEditGate
{
    public function assertDocumentSlot(
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        string $action = 'upload'
    ): void;
}
