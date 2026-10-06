<?php

declare(strict_types=1);

return [
    // Must share Catalog's LOCAL DB connection to safely pin service state.
    'connection' => null,
    // Laravel 13 local disk defaults to storage/app/private. Never use the public disk.
    'private_disk' => env('INTL_DOCUMENT_DISK', 'local'),
    'allowed_mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
    'absolute_max_bytes' => 10_485_760,
    // The host must integrate virus scanning and retention rules before production.
];
