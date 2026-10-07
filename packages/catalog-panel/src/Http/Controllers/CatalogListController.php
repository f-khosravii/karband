<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Karband\CatalogPanel\Contracts\CatalogPanelAuthorizationInterface;
use Karband\CatalogPanel\Contracts\CatalogPanelPortInterface;

final class CatalogListController extends Controller
{
    public function __construct(
        private readonly CatalogPanelPortInterface $port,
        private readonly CatalogPanelAuthorizationInterface $auth,
    ) {
    }

    public function index(Request $request)
    {
        if (!$this->auth->canList()) {
            abort(403, 'Unauthorized to view service catalog.');
        }

        $search = $request->query('q');
        $status = $request->query('status');

        $services = $this->port->listServices(
            is_string($search) ? trim($search) : null,
            is_string($status) ? trim($status) : null,
        );

        return view('catalog-panel::index', [
            'services' => $services,
            'search' => $search,
            'statusFilter' => $status,
            'canCreate' => $this->auth->canCreate(),
        ]);
    }
}
