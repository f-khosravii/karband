<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';
use Karband\Billing\Engine\PaymentStateMachine;
use Karband\Billing\ValueObjects\Money;
use Karband\Billing\ValueObjects\PaymentOutcome;
use Karband\Billing\ValueObjects\PaymentStatus;

assert_options(ASSERT_ACTIVE, 1);
$machine = new PaymentStateMachine();
if ((new Money(100, 'IRR'))->amount !== 100 ||
    $machine->afterGatewayResult(PaymentStatus::Processing, PaymentOutcome::unknown()) !== PaymentStatus::Unknown ||
    $machine->afterGatewayResult(PaymentStatus::Succeeded, PaymentOutcome::failed()) !== PaymentStatus::Succeeded) {
    throw new RuntimeException('Billing standalone smoke failed.');
}
echo "Billing core smoke passed\n";
