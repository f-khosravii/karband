<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Services;

use Illuminate\Database\Connection;

final readonly class RequestStorage
{
    public function __construct(public Connection $connection) {}
}
