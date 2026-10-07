<?php

declare(strict_types=1);

namespace App\Bridges;

use Karband\Authorization\AccessRequest;
use Karband\Authorization\AuthorizerInterface;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\CatalogPanel\Contracts\CatalogPanelAuthorizationInterface;
use Karband\CatalogPanel\Contracts\CatalogPanelPortInterface;
use Karband\CatalogPanel\Dto\CatalogServiceDetail;
use Karband\CatalogPanel\Dto\CatalogServiceItem;
use Karband\CatalogPanel\Dto\CatalogServiceRevisionItem;
use Karband\CatalogPanel\Support\MoneyFormatter;
use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\ServiceStatus;
use Karband\ServiceCatalogLaravel\Services\ServiceCatalog;

final readonly class CatalogPanelBridge implements CatalogPanelPortInterface, CatalogPanelAuthorizationInterface
{
    public function __construct(
        private ServiceCatalog $catalog,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $authorizer,
    ) {
    }

    public function listServices(?string $search = null, ?string $status = null): array
    {
        $definitions = $this->catalog->listAuthorized(limit: 200);
        $items = [];

        foreach ($definitions as $def) {
            // Apply search filter if provided
            if ($search !== null && $search !== '') {
                $searchLower = mb_strtolower($search);
                $codeMatch = str_contains(mb_strtolower($def->code), $searchLower);
                $nameMatch = str_contains(mb_strtolower($def->name), $searchLower);
                if (!$codeMatch && !$nameMatch) {
                    continue;
                }
            }

            // Apply status filter if provided
            if ($status !== null && $status !== '') {
                if ($def->status->value !== $status) {
                    continue;
                }
            }

            $items[] = $this->toItem($def);
        }

        return $items;
    }

    public function getService(string $code): CatalogServiceDetail
    {
        $def = $this->catalog->get($code);
        return $this->toDetail($def);
    }

    public function getRevision(string $code, int $revision): CatalogServiceDetail
    {
        $def = $this->catalog->getRevision($code, $revision);
        return $this->toDetail($def);
    }

    public function getHistory(string $code): array
    {
        $definitions = $this->catalog->history($code);
        $items = [];
        foreach ($definitions as $def) {
            $items[] = $this->toRevisionItem($def);
        }
        return $items;
    }

    public function createService(string $code, string $name, ?string $description = null): CatalogServiceDetail
    {
        $draft = new ServiceDraft(
            code: $code,
            name: $name,
            description: $description,
            settings: [],
            workflow: null,
        );
        $created = $this->catalog->create($draft);
        return $this->toDetail($created);
    }

    public function updateMetadata(string $code, int $expectedRevision, string $name, ?string $description = null): CatalogServiceDetail
    {
        $updated = $this->catalog->updateMetadata($code, $expectedRevision, $name, $description);
        return $this->toDetail($updated);
    }

    public function updateFinancialTerms(
        string $code,
        int $expectedRevision,
        int $fixedPriceMinor,
        string $currency,
        array $refundEvents = [],
        string $pricingMode = 'fixed_catalog',
    ): CatalogServiceDetail {
        $updated = $this->catalog->updateFinancialTerms(
            $code,
            $expectedRevision,
            $fixedPriceMinor,
            $currency,
            $refundEvents,
            $pricingMode,
        );
        return $this->toDetail($updated);
    }

    public function activateService(string $code, int $expectedRevision): CatalogServiceDetail
    {
        $updated = $this->catalog->activate($code, $expectedRevision);
        return $this->toDetail($updated);
    }

    public function deactivateService(string $code, int $expectedRevision): CatalogServiceDetail
    {
        $updated = $this->catalog->deactivate($code, $expectedRevision);
        return $this->toDetail($updated);
    }

    // --- Authorization implementation (fine-grained, role-agnostic) ---

    public function canList(): bool
    {
        return $this->checkAbility('catalog.service.list', 'catalog.collection', 'local');
    }

    public function canView(string $code): bool
    {
        return $this->checkAbility('catalog.service.view', 'catalog.service', $code);
    }

    public function canCreate(): bool
    {
        return $this->checkAbility('catalog.service.create', 'catalog.service', 'new');
    }

    public function canUpdate(string $code): bool
    {
        return $this->checkAbility('catalog.service.update', 'catalog.service', $code);
    }

    public function canPrice(string $code): bool
    {
        return $this->checkAbility('catalog.service.price.update', 'catalog.service', $code);
    }

    public function canActivate(string $code): bool
    {
        return $this->checkAbility('catalog.service.activate', 'catalog.service', $code);
    }

    public function canDeactivate(string $code): bool
    {
        return $this->checkAbility('catalog.service.deactivate', 'catalog.service', $code);
    }

    private function checkAbility(string $ability, string $resourceType, string $id): bool
    {
        try {
            $actor = $this->actors->currentActor();
            $this->authorizer->authorize($actor, new AccessRequest($ability, $resourceType, $id));
            return true;
        } catch (\Karband\Authorization\AuthorizationDenied) {
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    // --- Transformation helpers ---

    private function toItem(ServiceDefinition $def): CatalogServiceItem
    {
        $settings = $def->settings;
        $priceMinor = isset($settings['billing_fixed_price_minor']) ? (int) $settings['billing_fixed_price_minor'] : null;
        $currency = isset($settings['billing_currency']) ? (string) $settings['billing_currency'] : null;

        return new CatalogServiceItem(
            code: $def->code,
            name: $def->name,
            description: $def->description,
            status: $def->status->value,
            revision: $def->revision,
            fixedPriceMinor: $priceMinor,
            currency: $currency,
            formattedPrice: MoneyFormatter::format($priceMinor, $currency),
            isActive: $def->status === ServiceStatus::Active,
        );
    }

    private function toDetail(ServiceDefinition $def): CatalogServiceDetail
    {
        $settings = $def->settings;
        $priceMinor = isset($settings['billing_fixed_price_minor']) ? (int) $settings['billing_fixed_price_minor'] : null;
        $currency = isset($settings['billing_currency']) ? (string) $settings['billing_currency'] : null;
        $pricingMode = isset($settings['pricing_mode']) ? (string) $settings['pricing_mode'] : null;
        $refundEvents = isset($settings['refund_events']) && is_array($settings['refund_events']) ? $settings['refund_events'] : [];

        return new CatalogServiceDetail(
            code: $def->code,
            name: $def->name,
            description: $def->description,
            status: $def->status->value,
            revision: $def->revision,
            fixedPriceMinor: $priceMinor,
            currency: $currency,
            formattedPrice: MoneyFormatter::format($priceMinor, $currency),
            pricingMode: $pricingMode,
            refundEvents: $refundEvents,
            settings: $settings,
            workflowId: $def->workflow?->id,
            workflowVersion: $def->workflow?->version,
            isActive: $def->status === ServiceStatus::Active,
        );
    }

    private function toRevisionItem(ServiceDefinition $def): CatalogServiceRevisionItem
    {
        $settings = $def->settings;
        $priceMinor = isset($settings['billing_fixed_price_minor']) ? (int) $settings['billing_fixed_price_minor'] : null;
        $currency = isset($settings['billing_currency']) ? (string) $settings['billing_currency'] : null;
        $pricingMode = isset($settings['pricing_mode']) ? (string) $settings['pricing_mode'] : null;
        $refundEvents = isset($settings['refund_events']) && is_array($settings['refund_events']) ? $settings['refund_events'] : [];

        return new CatalogServiceRevisionItem(
            code: $def->code,
            revision: $def->revision,
            name: $def->name,
            description: $def->description,
            status: $def->status->value,
            fixedPriceMinor: $priceMinor,
            currency: $currency,
            formattedPrice: MoneyFormatter::format($priceMinor, $currency),
            pricingMode: $pricingMode,
            refundEvents: $refundEvents,
            settings: $settings,
            workflowId: $def->workflow?->id,
            workflowVersion: $def->workflow?->version,
        );
    }
}
