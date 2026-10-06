<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\DocumentsLaravel\Contracts\CatalogServiceVerifier;
use Karband\DocumentsLaravel\Exceptions\DocumentNotFound;
use Karband\ServiceCatalogLaravel\Services\CatalogStorage;

final readonly class CatalogServiceVerifierBridge implements CatalogServiceVerifier
{
    public function __construct(private CatalogStorage $catalog)
    {
    }

    public function assertAvailable(string $serviceCode, bool $lock = false, bool $requireActive = false): void
    {
        $query = $this->catalog->connection->table('catalog_services')->where('code', $serviceCode);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null || $row->status === 'archived' || ($requireActive && $row->status !== 'active')) {
            throw new DocumentNotFound('Service not available.');
        }
    }
}
