<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Contracts;

/**
 * Role-agnostic permission checker for Catalog Panel presentation controls and endpoints.
 * Never checks concrete roles; only checks fine-grained canonical catalog permissions/capabilities.
 */
interface CatalogPanelAuthorizationInterface
{
    public function canList(): bool;

    public function canView(string $code): bool;

    public function canCreate(): bool;

    public function canUpdate(string $code): bool;

    public function canPrice(string $code): bool;

    public function canActivate(string $code): bool;

    public function canDeactivate(string $code): bool;
}
