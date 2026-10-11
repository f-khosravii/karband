<?php

declare(strict_types=1);
namespace Karband\ServiceRecipientsLaravel\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Karband\Authorization\Contracts\ActorProviderInterface;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\Authorization\AuthorizerInterface;
use Karband\ServiceRecipients\Contracts\RecipientRules;
use Karband\ServiceRecipients\Exceptions\DuplicateRecipient;
use Karband\ServiceRecipients\Exceptions\RecipientConflict;
use Karband\ServiceRecipients\Identity;
use Karband\ServiceRecipients\RecipientInput;
use Karband\ServiceRecipients\RecipientKind;
use Karband\ServiceRecipients\RecipientSnapshot;
use Karband\ServiceRecipientsLaravel\Contracts\RecipientAudit;
use Karband\ServiceRecipientsLaravel\Contracts\RecipientRequestBridge;

/**
 * Agency-scoped recipient profiles. MUST be wired to real host policy, audit, and request bridge.
 * All hooks execute inside same LOCAL DB transaction. No central service or client-supplied agency.
 * Request creation must use the shared recipient row-lock gate added in Phase 15.
 */
final readonly class Recipients
{
    public function __construct(
        private RecipientStorage $storage,
        private RecipientRules $rules,
        private ActorProviderInterface $actors,
        private AuthorizerInterface $permissions,
        private RecipientRequestBridge $requests,
        private RecipientAudit $audit,
    ) {}

    private function db(): \Illuminate\Database\Connection
    {
        $name = $this->storage->connection->getName();
        if ($this->requests->connectionName() !== $name || $this->audit->connectionName() !== $name) {
            throw new \RuntimeException('Recipients, draft bridge and audit must share one local DB connection.');
        }
        return $this->storage->connection;
    }

    private function actor(): ActorContext
    {
        return $this->actors->currentActor();
    }
    private function agencyActor(string $ability, string $resourceType, string $resourceId): array
    {
        $actor = $this->actor();
        if ($actor->organizationId === null || trim($actor->organizationId) === '') {
            throw new AuthorizationDenied('agency_required');
        }
        $this->permissions->authorize($actor, new AccessRequest($ability, $resourceType, $resourceId));
        return [$actor, $actor->organizationId];
    }
    private function platformActor(string $ability, string $recipientId): ActorContext
    {
        $actor = $this->actor();
        $this->permissions->authorize($actor, new AccessRequest($ability, 'recipient', $recipientId));
        return $actor;
    }
    private function owned(string $id, string $agencyId, bool $lock = false): object
    {
        $q = $this->storage->connection->table('recipients')->where('id', $id)->where('agency_id', $agencyId);
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($row === null) { throw new AuthorizationDenied('recipient_not_available'); }
        return $row;
    }
    private function existingInAgency(string $agency, string $digest): ?object
    {
        return $this->storage->connection->table('recipients')
            ->where('agency_id', $agency)->where('identity_lookup', $digest)->first();
    }
    private function duplicateOrUnavailable(?object $existing): never
    {
        if ($existing !== null && $existing->status === 'active') {
            throw new DuplicateRecipient((string)$existing->id);
        }
        throw new RecipientConflict('An existing archived or concurrent profile prevents registration.');
    }
    private function normalized(RecipientKind $kind, string $type, string $value): Identity
    {
        $this->rules->assertAllowedKind($kind);
        return $this->rules->normalize($kind, $type, $value);
    }

    public function create(RecipientInput $input): RecipientSnapshot
    {
        [$actor, $agency] = $this->agencyActor('recipients.create', 'agency', $this->actor()->organizationId ?? 'missing-agency');
        $db = $this->db();
        $this->rules->validateProfile($input->kind, $input->profile);
        $identity = $this->normalized($input->kind, $input->identityType, $input->identityValue);
        $digest = $this->storage->hmac($agency, $input->kind, $identity);
        try {
            return $db->transaction(function () use ($actor, $agency, $input, $identity, $digest): RecipientSnapshot {
                $existing = $this->existingInAgency($agency, $digest);
                if ($existing !== null) { $this->duplicateOrUnavailable($existing); }
                $id = (string) Str::ulid();
                $this->storage->connection->table('recipients')->insert([
                    'id'=>$id, 'agency_id'=>$agency, 'kind'=>$input->kind->value,
                    'identity_type'=>$identity->type, 'identity_lookup'=>$digest,
                    'identity_cipher'=>$this->storage->encryptIdentity($identity),
                    'profile_cipher'=>$this->storage->encrypt($input->profile),
                    'status'=>'active', 'revision'=>1, 'created_by'=>$actor->id,
                    'created_at'=>now(), 'updated_at'=>now(),
                ]);
                $this->audit->record('recipients.created', $id, ['revision'=>1]);
                return new RecipientSnapshot($id, $agency, $input->kind, $identity, $input->profile, 'active', 1);
            }, 1);
        } catch (UniqueConstraintViolationException $e) {
            $this->duplicateOrUnavailable($this->existingInAgency($agency, $digest));
        }
    }

    /** Only the owning agency can read the complete decrypted profile. */
    public function get(string $id): RecipientSnapshot
    {
        [, $agency] = $this->agencyActor('recipients.view', 'recipient', $id);
        return $this->storage->snapshot($this->owned($id, $agency));
    }

    /** Cursor-paginated active profiles; NEVER queries another agency or returns archived profiles.
     * @return array<int,RecipientSnapshot>
     */
    public function listActive(int $limit = 25, ?string $afterId = null): array
    {
        [, $agency] = $this->agencyActor('recipients.list', 'agency', $this->actor()->organizationId ?? 'missing-agency');
        if ($limit < 1 || $limit > 100 || ($afterId !== null && !preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $afterId))) {
            throw new \InvalidArgumentException('Invalid recipient pagination.');
        }
        $q = $this->storage->connection->table('recipients')
            ->where('agency_id',$agency)->where('status','active')->orderBy('id')->limit($limit);
        if ($afterId !== null) { $q->where('id','>',$afterId); }
        return $q->get()->map(fn (object $row): RecipientSnapshot => $this->storage->snapshot($row))->all();
    }

    /** @param array<string,mixed> $profile */
    public function updateProfile(string $id, array $profile, int $expectedRevision): RecipientSnapshot
    {
        [, $agency] = $this->agencyActor('recipients.update', 'recipient', $id);
        $db = $this->db();
        return $db->transaction(function () use ($id, $agency, $profile, $expectedRevision): RecipientSnapshot {
            $row = $this->owned($id, $agency, true);
            if ($row->status !== 'active' || (int)$row->revision !== $expectedRevision) {
                throw new RecipientConflict('Recipient is archived or profile revision is stale.');
            }
            $kind = RecipientKind::from((string)$row->kind);
            RecipientInput::assertJsonObject($profile);
            $this->rules->validateProfile($kind, $profile);
            $identity = $this->storage->decryptIdentity($row);
            // Host bridge must update ONLY editable, never-frozen, unsubmitted drafts, including their identity snapshot.
            $this->requests->syncEditableDrafts($agency, $id, $profile, $identity->type, $identity->normalized);
            $revision = $expectedRevision + 1;
            $db = $this->storage->connection;
            $db->table('recipients')->where('id', $id)->update([
                'profile_cipher'=>$this->storage->encrypt($profile), 'revision'=>$revision, 'updated_at'=>now(),
            ]);
            $this->audit->record('recipients.updated', $id, ['revision'=>$revision]);
            return new RecipientSnapshot($id, $agency, $kind, $identity, $profile, 'active', $revision);
        }, 1);
    }

    /** Agency may REQUEST but never directly modify the deduplication identity. */
    public function requestIdentityChange(string $id, string $type, string $value): string
    {
        [$actor, $agency] = $this->agencyActor('recipients.identity.request', 'recipient', $id);
        return $this->db()->transaction(function () use ($actor, $agency, $id, $type, $value): string {
            $row = $this->owned($id, $agency, true);
            if ($row->status !== 'active') { throw new RecipientConflict('Recipient is archived.'); }
            $kind = RecipientKind::from((string)$row->kind);
            $identity = $this->normalized($kind, $type, $value);
            $digest = $this->storage->hmac($agency, $kind, $identity);
            if ($digest === $row->identity_lookup) { throw new RecipientConflict('Identity is unchanged.'); }
            if ($this->existingInAgency($agency, $digest) !== null) {
                throw new RecipientConflict('Proposed identity is already in use in the current agency.');
            }
            if ($this->storage->connection->table('recipient_identity_changes')
                ->where('recipient_id', $id)->where('status', 'pending')->exists()) {
                throw new RecipientConflict('A pending identity change already exists.');
            }
            $changeId = (string) Str::ulid();
            $this->storage->connection->table('recipient_identity_changes')->insert([
                'id'=>$changeId, 'recipient_id'=>$id, 'proposed_type'=>$identity->type,
                'proposed_lookup'=>$digest, 'proposed_cipher'=>$this->storage->encryptIdentity($identity),
                'status'=>'pending','requested_by'=>$actor->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->audit->record('recipients.identity.requested', $id);
            return $changeId;
        }, 1);
    }

    public function decideIdentityChange(string $changeId, bool $approve): void
    {
        $db = $this->db();
        $db->transaction(function () use ($db, $changeId, $approve): void {
            $change = $db->table('recipient_identity_changes')->where('id', $changeId)->first();
            if ($change === null) { throw new RecipientConflict('Unknown identity change.'); }
            $actor = $this->platformActor('recipients.identity.decide', (string)$change->recipient_id);
            // Lock recipient first, then refresh the pending row; same lock order as requestIdentityChange.
            $row = $db->table('recipients')->where('id', $change->recipient_id)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'active') { throw new RecipientConflict('Recipient is not active.'); }
            $change = $db->table('recipient_identity_changes')->where('id', $changeId)->lockForUpdate()->first();
            if ($change === null || $change->status !== 'pending') { throw new RecipientConflict('Identity change was already decided.'); }
            if ($approve) {
                $other = $this->existingInAgency((string)$row->agency_id, (string)$change->proposed_lookup);
                if ($other !== null && $other->id !== $row->id) {
                    throw new RecipientConflict('Proposed identity is already in use in this agency.');
                }
                $kind = RecipientKind::from((string)$row->kind);
                $identity = $this->normalized($kind, (string)$change->proposed_type, Crypt::decryptString((string)$change->proposed_cipher));
                if ($this->storage->hmac((string)$row->agency_id, $kind, $identity) !== $change->proposed_lookup) {
                    throw new RecipientConflict('Host identity rules changed after request.');
                }
                $this->requests->syncEditableDrafts((string)$row->agency_id, (string)$row->id,
                    $this->storage->decrypt((string)$row->profile_cipher), $identity->type, $identity->normalized);
                // The unique DB constraint arbitrates two concurrent approvals with the same proposed identity.
                $db->table('recipients')->where('id',$row->id)->update([
                    'identity_type'=>$identity->type,'identity_lookup'=>$change->proposed_lookup,
                    'identity_cipher'=>$change->proposed_cipher,'revision'=>((int)$row->revision)+1,
                    'updated_at'=>now(),
                ]);
            }
            $db->table('recipient_identity_changes')->where('id', $changeId)->update([
                'status'=>$approve?'approved':'rejected', 'decided_by'=>$actor->id,
                'decided_at'=>now(), 'updated_at'=>now(),
            ]);
            $this->audit->record($approve?'recipients.identity.approved':'recipients.identity.rejected', (string)$row->id);
        }, 1);
    }

    public function archive(string $id): void
    {
        $actor = $this->platformActor('recipients.archive', $id);
        $db = $this->db();
        $db->transaction(function () use ($id, $actor, $db): void {
            $row = $db->table('recipients')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'active') { throw new RecipientConflict('Recipient is not active.'); }
            if ($db->table('recipient_identity_changes')->where('recipient_id',$id)->where('status','pending')->exists()) {
                throw new RecipientConflict('Pending identity change must be resolved first.');
            }
            if ($this->requests->hasOpenSubmittedRequests((string)$row->agency_id, $id)) {
                throw new RecipientConflict('Recipient has open submitted requests.');
            }
            // Host MUST mark all previous drafts permanently read-only inside this transaction.
            $this->requests->freezeDrafts((string)$row->agency_id, $id);
            $db->table('recipients')->where('id',$id)->update([
                'status'=>'archived','archived_by'=>$actor->id,'archived_at'=>now(),
                'revision'=>((int)$row->revision)+1,'updated_at'=>now(),
            ]);
            $this->audit->record('recipients.archived', $id);
        }, 1);
    }

    public function restore(string $id): void
    {
        $this->platformActor('recipients.restore', $id);
        $this->db()->transaction(function () use ($id): void {
            $db = $this->storage->connection;
            $row = $db->table('recipients')->where('id',$id)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'archived') { throw new RecipientConflict('Recipient is not archived.'); }
            // Policy might have changed since archival; fail closed if type is no longer supported.
            $this->rules->assertAllowedKind(RecipientKind::from((string)$row->kind));
            $db->table('recipients')->where('id',$id)->update([
                'status'=>'active','archived_by'=>null,'archived_at'=>null,
                'revision'=>((int)$row->revision)+1,'updated_at'=>now(),
            ]);
            // Intentionally no call to unlock old drafts. A new request is required.
            $this->audit->record('recipients.restored', $id);
        }, 1);
    }

    /** Representative is a company contact, never an authenticated agency user or a verification grant. */
    public function addRepresentative(string $recipientId, array $profile): string
    {
        [$actor, $agency] = $this->agencyActor('recipients.representative.add', 'recipient', $recipientId);
        return $this->db()->transaction(function () use ($recipientId, $actor, $agency, $profile): string {
            $row = $this->owned($recipientId, $agency, true);
            if ($row->status !== 'active' || $row->kind !== RecipientKind::Legal->value) {
                throw new RecipientConflict('An active legal recipient is required.');
            }
            RecipientInput::assertJsonObject($profile);
            $this->rules->validateRepresentative($profile);
            $max = $this->rules->maxActiveRepresentatives();
            if ($max <= 0 || $max > 1000) { throw new RecipientConflict('Stored representatives are disabled or misconfigured.'); }
            $count = $this->storage->connection->table('recipient_representatives')
                ->where('recipient_id',$recipientId)->where('active',true)->count();
            if ($count >= $max) { throw new RecipientConflict('Representative limit reached.'); }
            $id = (string) Str::ulid();
            $this->storage->connection->table('recipient_representatives')->insert([
                'id'=>$id,'recipient_id'=>$recipientId,'profile_cipher'=>$this->storage->encrypt($profile),
                'active'=>true,'revision'=>1,'created_by'=>$actor->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->audit->record('recipients.representative.added',$recipientId);
            return $id;
        }, 1);
    }
    public function deactivateRepresentative(string $recipientId, string $representativeId): void
    {
        [, $agency] = $this->agencyActor('recipients.representative.deactivate','recipient',$recipientId);
        $this->db()->transaction(function () use ($recipientId,$representativeId,$agency): void {
            $this->owned($recipientId,$agency,true);
            $db = $this->storage->connection;
            $r = $db->table('recipient_representatives')->where('id',$representativeId)
                ->where('recipient_id',$recipientId)->lockForUpdate()->first();
            if ($r === null || !$r->active) { throw new RecipientConflict('Active representative not found.'); }
            $db->table('recipient_representatives')->where('id',$representativeId)->update([
                'active'=>false,'revision'=>((int)$r->revision)+1,'updated_at'=>now(),
            ]);
            $this->audit->record('recipients.representative.deactivated',$recipientId);
        }, 1);
    }
    /** @return array<int,array{id:string,profile:array,revision:int}> */
    public function activeRepresentatives(string $recipientId): array
    {
        [, $agency] = $this->agencyActor('recipients.representative.view','recipient',$recipientId);
        $this->owned($recipientId,$agency);
        $rows = $this->storage->connection->table('recipient_representatives')
            ->where('recipient_id',$recipientId)->where('active',true)->orderBy('id')->get();
        return $rows->map(fn (object $r): array => [
            'id'=>(string)$r->id,'profile'=>$this->storage->decrypt((string)$r->profile_cipher),
            'revision'=>(int)$r->revision,
        ])->all();
    }

    /** Intended for a future host request-creation gate; merely checking is NOT an atomic reservation. */
    public function isActiveOwnedBy(string $agencyId, string $recipientId, RecipientKind $kind): bool
    {
        return $this->storage->connection->table('recipients')
            ->where('agency_id',$agencyId)->where('id',$recipientId)
            ->where('kind',$kind->value)->where('status','active')->exists();
    }
}
