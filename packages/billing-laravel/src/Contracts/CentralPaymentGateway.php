<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\Contracts;

use Intl\Billing\ValueObjects\CancelPaymentCommand;
use Intl\Billing\ValueObjects\CancelPaymentOutcome;
use Intl\Billing\ValueObjects\ConfirmPaymentCommand;
use Intl\Billing\ValueObjects\ConfirmPaymentOutcome;
use Intl\Billing\ValueObjects\PaymentCommand;
use Intl\Billing\ValueObjects\PaymentOutcome;
use Intl\Billing\ValueObjects\PreparePaymentCommand;
use Intl\Billing\ValueObjects\PreparePaymentOutcome;

/**
 * Central Epishkhan V2 Payment Gateway interface.
 * Models two-stage financial lifecycle: prepare, confirm, cancel, lookup.
 */
interface CentralPaymentGateway
{
    public function preparePayment(PreparePaymentCommand $command): PreparePaymentOutcome;
    public function confirmPayment(ConfirmPaymentCommand $command): ConfirmPaymentOutcome;
    public function cancelPayment(CancelPaymentCommand $command): CancelPaymentOutcome;
    public function lookupPayment(string $centralPaymentId): PaymentOutcome;
    public function lookupByOperationKey(string $operationKey): PaymentOutcome;

    /** @deprecated Use two-stage preparePayment/confirmPayment for Epishkhan V2. */
    public function charge(PaymentCommand $command): PaymentOutcome;

    /** Legacy lookup by command */
    public function lookup(PaymentCommand $command): PaymentOutcome;
}
