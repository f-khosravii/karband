<?php

declare(strict_types=1);

namespace Karband\RequestJourneyLaravel\Services;

use Illuminate\Database\Connection;
use Karband\RequestJourney\Definitions\JourneyDefinition;

final readonly class JourneyStorage
{
    public function __construct(public Connection $connection)
    {
    }

    public function hydrate(object $row): JourneyDefinition
    {
        $data = json_decode((string) $row->definition_json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Stored journey definition must be a JSON object.');
        }

        $journey = JourneyDefinition::fromArray($data);
        if ($journey->serviceCode !== (string) $row->service_code || $journey->version !== (int) $row->version) {
            throw new \UnexpectedValueException('Stored journey definition metadata does not match database record.');
        }

        return $journey;
    }

    public static function json(JourneyDefinition $definition): string
    {
        return json_encode($definition->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
