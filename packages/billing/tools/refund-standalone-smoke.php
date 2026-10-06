<?php

declare(strict_types=1);

spl_autoload_register(static function (string $name): void {
    if (!str_starts_with($name, 'Intl\\Billing\\')) { return; }
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($name, strlen('Intl\\Billing\\'))) . '.php';
    if (is_file($path)) { require $path; }
});

use Intl\Billing\Refund\RefundInitiation;
use Intl\Billing\Refund\RefundOutcome;
use Intl\Billing\Refund\RefundPolicy;
use Intl\Billing\Refund\RefundStateMachine;
use Intl\Billing\Refund\RefundStatus;

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
