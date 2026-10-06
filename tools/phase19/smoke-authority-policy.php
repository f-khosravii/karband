<?php

declare(strict_types=1);

use Intl\ServiceRecipients\Authority\{AuthorityDecision,AuthorityVerificationPolicy,ExternalOutagePolicy,VerificationEvidence,VerificationMethod};
foreach (['AuthorityDecision','AuthorityVerificationPolicy','ExternalOutagePolicy','VerificationEvidence','VerificationMethod'] as $class) {
    require dirname(__DIR__, 2) . '/packages/service-recipients/src/Authority/' . $class . '.php';
}
$wait = new AuthorityVerificationPolicy(VerificationMethod::Hybrid, ExternalOutagePolicy::Wait);
$fallback = new AuthorityVerificationPolicy(VerificationMethod::Hybrid, ExternalOutagePolicy::AuthorizedManualFallback);
$cases = [
    [$wait, VerificationEvidence::Approved, VerificationEvidence::Unavailable, true, AuthorityDecision::Pending],
    [$fallback, VerificationEvidence::Approved, VerificationEvidence::Unavailable, false, AuthorityDecision::Pending],
    [$fallback, VerificationEvidence::Approved, VerificationEvidence::Unavailable, true, AuthorityDecision::Approved],
    [$fallback, VerificationEvidence::Approved, VerificationEvidence::Revoked, true, AuthorityDecision::Denied],
    [$fallback, VerificationEvidence::Approved, VerificationEvidence::Missing, true, AuthorityDecision::Pending],
];
foreach ($cases as [$policy,$manual,$external,$permission,$expected]) {
    if ($policy->decide($manual,$external,$permission) !== $expected) {
        throw new \RuntimeException('Verification policy returned unsafe decision.');
    }
}
echo 'PASS: ' . count($cases) . " offline authority decisions; no real external verification.\n";
