<?php

declare(strict_types=1);

namespace Karband\RequestJourney\ValueObjects;

use Karband\RequestJourney\Definitions\StepState;

final readonly class StepProgress
{
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public string $state,
        public ?string $reference = null,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->state === StepState::COMPLETED;
    }

    public function isAvailable(): bool
    {
        return $this->state === StepState::AVAILABLE || $this->state === StepState::CURRENT;
    }

    public function isLocked(): bool
    {
        return $this->state === StepState::LOCKED;
    }

    public function isSkipped(): bool
    {
        return $this->state === StepState::SKIPPED;
    }

    public function isCurrent(): bool
    {
        return $this->state === StepState::CURRENT;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'state' => $this->state,
        ];
        if ($this->reference !== null) {
            $data['reference'] = $this->reference;
        }
        return $data;
    }
}
