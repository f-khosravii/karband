<?php

declare(strict_types=1);

namespace Intl\RequestJourney\ValueObjects;

use Intl\RequestJourney\Definitions\StepState;

final readonly class JourneyProgress
{
    /** @var array<string, StepProgress> */
    public array $stepMap;

    /**
     * @param list<StepProgress> $steps
     */
    public function __construct(
        public string $serviceCode,
        public int $journeyVersion,
        public string $navigationMode,
        public array $steps,
        public ?string $currentStepKey = null,
        public float $percentageComplete = 0.0,
    ) {
        $map = [];
        foreach ($this->steps as $s) {
            $map[$s->key] = $s;
        }
        $this->stepMap = $map;
    }

    public function step(string $key): ?StepProgress
    {
        return $this->stepMap[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'service_code' => $this->serviceCode,
            'journey_version' => $this->journeyVersion,
            'navigation_mode' => $this->navigationMode,
            'current_step_key' => $this->currentStepKey,
            'percentage_complete' => $this->percentageComplete,
            'steps' => array_map(fn (StepProgress $s) => $s->toArray(), $this->steps),
        ];
    }
}
