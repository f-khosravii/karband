<?php

declare(strict_types=1);

namespace Karband\RequestJourney\Definitions;

use Karband\RequestJourney\Exceptions\InvalidJourneyDefinition;

final readonly class StepDefinition
{
    /**
     * @param list<string> $prerequisites
     * @param ?list<string> $availableWhenWorkflowStates
     * @param ?list<string> $skipWhenPricingModes
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public ?string $reference = null,
        public array $prerequisites = [],
        public ?array $availableWhenWorkflowStates = null,
        public ?array $skipWhenPricingModes = null,
        public ?string $customFactKey = null,
    ) {
        if (!preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $this->key)) {
            throw new InvalidJourneyDefinition("Invalid step key: '{$this->key}'.");
        }
        if (!StepType::isValid($this->type)) {
            throw new InvalidJourneyDefinition("Unknown step type: '{$this->type}'.");
        }
        if (trim($this->label) === '') {
            throw new InvalidJourneyDefinition("Step label cannot be empty.");
        }
        if (!array_is_list($this->prerequisites) || count($this->prerequisites) !== count(array_unique($this->prerequisites))) {
            throw new InvalidJourneyDefinition("Step prerequisites must be a list of unique step keys.");
        }
        foreach ($this->prerequisites as $prereq) {
            if (!is_string($prereq) || !preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $prereq)) {
                throw new InvalidJourneyDefinition("Invalid prerequisite step key: '{$prereq}'.");
            }
        }
        if ($this->availableWhenWorkflowStates !== null) {
            if (!array_is_list($this->availableWhenWorkflowStates) || count($this->availableWhenWorkflowStates) !== count(array_unique($this->availableWhenWorkflowStates))) {
                throw new InvalidJourneyDefinition("availableWhenWorkflowStates must be a list of unique strings.");
            }
        }
        if ($this->skipWhenPricingModes !== null) {
            if (!array_is_list($this->skipWhenPricingModes) || count($this->skipWhenPricingModes) !== count(array_unique($this->skipWhenPricingModes))) {
                throw new InvalidJourneyDefinition("skipWhenPricingModes must be a list of unique strings.");
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
        ];
        if ($this->reference !== null) {
            $data['reference'] = $this->reference;
        }
        if ($this->prerequisites !== []) {
            $data['prerequisites'] = $this->prerequisites;
        }
        if ($this->availableWhenWorkflowStates !== null) {
            $data['available_when_workflow_states'] = $this->availableWhenWorkflowStates;
        }
        if ($this->skipWhenPricingModes !== null) {
            $data['skip_when_pricing_modes'] = $this->skipWhenPricingModes;
        }
        if ($this->customFactKey !== null) {
            $data['custom_fact_key'] = $this->customFactKey;
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowedKeys = [
            'key', 'type', 'label', 'reference', 'prerequisites',
            'available_when_workflow_states', 'skip_when_pricing_modes', 'custom_fact_key',
        ];
        if (array_diff(array_keys($data), $allowedKeys) !== []) {
            throw new InvalidJourneyDefinition('Unexpected fields in step definition.');
        }
        if (!isset($data['key'], $data['type'], $data['label'])) {
            throw new InvalidJourneyDefinition('Missing mandatory step definition fields (key, type, label).');
        }

        return new self(
            (string) $data['key'],
            (string) $data['type'],
            (string) $data['label'],
            isset($data['reference']) ? (string) $data['reference'] : null,
            isset($data['prerequisites']) && is_array($data['prerequisites']) ? array_values($data['prerequisites']) : [],
            isset($data['available_when_workflow_states']) && is_array($data['available_when_workflow_states']) ? array_values($data['available_when_workflow_states']) : null,
            isset($data['skip_when_pricing_modes']) && is_array($data['skip_when_pricing_modes']) ? array_values($data['skip_when_pricing_modes']) : null,
            isset($data['custom_fact_key']) ? (string) $data['custom_fact_key'] : null,
        );
    }
}
