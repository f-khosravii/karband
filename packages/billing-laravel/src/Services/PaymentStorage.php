<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\Services;

use Illuminate\Database\Connection;
use Intl\Billing\ValueObjects\Money;
use Intl\Billing\ValueObjects\PaymentStatus;
use Intl\BillingLaravel\ValueObjects\PaymentSnapshot;

final readonly class PaymentStorage
{
    public function __construct(public Connection $connection) {}

    public function snapshot(object $row): PaymentSnapshot
    {
        return new PaymentSnapshot(
            id: (string) $row->id,
            serviceCode: (string) $row->service_code,
            resourceType: (string) $row->resource_type,
            resourceId: (string) $row->resource_id,
            agencyId: (string) $row->agency_id,
            money: new Money((int) $row->amount, (string) $row->currency),
            status: PaymentStatus::from((string) $row->status),
            attempt: (int) $row->attempt,
            revision: (int) $row->revision,
            centralReference: $row->central_reference === null ? null : (string) $row->central_reference,
            centralPaymentId: isset($row->central_payment_id) && $row->central_payment_id !== null ? (string) $row->central_payment_id : ($row->central_reference === null ? null : (string) $row->central_reference),
            pricingSource: isset($row->pricing_source) && $row->pricing_source !== null ? (string) $row->pricing_source : 'fixed_catalog',
            capturedAmount: isset($row->captured_amount) && $row->captured_amount !== null ? (int) $row->captured_amount : null,
            centralFeeAmount: isset($row->central_fee_amount) && $row->central_fee_amount !== null ? (int) $row->central_fee_amount : null,
            centralTaxAmount: isset($row->central_tax_amount) && $row->central_tax_amount !== null ? (int) $row->central_tax_amount : null,
            centralTotalAmount: isset($row->central_total_amount) && $row->central_total_amount !== null ? (int) $row->central_total_amount : null,
            prepareOperationKey: isset($row->prepare_operation_key) && $row->prepare_operation_key !== null ? (string) $row->prepare_operation_key : null,
            confirmOperationKey: isset($row->confirm_operation_key) && $row->confirm_operation_key !== null ? (string) $row->confirm_operation_key : null,
        );
    }
}
