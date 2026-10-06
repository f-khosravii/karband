<?php

declare(strict_types=1);

require __DIR__ . '/../../contracts/src/Audit/AuditEvent.php';
require __DIR__ . '/../../contracts/src/Audit/AuditRecorderInterface.php';
require __DIR__ . '/../src/Exceptions/AuditRecordDenied.php';
require __DIR__ . '/../src/Services/SpatieAuditRecorder.php';

use Intl\Contracts\Audit\AuditEvent;

$good = new AuditEvent('billing.payment.unknown', 'request', 'req-1', ['operation_id' => 'op-42']);
assert($good->metadata['operation_id'] === 'op-42');
$rejections = 0;
foreach ([
    fn () => new AuditEvent('bad event', 'request', 'r-1'),
    fn () => new AuditEvent('requests.request.created', 'request', 'r-1', ['token' => 'secret']),
    fn () => new AuditEvent('requests.request.created', 'request', 'r-1', ['result' => ['nested']]),
    fn () => new AuditEvent('requests.request.created', 'request', 'some user name'),
] as $invalid) {
    try {
        $invalid();
        throw new \RuntimeException('Expected rejection did not occur.');
    } catch (\InvalidArgumentException) {
        $rejections++;
    }
}
if ($rejections !== 4) {
    throw new \RuntimeException('Unexpected rejection count.');
}
echo "PASS: audit value objects and sensitive metadata restrictions (5 checks)\n";
