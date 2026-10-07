<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Karband\CatalogPanel\Contracts\CatalogPanelAuthorizationInterface;
use Karband\CatalogPanel\Contracts\CatalogPanelPortInterface;
use Karband\CatalogPanel\Support\MoneyFormatter;

final class CatalogEditController extends Controller
{
    public function __construct(
        private readonly CatalogPanelPortInterface $port,
        private readonly CatalogPanelAuthorizationInterface $auth,
    ) {
    }

    public function edit(string $code)
    {
        if (!$this->auth->canUpdate($code)) {
            abort(403, 'Unauthorized to edit service metadata.');
        }

        $service = $this->port->getService($code);

        return view('catalog-panel::edit', [
            'service' => $service,
        ]);
    }

    public function update(Request $request, string $code)
    {
        if (!$this->auth->canUpdate($code)) {
            abort(403, 'Unauthorized to edit service metadata.');
        }

        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'name' => ['required', 'string', 'min:2', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $updated = $this->port->updateMetadata(
            code: $code,
            expectedRevision: (int) $validated['expected_revision'],
            name: $validated['name'],
            description: $validated['description'] ?? null,
        );

        return redirect()
            ->route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $updated->code])
            ->with('success', "Service '{$updated->name}' metadata updated (Revision {$updated->revision}).");
    }

    public function editPrice(string $code)
    {
        if (!$this->auth->canPrice($code)) {
            abort(403, 'Unauthorized to configure service pricing.');
        }

        $service = $this->port->getService($code);

        return view('catalog-panel::edit-price', [
            'service' => $service,
            'priceMajor' => MoneyFormatter::toMajorString($service->fixedPriceMinor, $service->currency),
        ]);
    }

    public function updatePrice(Request $request, string $code)
    {
        if (!$this->auth->canPrice($code)) {
            abort(403, 'Unauthorized to configure service pricing.');
        }

        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'price' => ['required', 'string', 'regex:/^\d+(\.\d+)?$/D'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/i'],
            'pricing_mode' => ['nullable', 'string', 'in:fixed_catalog'],
        ]);

        $currency = strtoupper($validated['currency']);
        $fixedPriceMinor = MoneyFormatter::toMinorUnits((string) $validated['price'], $currency);

        $service = $this->port->getService($code);
        $refundEvents = $service->refundEvents;

        $updated = $this->port->updateFinancialTerms(
            code: $code,
            expectedRevision: (int) $validated['expected_revision'],
            fixedPriceMinor: $fixedPriceMinor,
            currency: $currency,
            refundEvents: $refundEvents,
            pricingMode: $validated['pricing_mode'] ?? 'fixed_catalog',
        );

        return redirect()
            ->route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $updated->code])
            ->with('success', "Service '{$updated->name}' financial terms updated (Revision {$updated->revision}).");
    }
}
