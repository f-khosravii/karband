<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Http\Controllers;

use Illuminate\Routing\Controller;
use Karband\CatalogPanel\Contracts\CatalogPanelAuthorizationInterface;
use Karband\CatalogPanel\Contracts\CatalogPanelPortInterface;

final class CatalogDetailController extends Controller
{
    public function __construct(
        private readonly CatalogPanelPortInterface $port,
        private readonly CatalogPanelAuthorizationInterface $auth,
    ) {
    }

    public function show(string $code)
    {
        if (!$this->auth->canView($code)) {
            abort(403, 'Unauthorized to view this service.');
        }

        $service = $this->port->getService($code);

        return view('catalog-panel::show', [
            'service' => $service,
            'canUpdate' => $this->auth->canUpdate($code),
            'canPrice' => $this->auth->canPrice($code),
            'canActivate' => $this->auth->canActivate($code),
            'canDeactivate' => $this->auth->canDeactivate($code),
        ]);
    }
}
