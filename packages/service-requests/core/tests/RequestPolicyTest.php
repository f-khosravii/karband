<?php

declare(strict_types=1);

namespace Karband\ServiceRequests\Tests;

use Karband\ServiceRequests\Policies\CorrectionScope;
use Karband\ServiceRequests\Policies\RequestPolicy;
use PHPUnit\Framework\TestCase;

final class RequestPolicyTest extends TestCase
{
    private static function sample(): array
    {
        return ['draft_state'=>'draft','submit_actions'=>['submit','resubmit'],
            'submitted_states'=>['submitted','correction_required'], 'terminal_states'=>['approved','rejected','cancelled'], 'cancelled_state'=>'cancelled',
            'cancellable_states'=>['submitted','correction_required'], 'correction_states'=>['correction_required'],
            'correction_mode'=>'limited', 'correction_action'=>'require_correction',
            'resubmit_action'=>'resubmit','cancel_draft_action'=>'cancel_draft',
            'cancel_actions_by_state'=>['submitted'=>'cancel_submitted', 'correction_required'=>'cancel_correction'],
            'recipient_field_map'=>['first_name'=>'person_name']];
    }
    public function test_policy_round_trips_without_permitting_cancellation_after_approval(): void
    {
        $policy = RequestPolicy::fromArray(self::sample());
        self::assertSame(self::sample(), $policy->toArray());
        self::assertTrue($policy->mayRequestCancellation('submitted'));
        self::assertFalse($policy->mayRequestCancellation('approved'));
        self::assertSame('cancel_correction', $policy->cancellationAction('correction_required'));
    }
    public function test_policy_rejects_undefined_cancellation_states(): void
    {
        $data = self::sample(); $data['cancel_actions_by_state']['approved'] = 'cancel_approved';
        $this->expectException(\InvalidArgumentException::class);
        RequestPolicy::fromArray($data);
    }
    public function test_cancellation_state_cannot_point_to_approval(): void
    {
        $data = self::sample(); $data['cancelled_state'] = 'approved';
        $data['cancellable_states'][] = 'approved';
        $this->expectException(\InvalidArgumentException::class);
        RequestPolicy::fromArray($data);
    }
    public function test_limited_correction_prevents_unauthorized_changes_even_when_full_payload_sent(): void
    {
        $scope = new CorrectionScope('limited',['name'],['passport']);
        $scope->assertAnswers(['name'=>'A','date'=>'2026'], ['name'=>'B','date'=>'2026']);
        $scope->assertDocumentSlot('passport');
        $this->expectException(\DomainException::class);
        $scope->assertAnswers(['name'=>'A','date'=>'2026'], ['name'=>'B','date'=>'2027']);
    }
    public function test_limited_correction_rejects_unknown_document_slot(): void
    {
        $this->expectException(\DomainException::class);
        (new CorrectionScope('limited',[],['passport']))->assertDocumentSlot('other');
    }
    public function test_limited_correction_rejects_empty_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CorrectionScope('limited',[],[]);
    }
}
