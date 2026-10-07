<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Karband\CatalogPanel\Contracts\CatalogPanelAuthorizationInterface;
use Karband\CatalogPanel\Contracts\CatalogPanelPortInterface;

final class CatalogLifecycleController extends Controller
{
    public function __construct(
        private readonly CatalogPanelPortInterface $port,
        private readonly CatalogPanelAuthorizationInterface $auth,
    ) {
    }

    public function activate(Request $request, string $code)
    {
        if (!$this->auth->canActivate($code)) {
            abort(403, 'Unauthorized to activate service.');
        }

        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);

        $updated = $this->port->activateService(
            code: $code,
            expectedRevision: (int) $validated['expected_revision'],
        );

        return redirect()
            ->route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $updated->code])
            ->with('success', "Service '{$updated->name}' is now ACTIVE (Revision {$updated->revision}).");
    }

    public function deactivate(Request $request, string $code)
    {
        if (!$this->auth->canDeactivate($code)) {
            abort(403, 'Unauthorized to deactivate service.');
        }

        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);

        $updated = $this->port->deactivateService(
            code: $code,
            expectedRevision: (int) $validated['expected_revision'],
        );

        return redirect()
            ->route(config('catalog-panel.route_name_prefix', 'catalog.') . 'show', ['code' => $updated->code])
            ->with('success', "Service '{$updated->name}' is now INACTIVE (Revision {$updated->revision}).");
    }
}
