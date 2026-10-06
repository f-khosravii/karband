<?php

declare(strict_types=1);

namespace Intl\RequestJourneyLaravel\Contracts;

use Intl\RequestJourney\ValueObjects\JourneyProgress;

interface JourneyServiceInterface
{
    /**
     * Pin a request to a journey definition version (defaults to latest published version).
     * If no journey definition is registered for the service, no journey is pinned (safe no-op).
     */
    public function pinForRequest(string $requestId, string $serviceCode, ?int $version = null): void;

    /**
     * Evaluate and return the current journey progress for the request.
     * Returns null if no journey is configured/pinned for the request.
     */
    public function progress(string $requestId): ?JourneyProgress;

    /**
     * Record visiting a step.
     * Rejects locked or invalid steps without mutating any underlying business facts.
     */
    public function visit(string $requestId, string $stepKey): void;
}
