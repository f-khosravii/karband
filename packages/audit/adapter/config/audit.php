<?php

declare(strict_types=1);

return [
    // Use the local microservice's default database. Never configure a central INTL database here.
    'log_name' => 'audit',

    // Explicit event codes only. Extend this list in each host as its modules are installed.
    'allowed_events' => [
        'authorization.role.assigned',
        'authorization.permission.changed',
        'authorization.access.denied',
        'workflow.definition.published',
        'workflow.transition.completed',
        'workflow.transition.denied',
        'catalog.service.created',
        'catalog.service.activated',
        'catalog.service.deactivated',
        'forms.definition.published',
        'documents.file.replaced',
        'billing.payment.initiated',
        'billing.payment.confirmed',
        'billing.payment.rejected',
        'billing.payment.unknown',
        'requests.request.created',
        'requests.draft.cancelled',
        'requests.cancellation.requested',
        'requests.cancellation.decided',
        'requests.cancellation.withdrawn',
        'requests.correction.required',
        'requests.resubmitted',
        'requests.draft.deleted',
        'requests.draft.restored',
        'requests.representative.selected',
        'request.quote.proposed',
        'request.quote.accepted',
        'request.quote.rejected',
        'external_auth.ticket_exchanged',
        'external_auth.session_revoked',
    ],
];
