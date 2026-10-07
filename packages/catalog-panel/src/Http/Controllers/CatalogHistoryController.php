<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Http\Controllers;

use Illuminate\Routing\Controller;
use Karband\CatalogPanel\Contracts\CatalogPanelAuthorizationInterface;
use Karband\CatalogPanel\Contracts\CatalogPanelPortInterface;

final class CatalogHistoryController extends Controller
{
    public function __construct(
        private readonly CatalogPanelPortInterface $port,
        private readonly CatalogPanelAuthorizationInterface $auth,
    ) {
    }

    public function index(string $code)
    {
        if (!$this->auth->canView($code)) {
            abort(403, 'Unauthorized to view service history.');
        }

        $service = $this->port->getService($code);
        $revisions = $this->port->getHistory($code);

        return view('catalog-panel::history', [
            'service' => $service,
            'revisions' => $revisions,
        ]);
    }

    public function showRevision(string $code, int $revision)
    {
        if (!$this->auth->canView($code)) {
            abort(403, 'Unauthorized to view service history.');
        }

        $historical = $this->port->getRevision($code, $revision);
        $current = $this->port->getService($code);

        return view('catalog-panel::revision-detail', [
            'historical' => $historical,
            'current' => $current,
            'isLatest' => $historical->revision === $current->revision,
        ]);
    }
}
