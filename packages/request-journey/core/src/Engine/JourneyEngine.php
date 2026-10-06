<?php

declare(strict_types=1);

namespace Karband\RequestJourney\Engine;

use Karband\RequestJourney\Definitions\JourneyDefinition;
use Karband\RequestJourney\Definitions\NavigationMode;
use Karband\RequestJourney\Definitions\StepDefinition;
use Karband\RequestJourney\Definitions\StepState;
use Karband\RequestJourney\Definitions\StepType;
use Karband\RequestJourney\ValueObjects\JourneyFacts;
use Karband\RequestJourney\ValueObjects\JourneyProgress;
use Karband\RequestJourney\ValueObjects\StepProgress;

final class JourneyEngine
{
    public function evaluate(
        JourneyDefinition $definition,
        JourneyFacts $facts,
        ?string $lastVisitedStepKey = null
    ): JourneyProgress {
        $stepStates = [];
        $isCompletedMap = [];
        $isSkippedMap = [];

        // 1. First pass: Determine completed and skipped status for each step
        foreach ($definition->steps as $step) {
            $isSkipped = $this->isStepSkipped($step, $facts);
            $isSkippedMap[$step->key] = $isSkipped;

            if ($isSkipped) {
                $isCompletedMap[$step->key] = false;
                $stepStates[$step->key] = StepState::SKIPPED;
            } else {
                $isCompleted = $this->isStepCompleted($step, $facts, $isCompletedMap, $isSkippedMap);
                $isCompletedMap[$step->key] = $isCompleted;
                if ($isCompleted) {
                    $stepStates[$step->key] = StepState::COMPLETED;
                }
            }
        }

        // 2. Second pass: Determine accessibility/locked state for incomplete, non-skipped steps
        foreach ($definition->steps as $index => $step) {
            if (isset($stepStates[$step->key])) {
                continue; // already COMPLETED or SKIPPED
            }

            // Check workflow state constraints
            if ($step->availableWhenWorkflowStates !== null && $step->availableWhenWorkflowStates !== []) {
                if ($facts->workflowState === null || !in_array($facts->workflowState, $step->availableWhenWorkflowStates, true)) {
                    $stepStates[$step->key] = StepState::LOCKED;
                    continue;
                }
            }

            // Check explicit prerequisites
            $prereqsMet = true;
            foreach ($step->prerequisites as $prereqKey) {
                $prereqCompleted = $isCompletedMap[$prereqKey] ?? false;
                $prereqSkipped = $isSkippedMap[$prereqKey] ?? false;
                if (!$prereqCompleted && !$prereqSkipped) {
                    $prereqsMet = false;
                    break;
                }
            }

            if (!$prereqsMet) {
                $stepStates[$step->key] = StepState::LOCKED;
                continue;
            }

            // Check linear mode progression
            if ($definition->navigationMode === NavigationMode::LINEAR) {
                $priorStepsMet = true;
                for ($i = 0; $i < $index; $i++) {
                    $priorKey = $definition->steps[$i]->key;
                    $priorCompleted = $isCompletedMap[$priorKey] ?? false;
                    $priorSkipped = $isSkippedMap[$priorKey] ?? false;
                    if (!$priorCompleted && !$priorSkipped) {
                        $priorStepsMet = false;
                        break;
                    }
                }
                if (!$priorStepsMet) {
                    $stepStates[$step->key] = StepState::LOCKED;
                    continue;
                }
            }

            $stepStates[$step->key] = StepState::AVAILABLE;
        }

        // 3. Derive current step key
        $currentStepKey = null;

        // A. If last visited step is valid and accessible (not locked and not skipped)
        if ($lastVisitedStepKey !== null && isset($definition->stepMap[$lastVisitedStepKey])) {
            $lastState = $stepStates[$lastVisitedStepKey] ?? StepState::LOCKED;
            if ($lastState !== StepState::LOCKED && $lastState !== StepState::SKIPPED) {
                $currentStepKey = $lastVisitedStepKey;
            }
        }

        // B. Otherwise, first available (incomplete) step
        if ($currentStepKey === null) {
            foreach ($definition->steps as $step) {
                if (($stepStates[$step->key] ?? null) === StepState::AVAILABLE) {
                    $currentStepKey = $step->key;
                    break;
                }
            }
        }

        // C. Otherwise, if all completed/skipped, last non-skipped step or first step
        if ($currentStepKey === null) {
            for ($i = count($definition->steps) - 1; $i >= 0; $i--) {
                $sKey = $definition->steps[$i]->key;
                if (($stepStates[$sKey] ?? null) !== StepState::SKIPPED) {
                    $currentStepKey = $sKey;
                    break;
                }
            }
            if ($currentStepKey === null && isset($definition->steps[0])) {
                $currentStepKey = $definition->steps[0]->key;
            }
        }

        // 4. Build StepProgress list
        $stepProgressList = [];
        $totalNonSkipped = 0;
        $totalCompleted = 0;

        foreach ($definition->steps as $step) {
            $state = $stepStates[$step->key];
            if ($step->key === $currentStepKey && $state === StepState::AVAILABLE) {
                $state = StepState::CURRENT;
            }

            if ($state !== StepState::SKIPPED) {
                $totalNonSkipped++;
                if ($isCompletedMap[$step->key]) {
                    $totalCompleted++;
                }
            }

            $stepProgressList[] = new StepProgress(
                $step->key,
                $step->type,
                $step->label,
                $state,
                $step->reference,
            );
        }

        $percentage = $totalNonSkipped > 0
            ? round(($totalCompleted / $totalNonSkipped) * 100, 2)
            : 100.0;

        return new JourneyProgress(
            $definition->serviceCode,
            $definition->version,
            $definition->navigationMode,
            $stepProgressList,
            $currentStepKey,
            $percentage,
        );
    }

    private function isStepSkipped(StepDefinition $step, JourneyFacts $facts): bool
    {
        if ($step->skipWhenPricingModes !== null && $facts->pricingMode !== null) {
            if (in_array($facts->pricingMode, $step->skipWhenPricingModes, true)) {
                return true;
            }
        }

        if ($step->type === StepType::PAYMENT && $facts->pricingMode === 'no_charge') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, bool> $isCompletedMap
     * @param array<string, bool> $isSkippedMap
     */
    private function isStepCompleted(
        StepDefinition $step,
        JourneyFacts $facts,
        array $isCompletedMap,
        array $isSkippedMap
    ): bool {
        return match ($step->type) {
            StepType::FORM => $step->reference !== null
                ? $facts->isFormSectionCompleted($step->reference)
                : $facts->formCompleted,
            StepType::DOCUMENTS => $facts->documentsCompleted,
            StepType::QUOTE => $facts->quoteAccepted,
            StepType::SIGNATURE => $facts->signatureCompleted || $facts->hasCustomFact($step->customFactKey ?? 'signed_document_present'),
            StepType::PAYMENT => $facts->paymentConfirmed,
            StepType::REVIEW => $facts->reviewConfirmed || $this->arePrerequisitesSatisfied($step, $isCompletedMap, $isSkippedMap),
            StepType::CUSTOM => $facts->hasCustomFact($step->customFactKey ?? $step->reference ?? $step->key),
            default => false,
        };
    }

    /**
     * @param array<string, bool> $isCompletedMap
     * @param array<string, bool> $isSkippedMap
     */
    private function arePrerequisitesSatisfied(StepDefinition $step, array $isCompletedMap, array $isSkippedMap): bool
    {
        if ($step->prerequisites === []) {
            return false;
        }
        foreach ($step->prerequisites as $prereq) {
            $done = $isCompletedMap[$prereq] ?? false;
            $skipped = $isSkippedMap[$prereq] ?? false;
            if (!$done && !$skipped) {
                return false;
            }
        }
        return true;
    }
}
