<?php

declare(strict_types=1);

return [
    // Must use the same local database as service-catalog for atomic checks.
    'connection' => null,
    'deadlock_attempts' => 3,
];
