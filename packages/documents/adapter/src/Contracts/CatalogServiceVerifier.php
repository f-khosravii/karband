<?php

declare(strict_types=1);

namespace Karband\DocumentsLaravel\Contracts;

interface CatalogServiceVerifier
{
    public function assertAvailable(string $serviceCode, bool $lock = false, bool $requireActive = false): void;
}
