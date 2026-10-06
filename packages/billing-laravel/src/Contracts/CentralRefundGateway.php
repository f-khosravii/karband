<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\Contracts;

use Intl\Billing\Refund\RefundCommand;
use Intl\Billing\Refund\RefundOutcome;

/**
 * Epishkhan V2 Central Refund Gateway contract interface.
 * Supports full refund execution and reconciliation.
 */
interface CentralRefundGateway
{
    public function createRefund(RefundCommand $command): RefundOutcome;
    public function lookupRefund(string $centralRefundId): RefundOutcome;
    public function lookupRefundByOperationKey(string $operationKey): RefundOutcome;

    /** Legacy refund method */
    public function refund(RefundCommand $command): RefundOutcome;

    /** Legacy lookup method */
    public function lookup(RefundCommand $command): RefundOutcome;
}
