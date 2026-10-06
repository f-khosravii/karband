<?php

declare(strict_types=1);

namespace Karband\BillingLaravel\Contracts;

use Karband\Billing\ValueObjects\CancelPaymentCommand;
use Karband\Billing\ValueObjects\CancelPaymentOutcome;
use Karband\Billing\ValueObjects\ConfirmPaymentCommand;
use Karband\Billing\ValueObjects\ConfirmPaymentOutcome;
use Karband\Billing\ValueObjects\PaymentCommand;
use Karband\Billing\ValueObjects\PaymentOutcome;
use Karband\Billing\ValueObjects\PreparePaymentCommand;
use Karband\Billing\ValueObjects\PreparePaymentOutcome;

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
