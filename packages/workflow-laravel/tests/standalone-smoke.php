<?php

declare(strict_types=1);

/** No Laravel vendor required: smoke-check the adapter's pure DTO/input layer. */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Intl\\WorkflowLaravel\\' => __DIR__ . '/../src/',
        'Intl\\Workflow\\' => __DIR__ . '/../../workflow/src/',
    ];
    foreach ($prefixes as $prefix => $path) {
        if (str_starts_with($class, $prefix)) {
            $file = $path . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

use Intl\Workflow\ValueObjects\TransitionResult;
use Intl\WorkflowLaravel\Support\Input;
use Intl\WorkflowLaravel\ValueObjects\ActionOutcome;
use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;

$checks = 0;
function verify(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) {
        throw new LogicException('FAILED: ' . $message);
    }
}

Input::resource('ncr.request', '123');
Input::idempotencyKey('safe:key-001');
verify(true, 'Good identifiers accepted');
foreach ([['', 'x'], ['ncr.request', ''], ['ncr.request', str_repeat('x', 192)]] as [$type, $id]) {
    try {
        Input::resource($type, $id);
        throw new LogicException('Invalid resource was accepted.');
    } catch (InvalidArgumentException|Intl\Workflow\Exceptions\InvalidWorkflowDefinition $e) {
        verify(true, 'Bad resource rejected');
    }
}
try {
    Input::idempotencyKey('not valid!');
    throw new LogicException('Invalid idempotency key was accepted.');
} catch (InvalidArgumentException $e) {
    verify(true, 'Bad idempotency key rejected');
}
$snapshot = new WorkflowInstance('01TEST', 'ncr.request', '123', 'ncr.sample', 3, 'draft', 0);
$denial = ActionOutcome::denied($snapshot, TransitionResult::reject('actor_not_allowed'));
verify(!$denial->applied && $denial->denial?->reasonCode === 'actor_not_allowed', 'Denial DTO');
$success = ActionOutcome::success($snapshot, 42, 'submitted', true);
verify($success->applied && $success->replayed && $success->historyId === 42, 'Replay DTO');
verify($snapshot->workflowVersion === 3 && $snapshot->revision === 0, 'Pinned snapshot');
echo "PASS: $checks standalone adapter smoke checks\n";
