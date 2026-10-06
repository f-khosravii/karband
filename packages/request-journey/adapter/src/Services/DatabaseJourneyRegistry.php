<?php

declare(strict_types=1);

namespace Karband\RequestJourneyLaravel\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Karband\RequestJourney\Definitions\JourneyDefinition;
use Karband\RequestJourneyLaravel\Contracts\JourneyRegistryInterface;
use Karband\RequestJourneyLaravel\Exceptions\JourneyConflict;

final readonly class DatabaseJourneyRegistry implements JourneyRegistryInterface
{
    public function __construct(private JourneyStorage $storage)
    {
    }

    public function publish(JourneyDefinition $definition, ?string $publishedBy = null): void
    {
        $db = $this->storage->connection;

        $db->transaction(function () use ($db, $definition, $publishedBy): void {
            $latest = $this->latestVersion($definition->serviceCode);
            if ($definition->version <= $latest) {
                throw new JourneyConflict("Journey version {$definition->version} has already been published or is not greater than latest version {$latest}.");
            }

            try {
                $db->table('journey_definitions')->insert([
                    'service_code' => $definition->serviceCode,
                    'version' => $definition->version,
                    'navigation_mode' => $definition->navigationMode,
                    'definition_json' => JourneyStorage::json($definition),
                    'published_by' => $publishedBy,
                    'published_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new JourneyConflict("Journey version {$definition->version} already published for service {$definition->serviceCode}.", previous: $e);
            }
        });
    }

    public function get(string $serviceCode, int $version): ?JourneyDefinition
    {
        if ($version < 1) {
            return null;
        }

        $row = $this->storage->connection->table('journey_definitions')
            ->where('service_code', $serviceCode)
            ->where('version', $version)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->storage->hydrate($row);
    }

    public function latest(string $serviceCode): ?JourneyDefinition
    {
        $version = $this->latestVersion($serviceCode);
        if ($version < 1) {
            return null;
        }

        return $this->get($serviceCode, $version);
    }

    public function latestVersion(string $serviceCode): int
    {
        return (int) ($this->storage->connection->table('journey_definitions')
            ->where('service_code', $serviceCode)
            ->max('version') ?? 0);
    }

    public function has(string $serviceCode, ?int $version = null): bool
    {
        $query = $this->storage->connection->table('journey_definitions')
            ->where('service_code', $serviceCode);

        if ($version !== null) {
            $query->where('version', $version);
        }

        return $query->exists();
    }
}
