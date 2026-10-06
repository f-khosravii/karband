<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\ServiceCatalog\Financial\PricingMode;
use Karband\ServiceCatalog\Financial\ServiceFinancialTerms;
use Karband\ServiceCatalogLaravel\Services\CatalogStorage;
use Karband\ServiceRequests\Policies\RequestPolicy;
use Karband\ServiceRequestsLaravel\Contracts\RequestCatalogPort;
use Karband\ServiceRequestsLaravel\Exceptions\QuoteConflict;
use Karband\ServiceRequestsLaravel\Exceptions\RequestConflict;

final readonly class RequestCatalogBridge implements RequestCatalogPort
{
    public function __construct(private CatalogStorage $storage) {}

    public function getPolicy(string $serviceCode): RequestPolicy
    {
        $db = $this->storage->connection;
        $catalog = $db->table('catalog_services')->where('code', $serviceCode)->lockForUpdate()->first();
        if ($catalog === null || $catalog->status !== 'active') {
            throw new RequestConflict('Requested service is unavailable.');
        }
        $settings = json_decode((string) $catalog->settings, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings) || !is_array($settings['request_management'] ?? null)) {
            throw new RequestConflict('Service has no explicit Phase 15 request policy.');
        }
        return RequestPolicy::fromArray($settings['request_management']);
    }

    public function assertServiceActive(string $serviceCode): void
    {
        $db = $this->storage->connection;
        $service = $db->table('catalog_services')->where('code', $serviceCode)->first();
        if ($service === null || $service->status !== 'active') {
            throw new RequestConflict('Cannot restore draft while service is inactive.');
        }
    }

    public function assertQuotePricingSupported(string $serviceCode): void
    {
        $db = $this->storage->connection;
        $catalog = $db->table('catalog_services')->where('code', $serviceCode)->first();
        if ($catalog === null || $catalog->status !== 'active') {
            throw new QuoteConflict('Requested catalog service is unavailable.');
        }
        $settings = json_decode((string) $catalog->settings, true, 512, JSON_THROW_ON_ERROR);
        $terms = ServiceFinancialTerms::fromCatalogSettings($settings);
        if ($terms->pricingMode !== PricingMode::RequestQuote) {
            throw new QuoteConflict('Quote proposals are only supported for services with pricing_mode = request_quote.');
        }
    }

    public function connectionName(): ?string
    {
        return $this->storage->connection->getName();
    }
}
