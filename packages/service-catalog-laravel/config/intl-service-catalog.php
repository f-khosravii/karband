<?php

declare(strict_types=1);

return [
    // Defaults to THIS microservice's local Laravel database connection.
    // For atomic catalog/workflow starts, configure the SAME connection name
    // in intl-workflow.connection (default null for both).
    'connection' => null,
    'deadlock_attempts' => 3,
];
