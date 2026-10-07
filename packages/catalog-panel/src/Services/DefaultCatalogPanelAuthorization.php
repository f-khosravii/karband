<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Services;

use Illuminate\Support\Facades\Gate;
use Karband\CatalogPanel\Contracts\CatalogPanelAuthorizationInterface;

/**
 * Default implementation of CatalogPanelAuthorizationInterface.
 * Checks fine-grained capabilities without role knowledge.
 * Fails closed: denies access by default if gate is not defined or denied.
 */
final class DefaultCatalogPanelAuthorization implements CatalogPanelAuthorizationInterface
{
    public function canList(): bool
    {
        return Gate::has('catalog.service.list') && (bool) Gate::allows('catalog.service.list');
    }

    public function canView(string $code): bool
    {
        return Gate::has('catalog.service.view') && (bool) Gate::allows('catalog.service.view', $code);
    }

    public function canCreate(): bool
    {
        return Gate::has('catalog.service.create') && (bool) Gate::allows('catalog.service.create');
    }

    public function canUpdate(string $code): bool
    {
        return Gate::has('catalog.service.update') && (bool) Gate::allows('catalog.service.update', $code);
    }

    public function canPrice(string $code): bool
    {
        return Gate::has('catalog.service.price.update') && (bool) Gate::allows('catalog.service.price.update', $code);
    }

    public function canActivate(string $code): bool
    {
        return Gate::has('catalog.service.activate') && (bool) Gate::allows('catalog.service.activate', $code);
    }

    public function canDeactivate(string $code): bool
    {
        return Gate::has('catalog.service.deactivate') && (bool) Gate::allows('catalog.service.deactivate', $code);
    }
}
