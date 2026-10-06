<?php

declare(strict_types=1);

namespace Intl\RequestJourneyLaravel\Services;

use Illuminate\Support\Str;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\RequestJourney\Definitions\StepState;
use Intl\RequestJourney\Engine\JourneyEngine;
use Intl\RequestJourney\ValueObjects\JourneyProgress;
use Intl\RequestJourneyLaravel\Contracts\JourneyFactProviderInterface;
use Intl\RequestJourneyLaravel\Contracts\JourneyRegistryInterface;
use Intl\RequestJourneyLaravel\Contracts\JourneyServiceInterface;
use Intl\RequestJourneyLaravel\Exceptions\JourneyConflict;
use Intl\RequestJourneyLaravel\Exceptions\JourneyLockedStep;
use Intl\RequestJourneyLaravel\Exceptions\JourneyNotFound;
use Intl\ServiceRequests\ValueObjects\RequestResource;

final readonly class RequestJourneyManager implements JourneyServiceInterface
{
    public function __construct(
        private JourneyStorage $storage,
        private JourneyRegistryInterface $registry,
        private JourneyFactProviderInterface $facts,
        private PrincipalProvider $principals,
        private AuthorizerInterface $authorization,
        private JourneyEngine $engine = new JourneyEngine(),
    ) {
    }

    public function pinForRequest(string $requestId, string $serviceCode, ?int $version = null): void
    {
        $resolvedVersion = $version ?? $this->registry->latestVersion($serviceCode);
        if ($resolvedVersion < 1) {
            // No journey registered for this service code; safe no-op for backward compatibility.
            return;
        }

        $db = $this->storage->connection;
        $existing = $db->table('intl_request_journeys')->where('request_id', $requestId)->first();
        if ($existing !== null) {
            // Already pinned; do not silently overwrite pinned version.
            return;
        }

        $db->table('intl_request_journeys')->insert([
            'id' => (string) Str::ulid(),
            'request_id' => $requestId,
            'service_code' => $serviceCode,
            'journey_version' => $resolvedVersion,
            'last_visited_step_key' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function progress(string $requestId): ?JourneyProgress
    {
        $row = $this->storage->connection->table('intl_request_journeys')
            ->where('request_id', $requestId)
            ->first();

        if ($row === null) {
            return null;
        }

        $this->authorizeRequestAccess($requestId);

        $definition = $this->registry->get((string) $row->service_code, (int) $row->journey_version);
        if ($definition === null) {
            throw new JourneyNotFound("Pinned journey definition not found for service '{$row->service_code}' version {$row->journey_version}.");
        }

        $facts = $this->facts->factsFor($requestId, (string) $row->service_code);

        return $this->engine->evaluate($definition, $facts, $row->last_visited_step_key !== null ? (string) $row->last_visited_step_key : null);
    }

    public function visit(string $requestId, string $stepKey): void
    {
        $row = $this->storage->connection->table('intl_request_journeys')
            ->where('request_id', $requestId)
            ->first();

        if ($row === null) {
            throw new JourneyNotFound("No journey pinned for request '{$requestId}'.");
        }

        $this->authorizeRequestAccess($requestId);

        $definition = $this->registry->get((string) $row->service_code, (int) $row->journey_version);
        if ($definition === null) {
            throw new JourneyNotFound("Pinned journey definition not found for service '{$row->service_code}' version {$row->journey_version}.");
        }

        if (!isset($definition->stepMap[$stepKey])) {
            throw new JourneyNotFound("Step '{$stepKey}' not found in journey definition.");
        }

        $facts = $this->facts->factsFor($requestId, (string) $row->service_code);
        $currentProgress = $this->engine->evaluate($definition, $facts, $row->last_visited_step_key !== null ? (string) $row->last_visited_step_key : null);

        $targetStep = $currentProgress->step($stepKey);
        if ($targetStep === null || $targetStep->state === StepState::LOCKED) {
            throw new JourneyLockedStep("Cannot visit locked step '{$stepKey}'.");
        }

        if ($targetStep->state === StepState::SKIPPED) {
            throw new JourneyLockedStep("Cannot visit skipped step '{$stepKey}'.");
        }

        // Update ONLY last_visited_step_key - NO business state side effects
        $this->storage->connection->table('intl_request_journeys')
            ->where('request_id', $requestId)
            ->update([
                'last_visited_step_key' => $stepKey,
                'updated_at' => now(),
            ]);
    }

    private function authorizeRequestAccess(string $requestId): void
    {
        $actor = $this->principals->current()->actor;
        $this->authorization->authorize($actor, new AccessRequest('requests.view', RequestResource::TYPE, $requestId));

        $req = $this->storage->connection->table('intl_service_requests')->where('id', $requestId)->first();
        if ($req !== null && $actor->role === 'agency' && $actor->organizationId !== (string) $req->agency_id) {
            throw new AuthorizationDenied('foreign_agency');
        }
    }
}
