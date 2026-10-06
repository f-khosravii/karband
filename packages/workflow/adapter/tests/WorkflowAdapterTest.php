<?php

declare(strict_types=1);

namespace Karband\WorkflowLaravel\Tests;

use PHPUnit\Framework\TestCase;
use Karband\Workflow\ValueObjects\TransitionResult;
use Karband\WorkflowLaravel\Support\Input;
use Karband\WorkflowLaravel\ValueObjects\ActionOutcome;
use Karband\WorkflowLaravel\ValueObjects\WorkflowInstance;

final class WorkflowAdapterTest extends TestCase
{
    public function test_input_validation(): void
    {
        Input::resource('ncr.request', '123');
        Input::idempotencyKey('safe:key-001');

        $this->expectException(\InvalidArgumentException::class);
        Input::idempotencyKey('not valid!');
    }

    public function test_action_outcome_denial_and_success(): void
    {
        $snapshot = new WorkflowInstance('01TEST', 'ncr.request', '123', 'ncr.sample', 3, 'draft', 0);
        $denial = ActionOutcome::denied($snapshot, TransitionResult::reject('actor_not_allowed'));
        self::assertFalse($denial->applied);
        self::assertSame('actor_not_allowed', $denial->denial?->reasonCode);

        $success = ActionOutcome::success($snapshot, 42, 'submitted', true);
        self::assertTrue($success->applied);
        self::assertTrue($success->replayed);
        self::assertSame(42, $success->historyId);
    }
}
