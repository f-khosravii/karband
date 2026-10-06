<?php

declare(strict_types=1);

namespace Karband\WorkflowLaravel\ValueObjects;

use Karband\Workflow\ValueObjects\TransitionResult;

/** Denial leaves the database untouched; replay refers to a prior successful action. */
final readonly class ActionOutcome
{
    private function __construct(
        public bool $applied,
        public bool $replayed,
        public WorkflowInstance $instance,
        public ?int $historyId,
        public ?string $previousState,
        public ?TransitionResult $denial,
    ) {
    }

    public static function denied(WorkflowInstance $instance, TransitionResult $result): self
    {
        return new self(false, false, $instance, null, null, $result);
    }

    public static function success(
        WorkflowInstance $instance,
        int $historyId,
        string $previousState,
        bool $replayed = false,
    ): self {
        return new self(true, $replayed, $instance, $historyId, $previousState, null);
    }
}
