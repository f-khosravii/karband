<?php
require dirname(__DIR__) . '/src/Policies/RequestPolicy.php';
require dirname(__DIR__) . '/src/Policies/CorrectionScope.php';
use Intl\ServiceRequests\Policies\CorrectionScope;
use Intl\ServiceRequests\Policies\RequestPolicy;
$p = RequestPolicy::fromArray(['draft_state'=>'draft','submit_actions'=>['submit'],'submitted_states'=>['submitted'],
    'terminal_states'=>['approved','cancelled'],'cancelled_state'=>'cancelled', 'cancellable_states'=>['submitted'], 'correction_states'=>['needs_correction'],
    'correction_mode'=>'limited','correction_action'=>'require_correction','resubmit_action'=>'resubmit',
    'cancel_draft_action'=>'cancel_draft','cancel_actions_by_state'=>['submitted'=>'cancel_submitted']]);
assert($p->mayRequestCancellation('submitted'));
assert(!$p->mayRequestCancellation('approved'));
(new CorrectionScope('limited', ['name'], []))->assertAnswers(['name'=>'a','id'=>1], ['name'=>'b','id'=>1]);
try { (new CorrectionScope('limited', ['name'], []))->assertAnswers(['name'=>'a','id'=>1], ['name'=>'b','id'=>2]);
    throw new RuntimeException('FAIL: locked field mutation passed'); }
catch (DomainException) {}
echo "PHASE15_CORE_SMOKE_OK\n";
