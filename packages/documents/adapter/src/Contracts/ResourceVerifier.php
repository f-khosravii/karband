<?php

declare(strict_types=1);

namespace Karband\DocumentsLaravel\Contracts;

/** Verify that this real host resource belongs to this catalog service. Do not trust request input. */
interface ResourceVerifier
{
    public function existsForService(string $serviceCode, string $resourceType, string $resourceId): bool;
}
