<?php

declare(strict_types=1);

namespace Karband\DirectoryPanel\Http\Controllers;

use Illuminate\View\View;
use Karband\DirectoryPanel\Contracts\DirectoryPanelAuthorizationInterface;
use Karband\DirectoryPanel\Contracts\DirectoryPanelPortInterface;

final readonly class UserDetailController
{
    public function __construct(
        private DirectoryPanelPortInterface $port,
        private DirectoryPanelAuthorizationInterface $auth,
    ) {
    }

    public function __invoke(string $id): View
    {
        if (!$this->auth->canViewUser($id)) {
            abort(403, 'Unauthorized to view this user.');
        }

        $user = $this->port->getUser($id);
        $memberships = $this->port->listMembershipsForUser($id);
        $allOrgs = $this->auth->canListOrganizations() ? $this->port->listOrganizations() : [];

        return view('directory-panel::users.show', [
            'user' => $user,
            'memberships' => $memberships,
            'organizations' => $allOrgs,
            'canUpdate' => $this->auth->canUpdateUser($id),
            'canArchive' => $this->auth->canArchiveUser($id),
            'canRestore' => $this->auth->canRestoreUser($id),
            'canCreateMembership' => $this->auth->canCreateMembership(),
            'canEndMembership' => $this->auth->canEndMembership(),
        ]);
    }
}
