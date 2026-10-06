<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\Billing\Engine\PaymentStateMachine;
use Intl\Billing\ValueObjects\CancelPaymentCommand;
use Intl\Billing\ValueObjects\CancelPaymentOutcome;
use Intl\Billing\ValueObjects\ConfirmPaymentCommand;
use Intl\Billing\ValueObjects\ConfirmPaymentOutcome;
use Intl\Billing\ValueObjects\Money;
use Intl\Billing\ValueObjects\PaymentCommand;
use Intl\Billing\ValueObjects\PaymentOutcome;
use Intl\Billing\ValueObjects\PaymentStatus;
use Intl\Billing\ValueObjects\PreparePaymentCommand;
use Intl\Billing\ValueObjects\PreparePaymentOutcome;
use Intl\BillingLaravel\Contracts\CentralPaymentGateway;
use Intl\BillingLaravel\Contracts\TrustedFinancialTermsProvider;
use Intl\BillingLaravel\Contracts\TrustedQuoteProvider;
use Intl\BillingLaravel\Contracts\TrustedRefundPolicyProvider;
use Intl\BillingLaravel\Exceptions\PaymentConflict;
use Intl\BillingLaravel\Exceptions\PaymentNotFound;
use Intl\BillingLaravel\ValueObjects\FinancialTermsSnapshot;
use Intl\BillingLaravel\ValueObjects\PaymentSnapshot;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\ServiceCatalog\Financial\PricingMode;

/**
 * Epishkhan V2 Two-Stage Payment Service.
 * Central Epishkhan V2 is authoritative for payment prepare, wallet hold, confirmation, and capture.
 * Local microservices remain authoritative for eligibility, pricing mode, and refund policies.
 * No wallet internals, raw OAuth credentials, or central JSON responses are exposed.
 */
final readonly class PaymentService
{
    public function __construct(
        private PaymentStorage $storage,
        private PrincipalProvider $principals,
        private AuthorizerInterface $authorization,
        private TrustedQuoteProvider $quotes,
        private TrustedRefundPolicyProvider $refundPolicies,
        private CentralPaymentGateway $central,
        private PaymentStateMachine $machine = new PaymentStateMachine(),
        private ?TrustedFinancialTermsProvider $financialTerms = null,
    ) {}

    /** Resolve financial terms (price, pricing mode, refund policy, revisions) for an attempt. */
    private function termsForAttempt(string $serviceCode, string $resourceType, string $resourceId, string $agency): FinancialTermsSnapshot
    {
        if ($this->financialTerms !== null) {
            return $this->financialTerms->snapshot($serviceCode, $resourceType, $resourceId, $agency);
        }
        if ((bool) config('intl-billing.require_atomic_financial_terms', false)) {
            throw new \RuntimeException('TrustedFinancialTermsProvider is required for payment attempts.');
        }
        $money = $this->quotes->quote($serviceCode, $resourceType, $resourceId, $agency);
        $policy = $this->refundPolicies->forPayment($serviceCode, $resourceType, $resourceId, $agency);
        return new FinancialTermsSnapshot(
            money: $money,
            refundPolicy: $policy,
            catalogRevision: 0,
            pricingMode: PricingMode::FixedCatalog,
            pricingRevision: 0,
            refundPolicyRevision: 0,
        );
    }

    public function create(string $serviceCode, string $resourceType, string $resourceId, string $idempotencyKey): PaymentSnapshot
    {
        self::identifier($serviceCode, 120, 'Service');
        self::identifier($resourceType, 120, 'Resource type');
        self::identifier($resourceId, 191, 'Resource id');
        self::identifier($idempotencyKey, 120, 'Local idempotency key');
        $actor = $this->authorize('billing.payment.create', $resourceType, $resourceId);
        $agency = $this->requireAgency($actor);
        $db = $this->storage->connection;
        $existing = $db->table('intl_payment_intents')
            ->where('agency_id', $agency)->where('local_idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            $this->assertSameRequest($existing, $serviceCode, $resourceType, $resourceId, $agency, $idempotencyKey);
            return $this->storage->snapshot($existing);
        }

        $terms = $this->termsForAttempt($serviceCode, $resourceType, $resourceId, $agency);

        if ($terms->pricingMode === PricingMode::NoCharge) {
            throw new PaymentConflict('Service is no_charge; payment operation is not required.');
        }

        $policyJson = json_encode($terms->refundPolicy->toArray(), JSON_THROW_ON_ERROR);
        $namespace = $this->namespace();
        try {
            return $db->transaction(function () use ($db, $serviceCode, $resourceType, $resourceId, $idempotencyKey, $actor, $agency, $terms, $policyJson, $namespace): PaymentSnapshot {
                $byKey = $db->table('intl_payment_intents')
                    ->where('agency_id', $agency)->where('local_idempotency_key', $idempotencyKey)->first();
                if ($byKey !== null) {
                    $this->assertSameRequest($byKey, $serviceCode, $resourceType, $resourceId, $agency, $idempotencyKey);
                    return $this->storage->snapshot($byKey);
                }
                if ($db->table('intl_payment_intents')->where('resource_type', $resourceType)
                    ->where('resource_id', $resourceId)->exists()) {
                    throw new PaymentConflict('This resource already has a payment intent; use its original key.');
                }
                $now = now();
                $id = (string) Str::ulid();
                $db->table('intl_payment_intents')->insert([
                    'id' => $id,
                    'service_code' => $serviceCode,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'agency_id' => $agency,
                    'created_by' => $actor->id,
                    'client_namespace' => $namespace,
                    'local_idempotency_key' => $idempotencyKey,
                    'amount' => $terms->money->amount,
                    'currency' => $terms->money->currency,
                    'status' => PaymentStatus::Ready->value,
                    'attempt' => 1,
                    'revision' => 0,
                    'central_reference' => null,
                    'central_payment_id' => null,
                    'pricing_source' => $terms->pricingMode->value,
                    'pricing_revision' => $terms->pricingRevision,
                    'refund_policy_revision' => $terms->refundPolicyRevision,
                    'captured_amount' => null,
                    'dispatched_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $db->table('intl_payment_attempts')->insert([
                    'payment_id' => $id,
                    'agency_id' => $agency,
                    'attempt' => 1,
                    'local_key' => $idempotencyKey,
                    'amount' => $terms->money->amount,
                    'currency' => $terms->money->currency,
                    'refund_policy_json' => $policyJson,
                    'policy_verified' => true,
                    'status' => PaymentStatus::Ready->value,
                    'central_payment_id' => null,
                    'pricing_source' => $terms->pricingMode->value,
                    'pricing_revision' => $terms->pricingRevision,
                    'refund_policy_revision' => $terms->refundPolicyRevision,
                    'base_amount' => $terms->money->amount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->event($id, 1, 'created', null, PaymentStatus::Ready);
                return $this->storage->snapshot($db->table('intl_payment_intents')->where('id', $id)->first());
            }, 1);
        } catch (UniqueConstraintViolationException $exception) {
            throw new PaymentConflict('A payment with this resource or idempotency key already exists.', previous: $exception);
        }
    }

    public function view(string $paymentId): PaymentSnapshot
    {
        return $this->storage->snapshot($this->findAuthorized($paymentId, 'billing.payment.view'));
    }

    /**
     * Stage 1: Prepare payment centrally (hold / reservation).
     */
    public function preparePayment(string $paymentId, string $prepareOperationKey): PaymentSnapshot
    {
        self::identifier($prepareOperationKey, 120, 'Prepare operation key');
        $row = $this->findAuthorized($paymentId, 'billing.payment.execute');
        $actor = $this->principals->current()->actor;
        $agency = $this->requireAgency($actor);
        $this->assertOwnedByAgency($row, $agency);

        $db = $this->storage->connection;
        $attempt = $db->table('intl_payment_attempts')
            ->where('payment_id', $paymentId)->where('attempt', $row->attempt)->first();
        if ($attempt === null) {
            throw new PaymentNotFound('Payment attempt not found.');
        }

        if ($attempt->prepare_operation_key === $prepareOperationKey) {
            if (in_array($row->status, [PaymentStatus::Prepared->value, PaymentStatus::Preparing->value, PaymentStatus::Succeeded->value], true)) {
                return $this->storage->snapshot($row);
            }
        }

        if ($row->status !== PaymentStatus::Ready->value && $row->status !== PaymentStatus::Preparing->value) {
            throw new PaymentConflict('Payment is not in ready state for preparation.');
        }

        $claimed = $db->transaction(function () use ($db, $paymentId, $prepareOperationKey, $row): object {
            $updated = $db->table('intl_payment_intents')->where('id', $paymentId)
                ->whereIn('status', [PaymentStatus::Ready->value, PaymentStatus::Preparing->value])
                ->where('revision', $row->revision)
                ->update([
                    'status' => PaymentStatus::Preparing->value,
                    'revision' => $row->revision + 1,
                    'dispatched_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new PaymentConflict('Payment was prepared concurrently.');
            }
            $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $row->attempt)
                ->update([
                    'status' => PaymentStatus::Preparing->value,
                    'prepare_operation_key' => $prepareOperationKey,
                    'updated_at' => now(),
                ]);
            $this->event($paymentId, (int) $row->attempt, 'prepare_dispatched', PaymentStatus::from((string) $row->status), PaymentStatus::Preparing);
            return $db->table('intl_payment_intents')->where('id', $paymentId)->first();
        }, 1);

        $cmd = new PreparePaymentCommand(
            operationKey: $prepareOperationKey,
            paymentId: $claimed->id,
            serviceCode: $claimed->service_code,
            resourceType: $claimed->resource_type,
            resourceId: $claimed->resource_id,
            agencyId: $claimed->agency_id,
            amount: new Money((int) $claimed->amount, (string) $claimed->currency),
            revision: (int) $claimed->revision,
        );

        try {
            $outcome = $this->central->preparePayment($cmd);
        } catch (\Throwable) {
            $outcome = PreparePaymentOutcome::unknown('Transport failure during prepare.');
        }

        return $this->recordPrepareResult($paymentId, (int) $claimed->attempt, $outcome);
    }

    /**
     * Stage 2: Confirm prepared payment centrally (capture).
     */
    public function confirmPayment(string $paymentId, string $confirmOperationKey): PaymentSnapshot
    {
        self::identifier($confirmOperationKey, 120, 'Confirm operation key');
        $row = $this->findAuthorized($paymentId, 'billing.payment.execute');
        $actor = $this->principals->current()->actor;
        $agency = $this->requireAgency($actor);
        $this->assertOwnedByAgency($row, $agency);

        $db = $this->storage->connection;
        $attempt = $db->table('intl_payment_attempts')
            ->where('payment_id', $paymentId)->where('attempt', $row->attempt)->first();

        if ($attempt !== null && $attempt->confirm_operation_key === $confirmOperationKey) {
            if ($row->status === PaymentStatus::Succeeded->value) {
                return $this->storage->snapshot($row);
            }
        }

        $currentStatus = PaymentStatus::from((string) $row->status);
        if ($currentStatus === PaymentStatus::Cancelled) {
            throw new PaymentConflict('Cannot confirm a cancelled payment.');
        }
        if ($currentStatus !== PaymentStatus::Prepared && $currentStatus !== PaymentStatus::Confirming && $currentStatus !== PaymentStatus::Unknown) {
            throw new PaymentConflict('Payment must be prepared before confirmation.');
        }

        $centralPaymentId = $row->central_payment_id ?? $row->central_reference;
        if ($centralPaymentId === null || $centralPaymentId === '') {
            throw new PaymentConflict('Central payment ID is required to confirm.');
        }

        $claimed = $db->transaction(function () use ($db, $paymentId, $confirmOperationKey, $row): object {
            $updated = $db->table('intl_payment_intents')->where('id', $paymentId)
                ->whereIn('status', [PaymentStatus::Prepared->value, PaymentStatus::Confirming->value, PaymentStatus::Unknown->value])
                ->where('revision', $row->revision)
                ->update([
                    'status' => PaymentStatus::Confirming->value,
                    'revision' => $row->revision + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new PaymentConflict('Payment confirm conflicted.');
            }
            $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $row->attempt)
                ->update([
                    'status' => PaymentStatus::Confirming->value,
                    'confirm_operation_key' => $confirmOperationKey,
                    'updated_at' => now(),
                ]);
            $this->event($paymentId, (int) $row->attempt, 'confirm_dispatched', PaymentStatus::from((string) $row->status), PaymentStatus::Confirming);
            return $db->table('intl_payment_intents')->where('id', $paymentId)->first();
        }, 1);

        $cmd = new ConfirmPaymentCommand(
            operationKey: $confirmOperationKey,
            paymentId: $claimed->id,
            centralPaymentId: (string) $centralPaymentId,
            amount: new Money((int) $claimed->amount, (string) $claimed->currency),
        );

        try {
            $outcome = $this->central->confirmPayment($cmd);
        } catch (\Throwable) {
            $outcome = ConfirmPaymentOutcome::unknown((string) $centralPaymentId, 'Transport failure during confirm.');
        }

        return $this->recordConfirmResult($paymentId, (int) $claimed->attempt, $outcome);
    }

    /**
     * Cancel a prepared payment centrally. Idempotent.
     */
    public function cancelPayment(string $paymentId, string $cancelOperationKey, ?string $reason = null): PaymentSnapshot
    {
        self::identifier($cancelOperationKey, 120, 'Cancel operation key');
        $row = $this->findAuthorized($paymentId, 'billing.payment.execute');
        $agency = $this->requireAgency($this->principals->current()->actor);
        $this->assertOwnedByAgency($row, $agency);

        if ($row->status === PaymentStatus::Cancelled->value) {
            return $this->storage->snapshot($row);
        }

        if ($row->status === PaymentStatus::Succeeded->value) {
            throw new PaymentConflict('Cannot cancel a succeeded payment.');
        }

        $centralPaymentId = $row->central_payment_id ?? $row->central_reference;
        if ($centralPaymentId === null || $centralPaymentId === '') {
            $db = $this->storage->connection;
            $db->table('intl_payment_intents')->where('id', $paymentId)
                ->update(['status' => PaymentStatus::Cancelled->value, 'updated_at' => now()]);
            $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $row->attempt)
                ->update(['status' => PaymentStatus::Cancelled->value, 'updated_at' => now()]);
            $this->event($paymentId, (int) $row->attempt, 'cancelled_locally', PaymentStatus::from((string) $row->status), PaymentStatus::Cancelled);
            return $this->storage->snapshot($db->table('intl_payment_intents')->where('id', $paymentId)->first());
        }

        $cmd = new CancelPaymentCommand(
            operationKey: $cancelOperationKey,
            paymentId: $paymentId,
            centralPaymentId: (string) $centralPaymentId,
            reason: $reason,
        );

        try {
            $outcome = $this->central->cancelPayment($cmd);
        } catch (\Throwable) {
            $outcome = CancelPaymentOutcome::unknown((string) $centralPaymentId, 'Transport failure during cancel.');
        }

        return $this->recordCancelResult($paymentId, (int) $row->attempt, $outcome);
    }

    /**
     * Reconcile uncertain financial operations via central lookup.
     */
    public function reconcile(string $paymentId): PaymentSnapshot
    {
        $row = $this->findAuthorized($paymentId, 'billing.payment.reconcile');
        $status = PaymentStatus::from((string) $row->status);
        if ($status->isFinal()) {
            return $this->storage->snapshot($row);
        }
        if (!$this->machine->canReconcile($status)) {
            throw new PaymentConflict('Payment has not been dispatched.');
        }

        if ($status === PaymentStatus::Processing) {
            $seconds = (int) config('intl-billing.reconcile_after_seconds', 0);
            if ($seconds > 0 && isset($row->dispatched_at) && $row->dispatched_at !== null) {
                $dispatchedAt = \Illuminate\Support\Carbon::parse((string) $row->dispatched_at);
                if (now()->diffInSeconds($dispatchedAt) < $seconds) {
                    throw new PaymentConflict('Recent processing attempt must not be queried.');
                }
            }
        }

        $centralPaymentId = $row->central_payment_id ?? $row->central_reference;
        $db = $this->storage->connection;
        $attempt = $db->table('intl_payment_attempts')
            ->where('payment_id', $paymentId)->where('attempt', $row->attempt)->first();

        $operationKey = $attempt->confirm_operation_key ?? $attempt->prepare_operation_key ?? $attempt->local_key ?? null;

        try {
            $outcome = null;
            if ($centralPaymentId !== null && $centralPaymentId !== '') {
                $outcome = $this->central->lookupPayment((string) $centralPaymentId);
            }
            if (($outcome === null || $outcome->status === PaymentStatus::Unknown || $outcome->status === PaymentStatus::Pending) && $operationKey !== null && $operationKey !== '' && $operationKey !== self::command($row)->requestKey) {
                $outcome = $this->central->lookupByOperationKey((string) $operationKey);
            }
            if ($outcome === null || $outcome->status === PaymentStatus::Unknown || $outcome->status === PaymentStatus::Pending) {
                $outcome = $this->central->lookup(self::command($row));
            }
        } catch (\Throwable) {
            $outcome = PaymentOutcome::unknown();
        }

        return $this->recordCentralResult($paymentId, (int) $row->attempt, $outcome, 'reconciled');
    }

    /** @deprecated Use preparePayment + confirmPayment for Epishkhan V2 two-stage billing. */
    public function dispatch(string $paymentId, int $confirmedAmount, string $confirmedCurrency, int $expectedRevision): PaymentSnapshot
    {
        $row = $this->findAuthorized($paymentId, 'billing.payment.execute');
        $actor = $this->principals->current()->actor;
        $agency = $this->requireAgency($actor);
        $this->assertOwnedByAgency($row, $agency);
        $this->assertStillPayable($row);

        $db = $this->storage->connection;
        $claimed = $db->transaction(function () use ($db, $paymentId, $agency, $confirmedAmount, $confirmedCurrency, $expectedRevision): object {
            $row = $db->table('intl_payment_intents')->where('id', $paymentId)->lockForUpdate()->first();
            if ($row === null) { throw new PaymentNotFound('Payment not found.'); }
            $this->assertOwnedByAgency($row, $agency);
            if ($row->status !== PaymentStatus::Ready->value) {
                throw new PaymentConflict('Payment already dispatched; use status or reconciliation, never charge again.');
            }
            if ((int) $row->revision !== $expectedRevision || (int) $row->amount !== $confirmedAmount
                || (string) $row->currency !== $confirmedCurrency) {
                throw new PaymentConflict('Agency confirmation is stale or does not match the frozen attempt price.');
            }
            $attempt = $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $row->attempt)->first();
            $this->assertReadyNotExpired($row, $attempt);
            $updated = $db->table('intl_payment_intents')->where('id', $paymentId)
                ->where('status', PaymentStatus::Ready->value)->where('revision', $row->revision)
                ->update([
                    'status' => PaymentStatus::Processing->value,
                    'revision' => $row->revision + 1,
                    'dispatched_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) { throw new PaymentConflict('Payment was dispatched concurrently.'); }
            $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $row->attempt)
                ->update(['status' => PaymentStatus::Processing->value, 'updated_at' => now()]);
            $this->event($paymentId, (int) $row->attempt, 'dispatch_claimed', PaymentStatus::Ready, PaymentStatus::Processing);
            return $db->table('intl_payment_intents')->where('id', $paymentId)->first();
        }, 1);

        try {
            $outcome = $this->central->charge(self::command($claimed));
        } catch (\Throwable) {
            $outcome = PaymentOutcome::unknown();
        }
        return $this->recordCentralResult($paymentId, (int) $claimed->attempt, $outcome, 'dispatch_result');
    }

    public function retryFailed(string $paymentId, string $retryKey): PaymentSnapshot
    {
        self::identifier($retryKey, 120, 'Retry idempotency key');
        $row = $this->findAuthorized($paymentId, 'billing.payment.retry');
        $agency = $this->requireAgency($this->principals->current()->actor);
        $this->assertOwnedByAgency($row, $agency);
        $max = $this->maxAttempts();
        $db = $this->storage->connection;
        $existingKey = $db->table('intl_payment_attempts')->where('agency_id', $agency)->where('local_key', $retryKey)->first();
        if ($existingKey !== null) {
            if ($existingKey->payment_id === $paymentId && (int) $existingKey->attempt === (int) $row->attempt) {
                return $this->storage->snapshot($row);
            }
            throw new PaymentConflict('Idempotency key belongs to another payment attempt.');
        }
        if ($row->status !== PaymentStatus::Failed->value) {
            throw new PaymentConflict('New attempt requires confirmed NO-DEBIT failure.');
        }
        $terms = $this->termsForAttempt((string) $row->service_code, (string) $row->resource_type, (string) $row->resource_id, $agency);
        $policyJson = json_encode($terms->refundPolicy->toArray(), JSON_THROW_ON_ERROR);
        try {
            return $db->transaction(function () use ($db, $paymentId, $agency, $max, $terms, $policyJson, $retryKey): PaymentSnapshot {
                $row = $db->table('intl_payment_intents')->where('id', $paymentId)->lockForUpdate()->first();
                if ($row === null) { throw new PaymentNotFound('Payment not found.'); }
                $this->assertOwnedByAgency($row, $agency);
                $keyRow = $db->table('intl_payment_attempts')->where('agency_id', $agency)->where('local_key', $retryKey)->first();
                if ($keyRow !== null) {
                    if ($keyRow->payment_id === $paymentId && (int) $keyRow->attempt === (int) $row->attempt) {
                        return $this->storage->snapshot($row);
                    }
                    throw new PaymentConflict('Retry idempotency key was reused.');
                }
                if ($row->status !== PaymentStatus::Failed->value || (int) $row->attempt >= $max) {
                    throw new PaymentConflict('Only definitive failed payments below max_attempts may retry.');
                }
                $attempt = (int) $row->attempt + 1;
                $updated = $db->table('intl_payment_intents')->where('id', $paymentId)
                    ->where('status', PaymentStatus::Failed->value)->where('revision', $row->revision)
                    ->update([
                        'attempt' => $attempt,
                        'status' => PaymentStatus::Ready->value,
                        'amount' => $terms->money->amount,
                        'currency' => $terms->money->currency,
                        'central_reference' => null,
                        'central_payment_id' => null,
                        'dispatched_at' => null,
                        'revision' => $row->revision + 1,
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) { throw new PaymentConflict('Concurrent retry detected.'); }
                $db->table('intl_payment_attempts')->insert([
                    'payment_id' => $paymentId,
                    'agency_id' => $agency,
                    'attempt' => $attempt,
                    'local_key' => $retryKey,
                    'amount' => $terms->money->amount,
                    'currency' => $terms->money->currency,
                    'refund_policy_json' => $policyJson,
                    'policy_verified' => true,
                    'status' => PaymentStatus::Ready->value,
                    'pricing_source' => $terms->pricingMode->value,
                    'pricing_revision' => $terms->pricingRevision,
                    'refund_policy_revision' => $terms->refundPolicyRevision,
                    'base_amount' => $terms->money->amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->event($paymentId, $attempt, 'retry_with_new_quote', PaymentStatus::Failed, PaymentStatus::Ready, $row->central_reference === null ? null : (string) $row->central_reference);
                return $this->storage->snapshot($db->table('intl_payment_intents')->where('id', $paymentId)->first());
            }, 1);
        } catch (UniqueConstraintViolationException $exception) {
            throw new PaymentConflict('Concurrent retry key or attempt collision.', previous: $exception);
        }
    }

    public function refreshReady(string $paymentId, string $refreshKey): PaymentSnapshot
    {
        self::identifier($refreshKey, 120, 'Refresh idempotency key');
        $row = $this->findAuthorized($paymentId, 'billing.payment.retry');
        $agency = $this->requireAgency($this->principals->current()->actor);
        $this->assertOwnedByAgency($row, $agency);
        $terms = $this->termsForAttempt((string) $row->service_code, (string) $row->resource_type, (string) $row->resource_id, $agency);
        $db = $this->storage->connection;
        try {
            return $db->transaction(function () use ($db, $paymentId, $agency, $refreshKey, $terms): PaymentSnapshot {
                $row = $db->table('intl_payment_intents')->where('id', $paymentId)->lockForUpdate()->first();
                if ($row === null) { throw new PaymentNotFound('Payment not found.'); }
                $this->assertOwnedByAgency($row, $agency);
                $keyRow = $db->table('intl_payment_attempts')->where('agency_id', $agency)->where('local_key', $refreshKey)->first();
                if ($keyRow !== null) {
                    if ($keyRow->payment_id === $paymentId && (int) $keyRow->attempt === (int) $row->attempt) {
                        return $this->storage->snapshot($row);
                    }
                    throw new PaymentConflict('Refresh idempotency key was reused.');
                }
                if ($row->status !== PaymentStatus::Ready->value || (int) $row->attempt >= $this->maxAttempts()) {
                    throw new PaymentConflict('Only an unused ready payment may be re-quoted within max_attempts.');
                }
                $attempt = (int) $row->attempt + 1;
                $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $row->attempt)
                    ->update(['status' => 'superseded', 'updated_at' => now()]);
                $updated = $db->table('intl_payment_intents')->where('id', $paymentId)
                    ->where('status', PaymentStatus::Ready->value)->where('revision', $row->revision)
                    ->update(['amount' => $terms->money->amount, 'currency' => $terms->money->currency,
                        'attempt' => $attempt, 'revision' => $row->revision + 1, 'updated_at' => now()]);
                if ($updated !== 1) { throw new PaymentConflict('Concurrent quote refresh.'); }
                $db->table('intl_payment_attempts')->insert([
                    'payment_id' => $paymentId,
                    'agency_id' => $agency,
                    'attempt' => $attempt,
                    'local_key' => $refreshKey,
                    'amount' => $terms->money->amount,
                    'currency' => $terms->money->currency,
                    'refund_policy_json' => json_encode($terms->refundPolicy->toArray(), JSON_THROW_ON_ERROR),
                    'policy_verified' => true,
                    'status' => PaymentStatus::Ready->value,
                    'pricing_source' => $terms->pricingMode->value,
                    'pricing_revision' => $terms->pricingRevision,
                    'refund_policy_revision' => $terms->refundPolicyRevision,
                    'base_amount' => $terms->money->amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->event($paymentId, $attempt, 'fresh_quote', PaymentStatus::Ready, PaymentStatus::Ready);
                return $this->storage->snapshot($db->table('intl_payment_intents')->where('id', $paymentId)->first());
            }, 1);
        } catch (UniqueConstraintViolationException $exception) {
            throw new PaymentConflict('Concurrent refresh key or attempt collision.', previous: $exception);
        }
    }

    public function attemptHistory(string $paymentId): array
    {
        $this->findAuthorized($paymentId, 'billing.payment.view');
        return $this->storage->connection->table('intl_payment_attempts')->where('payment_id', $paymentId)
            ->select('attempt', 'amount', 'currency', 'status', 'central_reference', 'central_payment_id', 'created_at')
            ->orderBy('attempt')->get()->all();
    }

    public function history(string $paymentId): array
    {
        $this->findAuthorized($paymentId, 'billing.payment.view');
        return $this->storage->connection->table('intl_payment_events')
            ->where('payment_id', $paymentId)->orderBy('id')->get()->all();
    }

    private function recordPrepareResult(string $paymentId, int $attempt, PreparePaymentOutcome $outcome): PaymentSnapshot
    {
        $db = $this->storage->connection;
        return $db->transaction(function () use ($db, $paymentId, $attempt, $outcome): PaymentSnapshot {
            $row = $db->table('intl_payment_intents')->where('id', $paymentId)->lockForUpdate()->first();
            if ($row === null) { throw new PaymentNotFound('Payment not found.'); }
            $before = PaymentStatus::from((string) $row->status);
            $next = $outcome->status;
            $centralId = $outcome->centralPaymentId ?? $row->central_payment_id ?? $row->central_reference;

            $updated = $db->table('intl_payment_intents')->where('id', $paymentId)
                ->where('revision', $row->revision)
                ->update([
                    'status' => $next->value,
                    'central_reference' => $centralId,
                    'central_payment_id' => $centralId,
                    'central_fee_amount' => $outcome->centralFeeAmount,
                    'central_tax_amount' => $outcome->centralTaxAmount,
                    'central_total_amount' => $outcome->centralTotalAmount,
                    'revision' => $row->revision + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) { throw new PaymentConflict('Concurrent prepare result update detected.'); }

            $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $attempt)
                ->update([
                    'status' => $next->value,
                    'central_reference' => $centralId,
                    'central_payment_id' => $centralId,
                    'central_fee_amount' => $outcome->centralFeeAmount,
                    'central_tax_amount' => $outcome->centralTaxAmount,
                    'central_total_amount' => $outcome->centralTotalAmount,
                    'updated_at' => now(),
                ]);

            $this->event($paymentId, $attempt, 'prepare_result', $before, $next, $centralId);
            return $this->storage->snapshot($db->table('intl_payment_intents')->where('id', $paymentId)->first());
        }, 1);
    }

    private function recordConfirmResult(string $paymentId, int $attempt, ConfirmPaymentOutcome $outcome): PaymentSnapshot
    {
        $db = $this->storage->connection;
        return $db->transaction(function () use ($db, $paymentId, $attempt, $outcome): PaymentSnapshot {
            $row = $db->table('intl_payment_intents')->where('id', $paymentId)->lockForUpdate()->first();
            if ($row === null) { throw new PaymentNotFound('Payment not found.'); }
            $before = PaymentStatus::from((string) $row->status);
            $next = $outcome->status;

            $captured = $outcome->capturedAmount ?? ($next === PaymentStatus::Succeeded ? (int) $row->amount : null);

            $updated = $db->table('intl_payment_intents')->where('id', $paymentId)
                ->where('revision', $row->revision)
                ->update([
                    'status' => $next->value,
                    'captured_amount' => $captured,
                    'revision' => $row->revision + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) { throw new PaymentConflict('Concurrent confirm result update detected.'); }

            $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $attempt)
                ->update([
                    'status' => $next->value,
                    'captured_amount' => $captured,
                    'updated_at' => now(),
                ]);

            $this->event($paymentId, $attempt, 'confirm_result', $before, $next, (string) $outcome->centralPaymentId);
            return $this->storage->snapshot($db->table('intl_payment_intents')->where('id', $paymentId)->first());
        }, 1);
    }

    private function recordCancelResult(string $paymentId, int $attempt, CancelPaymentOutcome $outcome): PaymentSnapshot
    {
        $db = $this->storage->connection;
        return $db->transaction(function () use ($db, $paymentId, $attempt, $outcome): PaymentSnapshot {
            $row = $db->table('intl_payment_intents')->where('id', $paymentId)->lockForUpdate()->first();
            if ($row === null) { throw new PaymentNotFound('Payment not found.'); }
            $before = PaymentStatus::from((string) $row->status);
            $next = $outcome->status;

            $db->table('intl_payment_intents')->where('id', $paymentId)
                ->update(['status' => $next->value, 'revision' => $row->revision + 1, 'updated_at' => now()]);
            $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $attempt)
                ->update(['status' => $next->value, 'updated_at' => now()]);

            $this->event($paymentId, $attempt, 'cancel_result', $before, $next, $outcome->centralPaymentId);
            return $this->storage->snapshot($db->table('intl_payment_intents')->where('id', $paymentId)->first());
        }, 1);
    }

    private function recordCentralResult(string $paymentId, int $attempt, PaymentOutcome $outcome, string $event): PaymentSnapshot
    {
        $db = $this->storage->connection;
        return $db->transaction(function () use ($db, $paymentId, $attempt, $outcome, $event): PaymentSnapshot {
            $row = $db->table('intl_payment_intents')->where('id', $paymentId)->lockForUpdate()->first();
            if ($row === null) { throw new PaymentNotFound('Payment not found.'); }
            if ((int) $row->attempt !== $attempt) {
                return $this->storage->snapshot($row);
            }
            $before = PaymentStatus::from((string) $row->status);
            $next = $this->machine->afterGatewayResult($before, $outcome);
            if ($before->isFinal()) { return $this->storage->snapshot($row); }
            $oldRef = $row->central_payment_id ?? $row->central_reference;
            $newRef = $outcome->providerReference;
            if ($oldRef !== null && $newRef !== null && (string) $oldRef !== (string) $newRef) {
                throw new PaymentConflict('Central payment reference changed for the same immutable attempt.');
            }
            $ref = $newRef ?? $oldRef;
            if ($next === $before && $ref === $oldRef) {
                return $this->storage->snapshot($row);
            }
            $updated = $db->table('intl_payment_intents')->where('id', $paymentId)
                ->where('revision', $row->revision)->where('status', $row->status)
                ->update(['status' => $next->value, 'central_reference' => $ref, 'central_payment_id' => $ref,
                    'revision' => $row->revision + 1, 'updated_at' => now()]);
            if ($updated !== 1) { throw new PaymentConflict('Concurrent central result update detected.'); }
            $db->table('intl_payment_attempts')->where('payment_id', $paymentId)->where('attempt', $attempt)
                ->update(['status' => $next->value, 'central_reference' => $ref, 'central_payment_id' => $ref, 'updated_at' => now()]);
            $this->event($paymentId, $attempt, $event, $before, $next, $ref);
            return $this->storage->snapshot($db->table('intl_payment_intents')->where('id', $paymentId)->first());
        }, 1);
    }

    private function findAuthorized(string $paymentId, string $ability): object
    {
        self::identifier($paymentId, 26, 'Payment id');
        $row = $this->storage->connection->table('intl_payment_intents')->where('id', $paymentId)->first();
        if ($row === null) { throw new PaymentNotFound('Payment not found.'); }
        $actor = $this->authorize($ability, (string) $row->resource_type, (string) $row->resource_id);
        if ($actor->role === config('intl-billing.initiator_role', 'agency')) {
            $this->assertOwnedByAgency($row, $this->requireAgency($actor));
        }
        return $row;
    }

    private function authorize(string $ability, string $resourceType, string $resourceId): ActorContext
    {
        try { $actor = $this->principals->current()->actor; }
        catch (\Throwable) { throw new AuthorizationDenied('not_authenticated'); }
        $this->authorization->authorize($actor, new AccessRequest($ability, $resourceType, $resourceId));
        return $actor;
    }

    private function requireAgency(ActorContext $actor): string
    {
        $role = config('intl-billing.initiator_role', 'agency');
        if (!is_string($role) || $role === '' || $actor->role !== $role || $actor->organizationId === null) {
            throw new AuthorizationDenied('agency_required');
        }
        self::identifier($actor->organizationId, 191, 'Agency');
        return $actor->organizationId;
    }

    private function assertOwnedByAgency(object $row, string $agency): void
    {
        if ((string) $row->agency_id !== $agency) {
            throw new AuthorizationDenied('agency_scope_denied');
        }
    }

    private function assertStillPayable(object $row): void
    {
        try {
            $this->quotes->quote(
                (string) $row->service_code,
                (string) $row->resource_type,
                (string) $row->resource_id,
                (string) $row->agency_id
            );
        } catch (PaymentConflict $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentConflict('Business request is no longer payable: ' . $e->getMessage(), 0, $e);
        }
    }

    private function assertReadyNotExpired(object $row, ?object $attempt = null): void
    {
        if (isset($row->expires_at) && $row->expires_at !== null && now()->greaterThan($row->expires_at)) {
            throw new PaymentConflict('Payment window has expired; request a new price snapshot.');
        }
        $maxAge = (int) config('intl-billing.max_quote_age_seconds', 3600);
        if ($maxAge > 0) {
            $createdAtStr = $attempt->created_at ?? $row->created_at ?? null;
            if ($createdAtStr !== null) {
                $createdAt = \Illuminate\Support\Carbon::parse((string) $createdAtStr);
                if ($createdAt->addSeconds($maxAge)->isPast()) {
                    throw new PaymentConflict('Expired prices must not be charged.');
                }
            }
        }
    }

    private function assertSameRequest(object $row, string $service, string $type, string $resource, string $agency, string $key): void
    {
        if ($row->service_code !== $service || $row->resource_type !== $type || $row->resource_id !== $resource ||
            $row->agency_id !== $agency || $row->local_idempotency_key !== $key) {
            throw new PaymentConflict('Local idempotency key was reused with different request details.');
        }
    }

    private function namespace(): string
    {
        $namespace = config('intl-billing.client_namespace', '');
        if (!is_string($namespace) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $namespace)) {
            throw new \RuntimeException('INTL payment client namespace must be stable and unique per deployment.');
        }
        return $namespace;
    }

    private static function identifier(string $value, int $limit, string $label): void
    {
        if (trim($value) !== $value || $value === '' || strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException("Invalid {$label}.");
        }
    }

    private static function command(object $row): PaymentCommand
    {
        $key = $row->client_namespace . ':' . $row->id . ':' . $row->attempt;
        return new PaymentCommand(
            $key, (string) $row->id, (string) $row->service_code,
            (string) $row->resource_type, (string) $row->resource_id,
            (string) $row->agency_id, new Money((int) $row->amount, (string) $row->currency)
        );
    }

    private function maxAttempts(): int
    {
        $max = config('intl-billing.max_attempts', 3);
        if (!is_int($max) || $max < 1 || $max > 50) {
            throw new \RuntimeException('Invalid max_attempts configuration.');
        }
        return $max;
    }

    private function event(string $paymentId, int $attempt, string $event, ?PaymentStatus $before, PaymentStatus $after, ?string $reference = null): void
    {
        $this->storage->connection->table('intl_payment_events')->insert([
            'payment_id' => $paymentId,
            'attempt' => $attempt,
            'event' => $event,
            'from_status' => $before?->value,
            'to_status' => $after->value,
            'central_reference' => $reference,
            'created_at' => now(),
        ]);
    }
}
