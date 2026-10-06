<?php

declare(strict_types=1);

namespace Karband\ServiceRequests\ValueObjects;

/** One canonical resource type across local Forms, Documents, Workflow and Billing. */
final class RequestResource
{
    public const TYPE = 'service_request';

    private function __construct() {}
}
