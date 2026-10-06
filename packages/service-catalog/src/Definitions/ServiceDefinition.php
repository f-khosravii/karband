<?php

declare(strict_types=1);

namespace Intl\ServiceCatalog\Definitions;

use Intl\ServiceCatalog\Exceptions\InvalidService;

/**
 * Immutable definition. Revision is for optimistic concurrency; it is NOT
 * immutable service-version history. Old Workflow instances pin independently.
 */
final readonly class ServiceDefinition
{
    /** @param array<string, mixed> $settings Only ordinary JSON, never executable logic/secrets */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public array $settings,
        public ServiceStatus $status,
        public ?WorkflowBinding $workflow,
        public int $revision,
    ) {
        self::assertCode($code, 'Service code');
        if (trim($name) === '' || self::characterCount($name) > 191) {
            throw new InvalidService('Service name must be 1-191 characters.');
        }
        if ($description !== null && self::characterCount($description) > 2000) {
            throw new InvalidService('Service description exceeds 2000 characters.');
        }
        if ($revision < 0) {
            throw new InvalidService('Service revision cannot be negative.');
        }
        if ($settings !== [] && array_is_list($settings)) {
            throw new InvalidService('Settings must be a JSON object, not a list.');
        }
        self::assertJsonSafe($settings);
        try {
            $json = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $error) {
            throw new InvalidService('Settings must contain valid JSON data.', previous: $error);
        }
        if (strlen($json) > 16384) {
            throw new InvalidService('Settings exceed 16 KiB.');
        }
        if ($status === ServiceStatus::Active && $workflow === null) {
            throw new InvalidService('Active services require a published workflow binding.');
        }
    }

    public static function fromDraft(ServiceDraft $draft): self
    {
        return new self($draft->code, $draft->name, $draft->description, $draft->settings,
            ServiceStatus::Inactive, $draft->workflow, 0);
    }

    public function replace(ServiceDraft $draft): self
    {
        if ($this->status === ServiceStatus::Archived) {
            throw new InvalidService('Archived services are immutable.');
        }
        if ($this->code !== $draft->code) {
            throw new InvalidService('Service code is immutable.');
        }
        return new self($this->code, $draft->name, $draft->description, $draft->settings,
            $this->status, $draft->workflow, $this->revision + 1);
    }

    public function transition(ServiceStatus $target): self
    {
        $allowed = match ($this->status) {
            ServiceStatus::Inactive => [ServiceStatus::Active, ServiceStatus::Archived],
            ServiceStatus::Active => [ServiceStatus::Inactive],
            ServiceStatus::Archived => [],
        };
        if (!in_array($target, $allowed, true)) {
            throw new InvalidService('Service lifecycle transition is not allowed.');
        }
        return new self($this->code, $this->name, $this->description, $this->settings,
            $target, $this->workflow, $this->revision + 1);
    }

    public static function assertCode(string $value, string $label): void
    {
        if (strlen($value) > 120 || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $value) !== 1) {
            throw new InvalidService("{$label} must be a lowercase identifier with at most 120 characters.");
        }
    }

    private static function characterCount(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        if ($count === false) {
            throw new InvalidService('Service text must be valid UTF-8.');
        }
        return $count;
    }

    /** @param array<mixed> $values */
    private static function assertJsonSafe(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                self::assertJsonSafe($value);
            } elseif ($value !== null && !is_bool($value) && !is_int($value) &&
                !is_float($value) && !is_string($value)) {
                throw new InvalidService('Settings may only contain JSON scalar values, arrays and objects.');
            }
        }
    }
}
