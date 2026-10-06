<?php

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

use Intl\ServiceRequests\ValueObjects\RecipientReference;
use Intl\ServiceRequests\ValueObjects\RequestDraft;
use Intl\ServiceRequests\ValueObjects\RequestSnapshot;

$tests = 0;
$draft = new RequestDraft('benefit', new RecipientReference('person','c-1'), 'client-key');
if ($draft->serviceCode !== 'benefit' || $draft->recipient->id !== 'c-1') { throw new RuntimeException('Draft failure'); }
$tests++;
$snapshot = new RequestSnapshot('id', 'benefit', 'agency', $draft->recipient, 'wf-inst', 'wf', 1, 'draft', 1, 1);
if ($snapshot->currentState !== 'draft') { throw new RuntimeException('Snapshot failure'); }
$tests++;
foreach ([
    fn () => new RecipientReference('Bad Type','c-1'),
    fn () => new RecipientReference('person', "c\n2"),
    fn () => new RequestDraft('../path',$draft->recipient,'key'),
    fn () => new RequestDraft('benefit',$draft->recipient,''),
    fn () => new RequestSnapshot('id','benefit','agency',$draft->recipient,'wi','wf',1,'draft',0,1),
] as $invalid) {
    try { $invalid(); throw new RuntimeException('Invalid input accepted'); }
    catch (InvalidArgumentException) { $tests++; }
}
echo "PASS: {$tests} standalone core checks\n";
