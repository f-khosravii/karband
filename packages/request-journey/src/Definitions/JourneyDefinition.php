<?php

declare(strict_types=1);

namespace Intl\RequestJourney\Definitions;

use Intl\RequestJourney\Exceptions\InvalidJourneyDefinition;

final readonly class JourneyDefinition
{
    /** @var array<string, StepDefinition> */
    public array $stepMap;

    /**
     * @param list<StepDefinition> $steps
     */
    public function __construct(
        public string $serviceCode,
        public int $version,
        public string $navigationMode,
        public array $steps,
    ) {
        if (!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $this->serviceCode)) {
            throw new InvalidJourneyDefinition("Invalid service code: '{$this->serviceCode}'.");
        }
        if ($this->version < 1) {
            throw new InvalidJourneyDefinition('Journey version must be >= 1.');
        }
        if (!NavigationMode::isValid($this->navigationMode)) {
            throw new InvalidJourneyDefinition("Invalid navigation mode: '{$this->navigationMode}'.");
        }
        if ($this->steps === []) {
            throw new InvalidJourneyDefinition('Journey definition must have at least one step.');
        }

        $map = [];
        foreach ($this->steps as $step) {
            if (!$step instanceof StepDefinition) {
                throw new InvalidJourneyDefinition('All steps must be StepDefinition instances.');
            }
            if (isset($map[$step->key])) {
                throw new InvalidJourneyDefinition("Duplicate step key: '{$step->key}'.");
            }
            $map[$step->key] = $step;
        }
        $this->stepMap = $map;

        $this->validatePrerequisites();
    }

    private function validatePrerequisites(): void
    {
        foreach ($this->steps as $step) {
            foreach ($step->prerequisites as $prereqKey) {
                if (!isset($this->stepMap[$prereqKey])) {
                    throw new InvalidJourneyDefinition("Step '{$step->key}' references unknown prerequisite '{$prereqKey}'.");
                }
                if ($prereqKey === $step->key) {
                    throw new InvalidJourneyDefinition("Step '{$step->key}' cannot depend on itself.");
                }
            }
        }

        // Cycle detection via DFS
        $visited = [];
        $recursionStack = [];

        $hasCycle = function (string $key) use (&$hasCycle, &$visited, &$recursionStack): bool {
            $visited[$key] = true;
            $recursionStack[$key] = true;

            $step = $this->stepMap[$key];
            foreach ($step->prerequisites as $prereq) {
                if (!isset($visited[$prereq])) {
                    if ($hasCycle($prereq)) {
                        return true;
                    }
                } elseif (isset($recursionStack[$prereq]) && $recursionStack[$prereq]) {
                    return true;
                }
            }

            $recursionStack[$key] = false;
            return false;
        };

        foreach (array_keys($this->stepMap) as $key) {
            if (!isset($visited[$key])) {
                if ($hasCycle($key)) {
                    throw new InvalidJourneyDefinition('Cyclic prerequisite dependency detected in journey steps.');
                }
            }
        }
    }

    public function step(string $key): ?StepDefinition
    {
        return $this->stepMap[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'service_code' => $this->serviceCode,
            'version' => $this->version,
            'navigation_mode' => $this->navigationMode,
            'steps' => array_map(fn (StepDefinition $s) => $s->toArray(), $this->steps),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['service_code'], $data['version'], $data['navigation_mode'], $data['steps']) || !is_array($data['steps'])) {
            throw new InvalidJourneyDefinition('Missing mandatory journey definition fields.');
        }

        $steps = [];
        foreach ($data['steps'] as $s) {
            if (!is_array($s)) {
                throw new InvalidJourneyDefinition('Malformed step data.');
            }
            $steps[] = StepDefinition::fromArray($s);
        }

        return new self(
            (string) $data['service_code'],
            (int) $data['version'],
            (string) $data['navigation_mode'],
            $steps,
        );
    }
}
