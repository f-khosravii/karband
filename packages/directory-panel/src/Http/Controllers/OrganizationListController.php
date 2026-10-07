<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;

final readonly class OrganizationListController
{
    public function __construct(
        private DirectoryPanelPortInterface $port,
        private DirectoryPanelAuthorizationInterface $auth,
    ) {
    }

    public function __invoke(Request $request): View
    {
        if (!$this->auth->canListOrganizations()) {
            abort(403, 'Unauthorized to list organizations.');
        }

        $search = $request->query('search');
        $status = $request->query('status');

        $searchStr = is_string($search) && trim($search) !== '' ? trim($search) : null;
        $statusStr = is_string($status) && trim($status) !== '' ? trim($status) : null;

        $organizations = $this->port->listOrganizations($searchStr, $statusStr);

        return view('directory-panel::organizations.index', [
            'organizations' => $organizations,
            'search' => $searchStr,
            'status' => $statusStr,
            'canCreate' => $this->auth->canCreateOrganization(),
        ]);
    }
}
