<?php

declare(strict_types=1);
namespace Karband\ServiceRecipients\Tests;

use Karband\ServiceRecipients\Authority\AuthorityDecision;
use Karband\ServiceRecipients\Authority\AuthorityVerificationPolicy;
use Karband\ServiceRecipients\Authority\ExternalOutagePolicy;
use Karband\ServiceRecipients\Authority\VerificationEvidence;
use Karband\ServiceRecipients\Authority\VerificationMethod;
use PHPUnit\Framework\TestCase;

final class AuthorityVerificationPolicyTest extends TestCase
{
    public function test_external_outage_cannot_implicitly_approve(): void
    {
        $policy = new AuthorityVerificationPolicy(VerificationMethod::External, ExternalOutagePolicy::Wait);
        self::assertSame(AuthorityDecision::Pending,
            $policy->decide(VerificationEvidence::Approved, VerificationEvidence::Unavailable, true));
    }

    public function test_manual_fallback_requires_both_permission_and_positive_manual_evidence(): void
    {
        $policy = new AuthorityVerificationPolicy(VerificationMethod::External, ExternalOutagePolicy::AuthorizedManualFallback);
        self::assertSame(AuthorityDecision::Pending,
            $policy->decide(VerificationEvidence::Approved, VerificationEvidence::Unavailable, false));
        self::assertSame(AuthorityDecision::Approved,
            $policy->decide(VerificationEvidence::Approved, VerificationEvidence::Unavailable, true));
        self::assertSame(AuthorityDecision::Pending,
            $policy->decide(VerificationEvidence::Missing, VerificationEvidence::Unavailable, true));
    }

    public function test_hybrid_requires_both_evidence_unless_explicit_fallback_is_authorized(): void
    {
        $policy = new AuthorityVerificationPolicy(VerificationMethod::Hybrid, ExternalOutagePolicy::AuthorizedManualFallback);
        self::assertSame(AuthorityDecision::Pending,
            $policy->decide(VerificationEvidence::Approved, VerificationEvidence::Missing, true));
        self::assertSame(AuthorityDecision::Denied,
            $policy->decide(VerificationEvidence::Approved, VerificationEvidence::Revoked, true));
        self::assertSame(AuthorityDecision::Approved,
            $policy->decide(VerificationEvidence::Approved, VerificationEvidence::Approved));
    }
}
