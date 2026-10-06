<?php

declare(strict_types=1);
namespace Karband\ServiceRecipients\Authority;

/**
 * Pure policy evaluation, NOT a verifier. Host must authenticate evidence,
 * check expiry/revocation and permission for the fallback BEFORE calling.
 * An outage can NEVER silently become approval.
 */
final readonly class AuthorityVerificationPolicy
{
    public function __construct(
        public VerificationMethod $method,
        public ExternalOutagePolicy $outagePolicy,
    ) {}

    public function decide(
        VerificationEvidence $manual,
        VerificationEvidence $external,
        bool $authorizedManualFallback = false,
    ): AuthorityDecision {
        if ($this->method === VerificationMethod::Manual) {
            return self::single($manual);
        }
        if ($this->method === VerificationMethod::External) {
            if ($external === VerificationEvidence::Unavailable &&
                $this->outagePolicy === ExternalOutagePolicy::AuthorizedManualFallback &&
                $authorizedManualFallback) {
                return self::single($manual);
            }
            return self::single($external);
        }
        // Hybrid: valid external + approved manual both required unless an
        // explicitly permitted outage fallback substitutes for unavailable external.
        if (self::single($manual) === AuthorityDecision::Denied ||
            self::single($external) === AuthorityDecision::Denied) {
            return AuthorityDecision::Denied;
        }
        if ($external === VerificationEvidence::Unavailable &&
            $this->outagePolicy === ExternalOutagePolicy::AuthorizedManualFallback &&
            $authorizedManualFallback) {
            return self::single($manual);
        }
        return ($manual === VerificationEvidence::Approved && $external === VerificationEvidence::Approved)
            ? AuthorityDecision::Approved : AuthorityDecision::Pending;
    }

    private static function single(VerificationEvidence $evidence): AuthorityDecision
    {
        return match ($evidence) {
            VerificationEvidence::Approved => AuthorityDecision::Approved,
            VerificationEvidence::Rejected, VerificationEvidence::Expired,
            VerificationEvidence::Revoked => AuthorityDecision::Denied,
            VerificationEvidence::Missing, VerificationEvidence::Unavailable => AuthorityDecision::Pending,
        };
    }
}
