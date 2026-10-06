<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Contracts;

/** Host enforces company representative/document authority and any other service-specific prerequisites. */
interface RequestSubmissionVerifier
{
    /** Throw on missing/stale/invalid facts. Must not write or call external systems inside request transaction. */
    public function assertReady(string $serviceCode, string $requestId): void;
}
