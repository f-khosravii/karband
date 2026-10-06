<?php

declare(strict_types=1);

return [
    // Use the host application's default connection unless explicitly configured.
    // NCR's host status and adapter rows must be committed on the SAME connection.
    'connection' => env('INTL_WORKFLOW_DB_CONNECTION'),

    // Trusted, host-registered guards: 'guard_key' => GuardImplementation::class.
    // Never accept this registry or its facts from an HTTP request.
    'guards' => [],

    // Laravel transaction retries only for recognized deadlocks/serialization conflicts.
    'deadlock_attempts' => 3,
];
