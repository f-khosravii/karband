<?php

declare(strict_types=1);

namespace Karband\DocumentsLaravel\Services;

use Illuminate\Database\ConnectionInterface;
use Karband\Documents\Definitions\DocumentDefinition;
use Karband\DocumentsLaravel\Exceptions\DocumentNotFound;

final readonly class DocumentStorage
{
    public function __construct(public ConnectionInterface $connection) {}

    public static function encode(DocumentDefinition $definition): string
    {
        return json_encode($definition->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function decode(string $json): DocumentDefinition
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Corrupt stored document definition.');
        }
        return DocumentDefinition::fromArray($data);
    }

    public function published(string $serviceCode, int $version): DocumentDefinition
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('Document version must be positive.');
        }
        $row = $this->connection->table('document_definitions')->where('service_code', $serviceCode)
            ->where('version', $version)->first();
        if ($row === null) {
            throw new DocumentNotFound('Published document definition not found.');
        }
        $definition = self::decode((string) $row->definition);
        if ($definition->serviceCode !== $serviceCode || $definition->version !== $version) {
            throw new \UnexpectedValueException('Stored document definition identity mismatch.');
        }
        return $definition;
    }

    public function latestVersion(string $serviceCode): int
    {
        return (int) ($this->connection->table('document_definitions')->where('service_code', $serviceCode)->max('version') ?? 0);
    }

    public static function assertResource(string $serviceCode, string $resourceType, string $resourceId): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $serviceCode)) {
            throw new \InvalidArgumentException('Invalid service code.');
        }
        foreach ([$resourceType, $resourceId] as $item) {
            if (trim($item) === '' || strlen($item) > 191) {
                throw new \InvalidArgumentException('Invalid resource identifier.');
            }
        }
        if (strlen($resourceType) > 120) {
            throw new \InvalidArgumentException('Resource type too long.');
        }
    }
}
