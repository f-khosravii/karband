# ADR-0004: Two-Stage Payment Processing & Idempotency

Status: Accepted

## Context

Financial transactions across government platforms interact with external central payment gateways (such as Central Payment / Shaparak). Direct single-step payment execution risks race conditions, double charges during network timeouts, and uncoordinated settlement.

## Decision

1. **Two-Stage Payment Protocol**:
   - Payments must follow a strict two-stage lifecycle:
     1. **Prepare (`PaymentIntent` creation)**: Generates a reservation with a unique cryptographic idempotency key, amount in smallest currency unit (`Rials`), and initial status `PENDING`.
     2. **Confirm (`Capture` / `Settle`)**: Confirms the transaction upon successful return from the gateway. If gateway verification fails or times out, the intent can be canceled or reconciled.
2. **Strict Idempotency**:
   - Every payment command requires an `idempotency_key`. Duplicate requests with the same key return the existing intent without creating new financial obligations.
3. **Decoupled Request Quote & Billing**:
   - `billing` has zero knowledge of `service-requests` quote tables.
   - Quote amounts and line items are converted to standard `Money` value objects by host orchestration before invoking Billing commands.
4. **Gateway Reconciliation**:
   - Gateway communication failures trigger automated status inquiries to verify whether the central gateway captured the transaction before marking a payment failed.

## Consequences

- ACID compliance and prevention of double billing.
- Reliable state recovery after gateway network failures.
- Zero database coupling between billing and service request domain tables.
