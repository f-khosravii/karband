<?php

declare(strict_types=1);

namespace Intl\Billing\Tests;

use Intl\Billing\Refund\RefundCommand;
use Intl\Billing\Refund\RefundInitiation;
use Intl\Billing\Refund\RefundOutcome;
use Intl\Billing\Refund\RefundPolicy;
use Intl\Billing\Refund\RefundStateMachine;
use Intl\Billing\Refund\RefundStatus;
use Intl\Billing\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class RefundCoreTest extends TestCase
{
    public function test_policy_serialization_and_disallowed_event(): void
    {
        $policy = new RefundPolicy(['service.rejected' => RefundInitiation::Automatic]);
        self::assertSame(RefundInitiation::Automatic, RefundPolicy::fromArray($policy->toArray())->modeFor('service.rejected'));
        self::assertNull($policy->modeFor('request.cancelled'));
    }

    public function test_empty_policy_is_non_refundable(): void
    {
        self::assertSame([], RefundPolicy::nonRefundable()->toArray());
    }

    public function test_policy_rejects_invalid_event_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RefundPolicy(['arbitrary user input!' => RefundInitiation::Automatic]);
    }

    public function test_success_requires_provider_reference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RefundOutcome::succeeded('');
    }

    public function test_pending_unknown_lookup_cannot_erase_pending(): void
    {
        $machine = new RefundStateMachine();
        self::assertSame(RefundStatus::Pending, $machine->afterGatewayResult(RefundStatus::Pending, RefundOutcome::unknown()));
    }

    public function test_uncertain_refund_never_turns_into_failure_by_default(): void
    {
        $machine = new RefundStateMachine();
        self::assertSame(RefundStatus::Unknown, $machine->afterGatewayResult(RefundStatus::Processing, RefundOutcome::unknown()));
    }

    public function test_final_refund_success_cannot_be_downgraded(): void
    {
        $machine = new RefundStateMachine();
        self::assertSame(RefundStatus::Succeeded, $machine->afterGatewayResult(RefundStatus::Succeeded, RefundOutcome::failed()));
    }

    public function test_manual_state_cannot_accept_provider_result(): void
    {
        $this->expectException(\DomainException::class);
        (new RefundStateMachine())->afterGatewayResult(RefundStatus::ManualPending, RefundOutcome::succeeded('ref'));
    }

    public function test_full_refund_command_contains_no_wallet_fields(): void
    {
        $command = new RefundCommand('ns:refund:r1:1', 'r1', 'p1', 'confirmed-payment-ref', new Money(600, 'IRR'));
        self::assertSame(600, $command->money->amount);
        self::assertFalse(property_exists($command, 'walletId'));
        self::assertFalse(property_exists($command, 'partialAmount'));
    }
}
