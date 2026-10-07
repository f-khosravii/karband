<?php

declare(strict_types=1);

namespace Karband\ServiceCatalogLaravel\Services;

use Illuminate\Database\Connection;
use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceStatus;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;

/** Maps local DB rows into validated, immutable domain values. */
final readonly class CatalogStorage
{
    public function __construct(public Connection $connection)
    {
    }

    public function hydrate(object $row): ServiceDefinition
    {
        $settings = json_decode((string) $row->settings, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) {
            throw new \UnexpectedValueException('Stored service settings are not a JSON object.');
        }
        if (($row->workflow_id === null) !== ($row->workflow_version === null)) {
            throw new \UnexpectedValueException('Stored workflow binding is incomplete.');
        }
        $binding = $row->workflow_id === null ? null :
            new WorkflowBinding((string) $row->workflow_id, (int) $row->workflow_version);

        $code = isset($row->service_code) ? (string) $row->service_code : (string) $row->code;

        return new ServiceDefinition(
            $code, (string) $row->name,
            $row->description === null ? null : (string) $row->description,
            $settings, ServiceStatus::from((string) $row->status), $binding, (int) $row->revision,
        );
    }

    public function hydrateRevision(object $row): ServiceDefinition
    {
        return $this->hydrate($row);
    }

    /** @return array<string, mixed> */
    public static function columns(ServiceDefinition $service): array
    {
        return [
            'name' => $service->name,
            'description' => $service->description,
            'settings' => json_encode($service->settings === [] ? (object) [] : $service->settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => $service->status->value,
            'workflow_id' => $service->workflow?->id,
            'workflow_version' => $service->workflow?->version,
            'revision' => $service->revision,
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    public static function revisionColumns(ServiceDefinition $service): array
    {
        return [
            'service_code' => $service->code,
            'revision' => $service->revision,
            'name' => $service->name,
            'description' => $service->description,
            'settings' => json_encode($service->settings === [] ? (object) [] : $service->settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => $service->status->value,
            'workflow_id' => $service->workflow?->id,
            'workflow_version' => $service->workflow?->version,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
