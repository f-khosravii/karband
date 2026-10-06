<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Services;

use Intl\ServiceRecipientsLaravel\Contracts\RecipientRequestBridge;
use Intl\ServiceRecipientsLaravel\Services\RecipientStorage;
use Intl\ServiceRequests\Policies\RequestPolicy;
use Intl\ServiceRequestsLaravel\Exceptions\RequestConflict;
use Intl\ServiceRequestsLaravel\Contracts\RequestAudit;

/** Same-database bridge: recipient owns master profile; requests own immutable submitted snapshots. */
final readonly class LocalRecipientRequestBridge implements RecipientRequestBridge
{
    public function __construct(private RequestStorage $requests, private RecipientStorage $recipients, private RequestAudit $audit) {}

    public function connectionName(): ?string { return $this->requests->connection->getName(); }

    public function hasOpenSubmittedRequests(string $agencyId, string $recipientId): bool
    {
        $this->sameDb();
        $db = $this->requests->connection;
        $rows = $db->table('intl_service_requests')->where('agency_id',$agencyId)
            ->where('recipient_id',$recipientId)->whereNull('deleted_at')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $wf = $db->table('intl_workflow_instances')->where('id',$row->workflow_instance_id)->first();
            if ($wf === null || $row->policy_json === null) {
                throw new RequestConflict('Legacy request lacks trusted lifecycle metadata; resolve before archival.');
            }
            $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json,true,512,JSON_THROW_ON_ERROR));
            $hasLeftDraft = $row->first_submitted_at !== null || (string)$wf->current_state !== $policy->draftState;
            if ($hasLeftDraft && !in_array((string)$wf->current_state,$policy->terminalStates,true)) {
                return true;
            }
            if ($db->table('intl_request_cancellations')->where('request_id',$row->id)
                ->where('status','pending')->exists()) { return true; }
        }
        return false;
    }

    /** @param array<string,mixed> $profile */
    public function syncEditableDrafts(string $agencyId, string $recipientId, array $profile,
        string $identityType, string $identityValue): void
    {
        $this->sameDb();
        $db = $this->requests->connection;
        $rows = $db->table('intl_service_requests')->where('agency_id',$agencyId)
            ->where('recipient_id',$recipientId)->whereNull('first_submitted_at')
            ->whereNull('deleted_at')->whereNull('draft_frozen_at')->lockForUpdate()->get();
        $recipientRow = $this->recipients->connection->table('intl_recipients')
            ->where('agency_id',$agencyId)->where('id',$recipientId)->first();
        if ($recipientRow === null || $recipientRow->status !== 'active') {
            throw new RequestConflict('Recipient is not available for draft sync.');
        }
        foreach ($rows as $row) {
            if ($row->policy_json === null) {
                throw new RequestConflict('Legacy editable draft cannot be safely synchronized.');
            }
            $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json,true,512,JSON_THROW_ON_ERROR));
            $wf = $db->table('intl_workflow_instances')->where('id',$row->workflow_instance_id)->first();
            if ($wf === null) { throw new RequestConflict('Draft workflow is missing.'); }
            if ($wf->current_state !== $policy->draftState) { continue; } // never rewrite submitted data
            $this->syncOne($row, $policy, $profile, $identityType, $identityValue,
                (int)$recipientRow->revision + 1);
        }
    }

    public function freezeDrafts(string $agencyId, string $recipientId): void
    {
        $this->sameDb();
        $db = $this->requests->connection;
        $rows = $db->table('intl_service_requests')->where('agency_id',$agencyId)
            ->where('recipient_id',$recipientId)->whereNull('first_submitted_at')
            ->whereNull('draft_frozen_at')->lockForUpdate()->get();
        foreach ($rows as $row) {
            if ($row->policy_json === null) { throw new RequestConflict('Legacy draft must be inspected before archival.'); }
            $wf = $db->table('intl_workflow_instances')->where('id',$row->workflow_instance_id)->first();
            if ($wf === null) { throw new RequestConflict('Draft workflow is missing.'); }
            $policy = RequestPolicy::fromArray(json_decode((string)$row->policy_json,true,512,JSON_THROW_ON_ERROR));
            if ($wf->current_state !== $policy->draftState) { continue; }
            $changed = $db->table('intl_service_requests')->where('id',$row->id)->whereNull('draft_frozen_at')
                ->update(['draft_frozen_at'=>now(),'updated_at'=>now()]);
            if ($changed !== 1) { throw new RequestConflict('Draft changed during recipient archival.'); }
            $this->audit->record('requests.draft.recipient_archived',(string)$row->id);
        }
    }

    /** @param array<string,mixed> $profile */
    private function syncOne(object $request, RequestPolicy $policy, array $profile,
        string $identityType, string $identityValue, int $nextRevision): void
    {
        $db = $this->requests->connection;
        $this->assertProfileMappingsPublished($request, $policy);
        $answers = $db->table('intl_form_answers')->where('service_code',$request->service_code)
            ->where('resource_type','intl.service_request')->where('resource_id',$request->id)
            ->lockForUpdate()->first();
        if ($answers === null || (int)$answers->form_version !== (int)$request->form_version) {
            throw new RequestConflict('Pinned form answer set missing while syncing recipient.');
        }
        $current = json_decode((string)$answers->answers,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($current)) { throw new RequestConflict('Corrupted draft form answers.'); }
        foreach ($policy->recipientFieldMap as $profileField => $answerField) {
            if (array_key_exists($profileField, $profile)) { $current[$answerField] = $profile[$profileField]; }
            else { unset($current[$answerField]); }
        }
        if ($policy->recipientIdentityField !== null) {
            $current[$policy->recipientIdentityField] = $identityValue;
        }
        $snapshot = ['kind'=>$request->recipient_type,'identity_type'=>$identityType,
            'identity_value'=>$identityValue,'profile'=>$profile,'revision'=>$nextRevision];
        $formChanged = $db->table('intl_form_answers')->where('id',$answers->id)->where('revision',$answers->revision)->update([
            'answers'=>json_encode($current,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            'revision'=>((int)$answers->revision)+1,'updated_by'=>'system:recipient_sync','updated_at'=>now(),
        ]);
        if ($formChanged !== 1) { throw new RequestConflict('Form changed while syncing recipient profile.'); }
        $changed = $db->table('intl_service_requests')->where('id',$request->id)
            ->whereNull('first_submitted_at')->whereNull('draft_frozen_at')->update([
                'recipient_snapshot_cipher'=>$this->recipients->encrypt($snapshot),
                'recipient_revision'=>$nextRevision,'updated_at'=>now(),
            ]);
        if ($changed !== 1) { throw new RequestConflict('Draft changed while syncing recipient profile.'); }
        $this->audit->record('requests.draft.recipient_synced',(string)$request->id,['revision'=>$nextRevision]);
    }

    private function assertProfileMappingsPublished(object $request, RequestPolicy $policy): void
    {
        $db = $this->requests->connection;
        $row = $db->table('intl_form_definitions')->where('service_code',$request->service_code)
            ->where('version',$request->form_version)->first();
        if ($row === null) { throw new RequestConflict('Published form definition is missing.'); }
        $definition = json_decode((string)$row->definition,true,512,JSON_THROW_ON_ERROR);
        $keys = [];
        foreach ($definition['fields'] ?? [] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) { $keys[]=$field['key']; }
        }
        foreach ([...array_values($policy->recipientFieldMap), ...($policy->recipientIdentityField === null ? [] : [$policy->recipientIdentityField])] as $key) {
            if (!in_array($key,$keys,true)) {
                throw new RequestConflict('Recipient mapping refers to an absent pinned form field.');
            }
        }
    }

    private function sameDb(): void
    {
        if ($this->requests->connection !== $this->recipients->connection ||
            $this->audit->connectionName() !== $this->requests->connection->getName()) {
            throw new \LogicException('Recipients and Requests must use the same local Connection object.');
        }
    }
}
