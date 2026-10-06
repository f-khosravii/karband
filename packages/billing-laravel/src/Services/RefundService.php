<?php

declare(strict_types=1);

namespace Intl\BillingLaravel\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\Billing\Refund\RefundCommand;
use Intl\Billing\Refund\RefundInitiation;
use Intl\Billing\Refund\RefundOutcome;
use Intl\Billing\Refund\RefundPolicy;
use Intl\Billing\Refund\RefundStateMachine;
use Intl\Billing\Refund\RefundStatus;
use Intl\Billing\ValueObjects\Money;
use Intl\Billing\ValueObjects\PaymentStatus;
use Intl\BillingLaravel\Contracts\CentralRefundGateway;
use Intl\BillingLaravel\Contracts\TrustedRefundEventVerifier;
use Intl\BillingLaravel\Exceptions\RefundConflict;
use Intl\BillingLaravel\Exceptions\RefundNotFound;
use Intl\BillingLaravel\ValueObjects\RefundSnapshot;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;

/**
 * FULL refund proposal and lifecycle only. Manual cases require an external
 * trusted reconciliation integration; NO method fabricates manual success.
 * Calls to the central system are ALWAYS outside local DB transactions.
 */
final readonly class RefundService
{
    public function __construct(
        private PaymentStorage $storage,
        private PrincipalProvider $principals,
        private AuthorizerInterface $authorization,
        private TrustedRefundEventVerifier $events,
        private Container $container,
        private RefundStateMachine $machine = new RefundStateMachine(),
    ) {}

    /**
     * Must be invoked from trusted host orchestration on an ACTUAL business event.
     * No HTTP routes are shipped; NEVER accept arbitrary event codes from the browser.
     * At most one proposal per confirmed payment in this conservative first release.
     */
    public function propose(string $paymentId, string $eventCode, string $eventId): RefundSnapshot
    {
        self::identifier($paymentId, 26, 'Payment id');
        self::identifier($eventCode, 80, 'Refund event');
        self::identifier($eventId, 191, 'Trusted event id');
        $db = $this->storage->connection;
        $payment = $db->table('intl_payment_intents')->where('id', $paymentId)->first();
        if ($payment === null) { throw new RefundNotFound('Payment not found.'); }
        $actor = $this->authorize('billing.refund.propose', $payment);
        $this->assertConfirmedPayment($payment);
        $attempt = $db->table('intl_payment_attempts')->where('payment_id', $paymentId)
            ->where('attempt', $payment->attempt)->first();
        if ($attempt === null || !$attempt->policy_verified || $attempt->status !== PaymentStatus::Succeeded->value) {
            throw new RefundConflict('Confirmed payment has no verified frozen refund policy.');
        }
        $policy = RefundPolicy::fromArray(json_decode((string) $attempt->refund_policy_json, true, 512, JSON_THROW_ON_ERROR));
        $mode = $policy->modeFor($eventCode);
        if ($mode === null) { throw new RefundConflict('Frozen service policy does not permit refund for this event.'); }
        $this->assertEventVerified($eventCode, $eventId, $payment);
        try {
            return $db->transaction(function () use ($db, $paymentId, $eventCode, $eventId, $actor, $mode): RefundSnapshot {
                $payment = $db->table('intl_payment_intents')->where('id', $paymentId)->lockForUpdate()->first();
                if ($payment === null) { throw new RefundNotFound('Payment not found.'); }
                $this->assertConfirmedPayment($payment);
                $existing = $db->table('intl_full_refunds')->where('payment_id', $paymentId)->first();
                if ($existing !== null) {
                    if ($existing->event_code === $eventCode && $existing->event_id === $eventId) {
                        return self::snapshot($existing); // trusted idempotent replay
                    }
                    throw new RefundConflict('This payment already has a refund proposal.');
                }
                $now = now();
                $status = match ($mode) {
                    RefundInitiation::Automatic => RefundStatus::Ready,
                    RefundInitiation::AdminApproval => RefundStatus::AwaitingApproval,
                    RefundInitiation::Manual => RefundStatus::ManualPending,
                };
                $id = (string) Str::ulid();
                $db->table('intl_full_refunds')->insert([
                    'id' => $id, 'payment_id' => $paymentId, 'service_code' => $payment->service_code,
                    'resource_type' => $payment->resource_type, 'resource_id' => $payment->resource_id,
                    'agency_id' => $payment->agency_id, 'client_namespace' => $payment->client_namespace,
                    'event_code' => $eventCode, 'event_id' => $eventId, 'mode' => $mode->value,
                    'status' => $status->value, 'amount' => $payment->amount, 'currency' => $payment->currency,
                    'original_payment_reference' => $payment->central_reference,
                    'requested_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->event($id, 'proposed', null, $status, $actor->id);
                return self::snapshot($db->table('intl_full_refunds')->where('id', $id)->first());
            }, 1);
        } catch (UniqueConstraintViolationException $exception) {
            // A race must not allow a second proposal, irrespective of event id.
            throw new RefundConflict('Concurrent refund proposal detected; read existing proposal.', previous: $exception);
        }
    }

    public function approve(string $refundId): RefundSnapshot
    {
        $row = $this->findAuthorized($refundId, 'billing.refund.approve');
        $actor = $this->currentActor();
        $this->requireGovernmentApprover($actor);
        if ($row->mode !== RefundInitiation::AdminApproval->value) {
            throw new RefundConflict('This refund does not use government approval.');
        }
        $this->assertEventVerifiedForRefund($row);
        return $this->storage->connection->transaction(function () use ($refundId, $actor): RefundSnapshot {
            $db = $this->storage->connection;
            $row = $db->table('intl_full_refunds')->where('id', $refundId)->lockForUpdate()->first();
            if ($row === null) { throw new RefundNotFound('Refund not found.'); }
            if ($row->status !== RefundStatus::AwaitingApproval->value) {
                throw new RefundConflict('Refund has already been reviewed.');
            }
            $changed = $db->table('intl_full_refunds')->where('id', $refundId)
                ->where('status', RefundStatus::AwaitingApproval->value)->where('revision', $row->revision)
                ->update(['status' => RefundStatus::Ready->value, 'approved_by' => $actor->id,
                    'revision' => $row->revision + 1, 'updated_at' => now()]);
            if ($changed !== 1) { throw new RefundConflict('Concurrent refund approval.'); }
            $this->event($refundId, 'approved', RefundStatus::AwaitingApproval, RefundStatus::Ready, $actor->id);
            return self::snapshot($db->table('intl_full_refunds')->where('id', $refundId)->first());
        }, 1);
    }

    public function reject(string $refundId): RefundSnapshot
    {
        $row = $this->findAuthorized($refundId, 'billing.refund.approve');
        $actor = $this->currentActor();
        $this->requireGovernmentApprover($actor);
        if ($row->mode !== RefundInitiation::AdminApproval->value) {
            throw new RefundConflict('This refund does not use government approval.');
        }
        return $this->storage->connection->transaction(function () use ($refundId, $actor): RefundSnapshot {
            $db = $this->storage->connection;
            $row = $db->table('intl_full_refunds')->where('id', $refundId)->lockForUpdate()->first();
            if ($row === null) { throw new RefundNotFound('Refund not found.'); }
            if ($row->status !== RefundStatus::AwaitingApproval->value) {
                throw new RefundConflict('Refund has already been reviewed.');
            }
            $changed = $db->table('intl_full_refunds')->where('id', $refundId)
                ->where('status', RefundStatus::AwaitingApproval->value)->where('revision', $row->revision)
                ->update(['status' => RefundStatus::Rejected->value, 'approved_by' => $actor->id,
                    'revision' => $row->revision + 1, 'updated_at' => now()]);
            if ($changed !== 1) { throw new RefundConflict('Concurrent refund rejection.'); }
            $this->event($refundId, 'rejected', RefundStatus::AwaitingApproval, RefundStatus::Rejected, $actor->id);
            return self::snapshot($db->table('intl_full_refunds')->where('id', $refundId)->first());
        }, 1);
    }

    /** Once a dispatch is claimed, even a process crash permits LOOKUP ONLY. */
    public function dispatch(string $refundId): RefundSnapshot
    {
        $row = $this->findAuthorized($refundId, 'billing.refund.execute');
        if ($row->mode === RefundInitiation::Manual->value) {
            throw new RefundConflict('Manual refund cannot invoke the automatic central refund API.');
        }
        $this->assertEventVerifiedForRefund($row);
        // Fail BEFORE claiming if a gateway is not explicitly wired by the host.
        $gateway = $this->container->make(CentralRefundGateway::class);
        $claimed = $this->storage->connection->transaction(function () use ($refundId): object {
            $db = $this->storage->connection;
            $row = $db->table('intl_full_refunds')->where('id', $refundId)->lockForUpdate()->first();
            if ($row === null) { throw new RefundNotFound('Refund not found.'); }
            if ($row->status !== RefundStatus::Ready->value) {
                throw new RefundConflict('Refund is not ready; uncertain/claimed refunds MUST use lookup.');
            }
            $payment = $db->table('intl_payment_intents')->where('id', $row->payment_id)->first();
            if ($payment === null || $payment->status !== PaymentStatus::Succeeded->value ||
                $payment->central_reference !== $row->original_payment_reference ||
                (int) $payment->amount !== (int) $row->amount || (string) $payment->currency !== (string) $row->currency) {
                throw new RefundConflict('Original confirmed payment snapshot does not match refund.');
            }
            $changed = $db->table('intl_full_refunds')->where('id', $refundId)
                ->where('status', RefundStatus::Ready->value)->where('revision', $row->revision)
                ->update(['status' => RefundStatus::Processing->value, 'dispatched_at' => now(),
                    'revision' => $row->revision + 1, 'updated_at' => now()]);
            if ($changed !== 1) { throw new RefundConflict('Concurrent refund dispatch claim.'); }
            $this->event($refundId, 'dispatch_claimed', RefundStatus::Ready, RefundStatus::Processing);
            return $db->table('intl_full_refunds')->where('id', $refundId)->first();
        }, 1);
        try {
            $outcome = $gateway->createRefund(self::command($claimed));
        } catch (\Throwable $e) {
            if (!method_exists($gateway, 'createRefund')) {
                try { $outcome = $gateway->refund(self::command($claimed)); }
                catch (\Throwable) { $outcome = RefundOutcome::unknown(); }
            } else {
                $outcome = RefundOutcome::unknown();
            }
        }
        return $this->recordResult($refundId, $outcome, 'dispatch_result');
    }

    /** LOOKUP with the same immutable key; never sends a new refund. */
    public function reconcile(string $refundId): RefundSnapshot
    {
        $row = $this->findAuthorized($refundId, 'billing.refund.reconcile');
        $before = RefundStatus::from((string) $row->status);
        if ($before->isFinal()) { return self::snapshot($row); }
        if (!in_array($before, [RefundStatus::Processing, RefundStatus::Pending, RefundStatus::Unknown], true)) {
            throw new RefundConflict('No automatic refund has been dispatched for lookup.');
        }
        if ($before === RefundStatus::Processing) {
            $delay = config('intl-billing.reconcile_after_seconds', 60);
            if (!is_int($delay) || $delay < 0 || $delay > 86400) {
                throw new \RuntimeException('Invalid refund reconciliation delay.');
            }
            $elapsed = $row->dispatched_at === null ? 0 : max(0, now()->getTimestamp() - Carbon::parse($row->dispatched_at)->getTimestamp());
            if ($elapsed < $delay) { throw new RefundConflict('Refund dispatch is too recent to reconcile.'); }
        }
        $gateway = $this->container->make(CentralRefundGateway::class);
        $opKey = isset($row->operation_key) && $row->operation_key !== null ? (string) $row->operation_key : self::command($row)->requestKey;
        try {
            $outcome = $gateway->lookup(self::command($row));
            if ($outcome->status === RefundStatus::Unknown && $row->central_refund_reference !== null) {
                $outcome = $gateway->lookupRefund((string) $row->central_refund_reference);
            }
            if ($outcome->status === RefundStatus::Unknown && isset($row->operation_key) && $row->operation_key !== null) {
                $outcome = $gateway->lookupRefundByOperationKey((string) $row->operation_key);
            }
        } catch (\Throwable) {
            $outcome = RefundOutcome::unknown();
        }
        return $this->recordResult($refundId, $outcome, 'reconciled');
    }

    public function view(string $refundId): RefundSnapshot
    {
        return self::snapshot($this->findAuthorized($refundId, 'billing.refund.view'));
    }

    public function history(string $refundId): array
    {
        $this->findAuthorized($refundId, 'billing.refund.view');
        return $this->storage->connection->table('intl_refund_events')->where('refund_id', $refundId)
            ->orderBy('id')->get()->all();
    }

    private function recordResult(string $refundId, RefundOutcome $outcome, string $event): RefundSnapshot
    {
        return $this->storage->connection->transaction(function () use ($refundId, $outcome, $event): RefundSnapshot {
            $db = $this->storage->connection;
            $row = $db->table('intl_full_refunds')->where('id', $refundId)->lockForUpdate()->first();
            if ($row === null) { throw new RefundNotFound('Refund not found.'); }
            $before = RefundStatus::from((string) $row->status);
            $next = $this->machine->afterGatewayResult($before, $outcome);
            if ($before->isFinal()) { return self::snapshot($row); }
            $old = $row->central_refund_reference;
            if ($old !== null && $outcome->providerReference !== null && $old !== $outcome->providerReference) {
                throw new RefundConflict('Central refund reference changed for the same immutable request key.');
            }
            $reference = $outcome->providerReference ?? $old;
            if ($before === $next && $reference === $old) { return self::snapshot($row); }
            $changed = $db->table('intl_full_refunds')->where('id', $refundId)
                ->where('status', $before->value)->where('revision', $row->revision)
                ->update(['status' => $next->value, 'central_refund_reference' => $reference,
                    'revision' => $row->revision + 1, 'updated_at' => now()]);
            if ($changed !== 1) { throw new RefundConflict('Concurrent refund reconciliation.'); }
            $this->event($refundId, $event, $before, $next, reference: $reference);
            return self::snapshot($db->table('intl_full_refunds')->where('id', $refundId)->first());
        }, 1);
    }

    private function findAuthorized(string $refundId, string $ability): object
    {
        self::identifier($refundId, 26, 'Refund id');
        $row = $this->storage->connection->table('intl_full_refunds')->where('id', $refundId)->first();
        if ($row === null) { throw new RefundNotFound('Refund not found.'); }
        $this->authorize($ability, $row);
        return $row;
    }

    private function authorize(string $ability, object $row): ActorContext
    {
        $actor = $this->currentActor();
        $this->authorization->authorize($actor, new AccessRequest($ability, (string) $row->resource_type, (string) $row->resource_id));
        if ($actor->role === config('intl-billing.initiator_role', 'agency') && $actor->organizationId !== $row->agency_id) {
            throw new AuthorizationDenied('agency_scope_denied');
        }
        return $actor;
    }

    private function currentActor(): ActorContext
    {
        try { return $this->principals->current()->actor; }
        catch (\Throwable) { throw new AuthorizationDenied('not_authenticated'); }
    }

    private function requireGovernmentApprover(ActorContext $actor): void
    {
        $role = config('intl-billing.refund_approver_role', 'government_admin');
        if (!is_string($role) || $role === '' || $actor->role !== $role) {
            throw new AuthorizationDenied('government_refund_approval_required');
        }
    }

    private function assertConfirmedPayment(object $payment): void
    {
        if ($payment->status !== PaymentStatus::Succeeded->value || $payment->central_reference === null) {
            throw new RefundConflict('Only centrally confirmed successful payments can be refunded.');
        }
    }

    private function assertEventVerifiedForRefund(object $refund): void
    {
        $this->assertEventVerified((string) $refund->event_code, (string) $refund->event_id, $refund);
    }

    private function assertEventVerified(string $eventCode, string $eventId, object $resource): void
    {
        try {
            $valid = $this->events->verify($eventCode, $eventId, (string) $resource->service_code,
                (string) $resource->resource_type, (string) $resource->resource_id, (string) $resource->agency_id);
        } catch (\Throwable) {
            throw new RefundConflict('Unable to verify the trusted refund event.');
        }
        if ($valid !== true) { throw new RefundConflict('Refund event not verified by the host.'); }
    }

    private static function command(object $refund): RefundCommand
    {
        return new RefundCommand(
            $refund->client_namespace . ':refund:' . $refund->id . ':1', (string) $refund->id,
            (string) $refund->payment_id, (string) $refund->original_payment_reference,
            new Money((int) $refund->amount, (string) $refund->currency)
        );
    }

    private static function snapshot(object $refund): RefundSnapshot
    {
        return new RefundSnapshot(
            (string) $refund->id, (string) $refund->payment_id, (string) $refund->event_code,
            RefundInitiation::from((string) $refund->mode), RefundStatus::from((string) $refund->status),
            new Money((int) $refund->amount, (string) $refund->currency),
            $refund->central_refund_reference === null ? null : (string) $refund->central_refund_reference,
        );
    }

    private function event(string $refundId, string $event, ?RefundStatus $before, RefundStatus $after,
        ?string $actor = null, ?string $reference = null): void
    {
        $this->storage->connection->table('intl_refund_events')->insert([
            'refund_id' => $refundId, 'event' => $event, 'from_status' => $before?->value,
            'to_status' => $after->value, 'actor_id' => $actor,
            'central_reference' => $reference, 'created_at' => now(),
        ]);
    }

    private static function identifier(string $value, int $limit, string $label): void
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException("Invalid {$label}.");
        }
    }
}
