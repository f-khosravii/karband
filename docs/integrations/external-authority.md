# External Authority Verification & Outage Policies

## 1. Overview

The `service-recipients` capability interacts with external government registries (e.g. National Civil Registration Organization / SabteAhval, Postal Service / Post, Corporate Registration / Asnad) to verify the legal identity and attributes of citizens and legal entities.

---

## 2. Verification Protocol

1. **Identity Tokenization & Hashing**:
   - National Identification Numbers (National Code / Shenaseh Melli) are encrypted at rest.
   - Blind indexes (HMAC-SHA256 with pepper) are used for deterministic lookups without decrypting entire tables.
2. **Authority Lookup Contracts**:
   - `AuthorityVerifierInterface` defines the contract for looking up and verifying recipient facts:
     ```php
     interface AuthorityVerifierInterface
     {
         public function verifyPerson(NationalId $nationalId, BirthDate $birthDate): VerificationResult;
         public function verifyCompany(CompanyId $companyId): CompanyVerificationResult;
     }
     ```
3. **Verification Caching**:
   - Successful verification results may be cached for a configurable TTL (default: 24 hours) to prevent redundant remote queries and rate-limiting issues.
   - Cached records store verification timestamp and authority reference ID.

---

## 3. Outage & Degraded Mode Policies

1. **Fail-Closed by Default**:
   - In production environments, if an external authority is unreachable (HTTP 5xx, connection timeout, network partition), the system must **FAIL CLOSED**. Unverified recipients cannot submit high-assurance service requests.
2. **Graceful Degraded Mode (Configurable)**:
   - For non-critical services where provisional filing is permitted by policy:
     - The request is accepted with status `PROVISIONAL_PENDING_VERIFICATION`.
     - A background job reconciles the identity once the authority recovers.
     - Final workflow approval / certificate issuance remains blocked until verification succeeds.
3. **Audit Logging**:
   - All authority verification attempts, successes, failures, and timeouts must produce structured audit log entries recorded via `audit-laravel`.
