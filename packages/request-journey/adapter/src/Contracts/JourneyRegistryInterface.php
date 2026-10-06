<?php

declare(strict_types=1);

namespace Karband\RequestJourneyLaravel\Contracts;

use Karband\RequestJourney\Definitions\JourneyDefinition;

interface JourneyRegistryInterface
{
    /**
     * Publish an immutable journey definition for a service.
     */
    public function publish(JourneyDefinition $definition, ?string $publishedBy = null): void;

    /**
     * Retrieve a specific published journey definition by service code and version.
     */
    public function get(string $serviceCode, int $version): ?JourneyDefinition;

    /**
     * Retrieve the latest published journey definition for a service code.
     */
    public function latest(string $serviceCode): ?JourneyDefinition;

    /**
     * Get the latest published version number for a service code (or 0 if none).
     */
    public function latestVersion(string $serviceCode): int;

    /**
     * Check if a published definition exists for a service code (and optional version).
     */
    public function has(string $serviceCode, ?int $version = null): bool;
}
