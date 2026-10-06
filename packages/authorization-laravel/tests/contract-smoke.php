<?php

declare(strict_types=1);

// Run with php packages/authorization-laravel/tests/contract-smoke.php (no Composer required).
foreach (glob(__DIR__ . '/../../contracts/src/Authorization/*.php') ?: [] as $path) {
    require_once $path;
}

use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\AuthorizationDenied;

$checks = 0;
$actor = new ActorContext('operator-1', 'agency', 'agency-a');
assert($actor->organizationId === 'agency-a'); $checks++;
$req = new AccessRequest('workflow.instance.view', 'ncr.request', 'request-1');
assert($req->ability === 'workflow.instance.view'); $checks++;
try {
    new ActorContext('', 'agency');
    throw new RuntimeException('Expected invalid actor');
} catch (InvalidArgumentException) { $checks++; }
try {
    new ActorContext('operator', '');
    throw new RuntimeException('Expected invalid role');
} catch (InvalidArgumentException) { $checks++; }
try {
    new ActorContext('operator', 'agency', '');
    throw new RuntimeException('Expected invalid organization');
} catch (InvalidArgumentException) { $checks++; }
try {
    new AccessRequest(' ', 'request', '1');
    throw new RuntimeException('Expected invalid ability');
} catch (InvalidArgumentException) { $checks++; }
try {
    throw new AuthorizationDenied('scope_denied');
} catch (AuthorizationDenied $e) { assert($e->reasonCode === 'scope_denied'); $checks++; }
if ($checks !== 7) {
    throw new RuntimeException('Failed contract checks.');
}
echo "Authorization contracts: {$checks} checks passed\n";
