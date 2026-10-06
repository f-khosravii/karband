<?php

declare(strict_types=1);

namespace Intl\Billing\Tests;

use Intl\Billing\Engine\PaymentStateMachine;
use Intl\Billing\ValueObjects\Money;
use Intl\Billing\ValueObjects\PaymentCommand;
use Intl\Billing\ValueObjects\PaymentOutcome;
use Intl\Billing\ValueObjects\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentCoreTest extends TestCase
{
    public function test_uses_integer_amount_and_currency(): void
    {
        self::assertTrue((new Money(1250, 'IRR'))->sameAs(new Money(1250, 'IRR')));
        self::assertFalse((new Money(1250, 'IRR'))->sameAs(new Money(1250, 'EUR')));
        self::assertSame(0, Money::zero('IRR')->amount);
    }

    public function test_negative_money_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(-1, 'IRR');
    }

    public function test_invalid_currency_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(15, 'irr');
    }

    public function test_success_requires_central_reference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentOutcome::succeeded('');
    }

    public function test_two_stage_prepared_and_succeeded_transitions(): void
    {
        $machine = new PaymentStateMachine();
        self::assertSame(PaymentStatus::Prepared, $machine->afterGatewayResult(
            PaymentStatus::Preparing, PaymentOutcome::prepared('central-ref-01')
        ));
        self::assertSame(PaymentStatus::Succeeded, $machine->afterGatewayResult(
            PaymentStatus::Confirming, PaymentOutcome::succeeded('central-ref-01')
        ));
        self::assertTrue(PaymentStatus::Succeeded->isFinal());
        self::assertTrue(PaymentStatus::Cancelled->isFinal());
    }

    public function test_timeout_remains_unknown(): void
    {
        $machine = new PaymentStateMachine();
        self::assertSame(PaymentStatus::Unknown, $machine->afterGatewayResult(
            PaymentStatus::Preparing, PaymentOutcome::unknown()
        ));
        self::assertTrue($machine->canReconcile(PaymentStatus::Unknown));
    }

    public function test_illegal_transition_cancelled_to_succeeded_is_rejected(): void
    {
        $machine = new PaymentStateMachine();
        $this->expectException(\DomainException::class);
        $machine->assertValidTransition(PaymentStatus::Cancelled, PaymentStatus::Succeeded);
    }

    public function test_illegal_transition_confirm_before_prepare_is_rejected(): void
    {
        $machine = new PaymentStateMachine();
        $this->expectException(\DomainException::class);
        $machine->assertValidTransition(PaymentStatus::Ready, PaymentStatus::Confirming);
    }

    public function test_success_cannot_be_downgraded_by_stale_lookup(): void
    {
        self::assertSame(PaymentStatus::Succeeded, (new PaymentStateMachine())->afterGatewayResult(
            PaymentStatus::Succeeded, PaymentOutcome::failed()
        ));
    }

    public function test_failure_cannot_be_downgraded_by_stale_lookup(): void
    {
        self::assertSame(PaymentStatus::Failed, (new PaymentStateMachine())->afterGatewayResult(
            PaymentStatus::Failed, PaymentOutcome::pending()
        ));
    }

    public function test_command_has_no_wallet_or_balance_fields(): void
    {
        $command = new PaymentCommand('client:123:1', '123', 'service', 'request', '7', 'agency', new Money(100, 'IRR'));
        self::assertSame('agency', $command->agencyId);
        self::assertFalse(property_exists($command, 'walletId'));
        self::assertFalse(property_exists($command, 'balance'));
    }
}
