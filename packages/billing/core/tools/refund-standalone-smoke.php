<?php

declare(strict_types=1);

spl_autoload_register(static function (string $name): void {
    if (!str_starts_with($name, 'Karband\\Billing\\')) { return; }
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($name, strlen('Karband\\Billing\\'))) . '.php';
    if (is_file($path)) { require $path; }
});

use Karband\Billing\Refund\RefundInitiation;
use Karband\Billing\Refund\RefundOutcome;
use Karband\Billing\Refund\RefundPolicy;
use Karband\Billing\Refund\RefundStateMachine;
use Karband\Billing\Refund\RefundStatus;

$policy = RefundPolicy::fromArray((new RefundPolicy([
    'service.rejected' => RefundInitiation::Automatic,
    'request.cancelled' => RefundInitiation::AdminApproval,
]))->toArray());
assert($policy->modeFor('service.rejected') === RefundInitiation::Automatic);
assert($policy->modeFor('invented') === null);
assert((new RefundStateMachine())->afterGatewayResult(RefundStatus::Pending, RefundOutcome::unknown()) === RefundStatus::Pending);
assert((new RefundStateMachine())->afterGatewayResult(RefundStatus::Processing, RefundOutcome::unknown()) === RefundStatus::Unknown);
try { RefundOutcome::succeeded(''); exit(2); } catch (InvalidArgumentException) {}
try { (new RefundStateMachine())->afterGatewayResult(RefundStatus::ManualPending, RefundOutcome::succeeded('ref')); exit(3); }
catch (DomainException) {}
echo "Refund core standalone smoke PASSED\n";
