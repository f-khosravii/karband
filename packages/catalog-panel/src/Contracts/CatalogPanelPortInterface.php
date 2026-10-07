<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Contracts;

use Karband\CatalogPanel\Dto\CatalogServiceDetail;
use Karband\CatalogPanel\Dto\CatalogServiceItem;
use Karband\CatalogPanel\Dto\CatalogServiceRevisionItem;

/**
 * Consumer-owned port for the Catalog Panel.
 * Implemented by host/composition bridges (e.g. Sandbox, Tax, Legal) to interact
 * with underlying Service Catalog domain operations without coupling the presentation
 * package to internal adapter layers.
 */
interface CatalogPanelPortInterface
{
    /**
     * @return list<CatalogServiceItem>
     */
    public function listServices(?string $search = null, ?string $status = null): array;

    public function getService(string $code): CatalogServiceDetail;

    public function getRevision(string $code, int $revision): CatalogServiceDetail;

    /**
     * @return list<CatalogServiceRevisionItem>
     */
    public function getHistory(string $code): array;

    public function createService(string $code, string $name, ?string $description = null): CatalogServiceDetail;

    public function updateMetadata(string $code, int $expectedRevision, string $name, ?string $description = null): CatalogServiceDetail;

    /**
     * @param array<string, string> $refundEvents
     */
    public function updateFinancialTerms(
        string $code,
        int $expectedRevision,
        int $fixedPriceMinor,
        string $currency,
        array $refundEvents = [],
        string $pricingMode = 'fixed_catalog',
    ): CatalogServiceDetail;

    public function activateService(string $code, int $expectedRevision): CatalogServiceDetail;

    public function deactivateService(string $code, int $expectedRevision): CatalogServiceDetail;
}
