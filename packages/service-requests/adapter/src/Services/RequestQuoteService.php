<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\Services;

use Illuminate\Support\Str;
use Karband\Audit\AuditEvent;
use Karband\Audit\AuditRecorderInterface;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Authorization\AuthorizerInterface;
use Karband\ServiceRequests\ValueObjects\QuoteStatus;
use Karband\ServiceRequests\ValueObjects\RequestQuoteProposal;
use Karband\ServiceRequests\ValueObjects\RequestResource;
use Karband\ServiceRequestsLaravel\Contracts\RequestCatalogPort;
use Karband\ServiceRequestsLaravel\Exceptions\QuoteConflict;
use Karband\ServiceRequestsLaravel\Exceptions\QuoteNotFound;

final readonly class RequestQuoteService
{
    public function __construct(
        private RequestStorage $storage,
        private RequestCatalogPort $catalog,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $authorization,
        private ?AuditRecorderInterface $audit = null,
    ) {}

    public function propose(string $requestId, int $amount, string $currency, ?string $notes = null): RequestQuoteProposal
    {
        $actor = $this->actor();
        $this->authorization->authorize($actor, new AccessRequest('quote.propose', RequestResource::TYPE, $requestId));

        $db = $this->storage->connection;
        return $db->transaction(function () use ($db, $requestId, $amount, $currency, $notes, $actor): RequestQuoteProposal {
            $request = $db->table('service_requests')->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new QuoteNotFound('Request not found.');
            }

            $this->catalog->assertQuotePricingSupported((string) $request->service_code);

            $quote = $db->table('request_quotes')->where('request_id', $requestId)->lockForUpdate()->first();
            if ($quote !== null && $quote->accepted_revision !== null) {
                throw new QuoteConflict('Cannot propose a new quote for a request with an already accepted quote.');
            }

            $db->table('request_quote_proposals')
                ->where('request_id', $requestId)
                ->where('status', QuoteStatus::Proposed->value)
                ->update([
                    'status' => QuoteStatus::Superseded->value,
                    'updated_at' => now(),
                ]);

            $nextRevision = $quote ? ((int) $quote->current_revision + 1) : 1;
            $proposalId = (string) Str::ulid();
            $now = now();

            try {
                $db->table('request_quote_proposals')->insert([
                    'id' => $proposalId,
                    'request_id' => $requestId,
                    'revision' => $nextRevision,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => QuoteStatus::Proposed->value,
                    'proposed_by' => $actor->id,
                    'proposed_at' => $now,
                    'notes' => $notes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                throw new QuoteConflict('Concurrent quote proposal conflict.', 0, $e);
            }

            if ($quote === null) {
                $db->table('request_quotes')->insert([
                    'request_id' => $requestId,
                    'current_revision' => $nextRevision,
                    'accepted_revision' => null,
                    'status' => QuoteStatus::Proposed->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $db->table('request_quotes')->where('request_id', $requestId)->update([
                    'current_revision' => $nextRevision,
                    'status' => QuoteStatus::Proposed->value,
                    'updated_at' => now(),
                ]);
            }

            $proposal = new RequestQuoteProposal(
                id: $proposalId,
                requestId: $requestId,
                revision: $nextRevision,
                amount: $amount,
                currency: $currency,
                status: QuoteStatus::Proposed,
                proposedBy: $actor->id,
                proposedAt: \DateTimeImmutable::createFromInterface($now),
                notes: $notes,
            );

            $this->audit?->record(new AuditEvent(
                'request.quote.proposed',
                RequestResource::TYPE,
                $requestId,
                ['revision' => $nextRevision]
            ));

            return $proposal;
        });
    }

    public function accept(string $requestId, int $expectedRevision): RequestQuoteProposal
    {
        $actor = $this->actor();
        $this->authorization->authorize($actor, new AccessRequest('quote.accept', RequestResource::TYPE, $requestId));

        $db = $this->storage->connection;
        return $db->transaction(function () use ($db, $requestId, $expectedRevision, $actor): RequestQuoteProposal {
            $request = $db->table('service_requests')->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new QuoteNotFound('Request not found.');
            }

            $this->assertOwnedByAgency($request, $actor);

            $proposalRow = $db->table('request_quote_proposals')
                ->where('request_id', $requestId)
                ->where('revision', $expectedRevision)
                ->lockForUpdate()
                ->first();

            if ($proposalRow === null) {
                throw new QuoteConflict('Quote revision mismatch or quote not found.');
            }
            if ($proposalRow->status !== QuoteStatus::Proposed->value) {
                throw new QuoteConflict('Quote revision is not in proposed state.');
            }

            $now = now();
            $db->table('request_quote_proposals')
                ->where('request_id', $requestId)
                ->where('revision', $expectedRevision)
                ->update([
                    'status' => QuoteStatus::Accepted->value,
                    'accepted_at' => $now,
                    'accepted_by' => $actor->id,
                    'updated_at' => $now,
                ]);

            $db->table('request_quotes')->where('request_id', $requestId)->update([
                'accepted_revision' => $expectedRevision,
                'status' => QuoteStatus::Accepted->value,
                'updated_at' => $now,
            ]);

            $updatedRow = $db->table('request_quote_proposals')
                ->where('request_id', $requestId)
                ->where('revision', $expectedRevision)
                ->first();

            $proposal = self::rowToProposal($updatedRow);

            $this->audit?->record(new AuditEvent(
                'request.quote.accepted',
                RequestResource::TYPE,
                $requestId,
                ['revision' => $expectedRevision]
            ));

            return $proposal;
        });
    }

    public function reject(string $requestId, int $expectedRevision, ?string $reason = null): RequestQuoteProposal
    {
        $actor = $this->actor();
        $this->authorization->authorize($actor, new AccessRequest('quote.reject', RequestResource::TYPE, $requestId));

        $db = $this->storage->connection;
        return $db->transaction(function () use ($db, $requestId, $expectedRevision, $reason, $actor): RequestQuoteProposal {
            $request = $db->table('service_requests')->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new QuoteNotFound('Request not found.');
            }

            $this->assertOwnedByAgency($request, $actor);

            $proposalRow = $db->table('request_quote_proposals')
                ->where('request_id', $requestId)
                ->where('revision', $expectedRevision)
                ->lockForUpdate()
                ->first();

            if ($proposalRow === null) {
                throw new QuoteConflict('Quote revision mismatch or quote not found.');
            }
            if ($proposalRow->status !== QuoteStatus::Proposed->value) {
                throw new QuoteConflict('Quote revision is not in proposed state.');
            }

            $now = now();
            $db->table('request_quote_proposals')
                ->where('request_id', $requestId)
                ->where('revision', $expectedRevision)
                ->update([
                    'status' => QuoteStatus::Rejected->value,
                    'rejected_at' => $now,
                    'rejected_by' => $actor->id,
                    'rejection_reason' => $reason,
                    'updated_at' => $now,
                ]);

            $db->table('request_quotes')->where('request_id', $requestId)->update([
                'status' => QuoteStatus::Rejected->value,
                'updated_at' => $now,
            ]);

            $updatedRow = $db->table('request_quote_proposals')
                ->where('request_id', $requestId)
                ->where('revision', $expectedRevision)
                ->first();

            $proposal = self::rowToProposal($updatedRow);

            $this->audit?->record(new AuditEvent(
                'request.quote.rejected',
                RequestResource::TYPE,
                $requestId,
                ['revision' => $expectedRevision]
            ));

            return $proposal;
        });
    }

    public function activeQuote(string $requestId): ?RequestQuoteProposal
    {
        $actor = $this->actor();
        $this->authorization->authorize($actor, new AccessRequest('quote.view', RequestResource::TYPE, $requestId));
        $this->assertRequestAccess($requestId, $actor);

        $row = $this->storage->connection->table('request_quote_proposals')
            ->where('request_id', $requestId)
            ->whereIn('status', [QuoteStatus::Proposed->value, QuoteStatus::Accepted->value])
            ->orderBy('revision', 'desc')
            ->first();

        return $row ? self::rowToProposal($row) : null;
    }

    public function acceptedQuote(string $requestId): ?RequestQuoteProposal
    {
        $actor = $this->actor();
        $this->authorization->authorize($actor, new AccessRequest('quote.view', RequestResource::TYPE, $requestId));
        $this->assertRequestAccess($requestId, $actor);

        $row = $this->storage->connection->table('request_quote_proposals')
            ->where('request_id', $requestId)
            ->where('status', QuoteStatus::Accepted->value)
            ->first();

        return $row ? self::rowToProposal($row) : null;
    }

    public function history(string $requestId): array
    {
        $actor = $this->actor();
        $this->authorization->authorize($actor, new AccessRequest('quote.view', RequestResource::TYPE, $requestId));
        $this->assertRequestAccess($requestId, $actor);

        $rows = $this->storage->connection->table('request_quote_proposals')
            ->where('request_id', $requestId)
            ->orderBy('revision', 'asc')
            ->get();

        return array_map(fn ($r) => self::rowToProposal($r), $rows->all());
    }

    private function actor(): ActorContext
    {
        try {
            return $this->actors->currentActor();
        } catch (\Throwable) {
            throw new AuthorizationDenied('not_authenticated');
        }
    }

    private function assertOwnedByAgency(object $request, ActorContext $actor): void
    {
        if ($actor->role === 'agency' && (string) $request->agency_id !== (string) $actor->organizationId) {
            throw new AuthorizationDenied('agency_scope_denied');
        }
    }

    private function assertRequestAccess(string $requestId, ActorContext $actor): void
    {
        $request = $this->storage->connection->table('service_requests')->where('id', $requestId)->first();
        if ($request === null) {
            throw new QuoteNotFound('Request not found.');
        }
        $this->assertOwnedByAgency($request, $actor);
    }

    private static function rowToProposal(object $row): RequestQuoteProposal
    {
        return new RequestQuoteProposal(
            id: (string) $row->id,
            requestId: (string) $row->request_id,
            revision: (int) $row->revision,
            amount: (int) $row->amount,
            currency: (string) $row->currency,
            status: QuoteStatus::from((string) $row->status),
            proposedBy: (string) $row->proposed_by,
            proposedAt: new \DateTimeImmutable((string) $row->proposed_at),
            acceptedAt: $row->accepted_at ? new \DateTimeImmutable((string) $row->accepted_at) : null,
            acceptedBy: $row->accepted_by ? (string) $row->accepted_by : null,
            rejectedAt: $row->rejected_at ? new \DateTimeImmutable((string) $row->rejected_at) : null,
            rejectedBy: $row->rejected_by ? (string) $row->rejected_by : null,
            rejectionReason: $row->rejection_reason ? (string) $row->rejection_reason : null,
            notes: $row->notes ? (string) $row->notes : null,
        );
    }
}
