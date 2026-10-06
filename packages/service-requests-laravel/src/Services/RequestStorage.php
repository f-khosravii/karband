<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Services;

use Illuminate\Database\Connection;

final readonly class RequestStorage
{
    public function __construct(public Connection $connection) {}
}
