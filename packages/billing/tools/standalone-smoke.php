<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';
use Intl\Billing\Engine\PaymentStateMachine;
use Intl\Billing\ValueObjects\Money;
use Intl\Billing\ValueObjects\PaymentOutcome;
use Intl\Billing\ValueObjects\PaymentStatus;

assert_options(ASSERT_ACTIVE, 1);
$machine = new PaymentStateMachine();
if ((new Money(100, 'IRR'))->amount !== 100 ||
    $machine->afterGatewayResult(PaymentStatus::Processing, PaymentOutcome::unknown()) !== PaymentStatus::Unknown ||
    $machine->afterGatewayResult(PaymentStatus::Succeeded, PaymentOutcome::failed()) !== PaymentStatus::Succeeded) {
    throw new RuntimeException('Billing standalone smoke failed.');
}
echo "Billing core smoke passed\n";
