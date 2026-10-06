<?php

declare(strict_types=1);

namespace Karband\DynamicFormsLaravel\Contracts;

interface FormCatalogVerifier
{
    public function assertAvailable(string $serviceCode, bool $lock = false, bool $requireActive = false): void;
}
