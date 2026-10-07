<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Http\Controllers;

use Illuminate\View\View;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;

final readonly class OrganizationDetailController
{
    public function __construct(
        private DirectoryPanelPortInterface $port,
        private DirectoryPanelAuthorizationInterface $auth,
    ) {
    }

    public function __invoke(string $id): View
    {
        if (!$this->auth->canViewOrganization($id)) {
            abort(403, 'Unauthorized to view this organization.');
        }

        $organization = $this->port->getOrganization($id);
        $memberships = $this->port->listMembershipsForOrganization($id);

        return view('directory-panel::organizations.show', [
            'org' => $organization,
            'memberships' => $memberships,
            'canUpdate' => $this->auth->canUpdateOrganization($id),
            'canArchive' => $this->auth->canArchiveOrganization($id),
            'canRestore' => $this->auth->canRestoreOrganization($id),
            'canCreateMembership' => $this->auth->canCreateMembership(),
            'canEndMembership' => $this->auth->canEndMembership(),
        ]);
    }
}
