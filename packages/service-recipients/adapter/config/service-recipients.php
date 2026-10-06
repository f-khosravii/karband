<?php

declare(strict_types=1);
return [
    'connection' => env('INTL_RECIPIENTS_DB_CONNECTION', env('DB_CONNECTION')),
    // Dedicated, stable, random >=32-byte key. Do not use APP_KEY and do not rotate without migration.
    'identity_hmac_key' => env('INTL_RECIPIENTS_HMAC_KEY'),
];
