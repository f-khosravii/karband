<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Karband\CatalogPanel\Contracts\CatalogPanelAuthorizationInterface;
use Karband\CatalogPanel\Contracts\CatalogPanelPortInterface;

final class CatalogCreateController extends Controller
{
    public function __construct(
        private readonly CatalogPanelPortInterface $port,
        private readonly CatalogPanelAuthorizationInterface $auth,
    ) {
    }

    public function create()
    {
        if (!$this->auth->canCreate()) {
            abort(403, 'Unauthorized to create services.');
        }

        return view('catalog-panel::create');
    }

    public function store(Request $request)
    {
        if (!$this->auth->canCreate()) {
            abort(403, 'Unauthorized to create services.');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'name' => ['required', 'string', 'min:2', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $created = $this->port->createService(
            code: $validated['code'],
            name: $validated['name'],
            description: $validated['description'] ?? null,
        );

        return redirect()
            ->route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $created->code])
            ->with('success', "Service '{$created->name}' ({$created->code}) created successfully.");
    }
}
