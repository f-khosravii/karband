# Central Payment Gateway Integration Contract

## 1. Overview & Purpose
This document specifies the integration requirements between host microservices and the central Epishkhan Payment Gateway.

Host microservices interact strictly with the Payment subsystem using domain actions (`CreatePaymentIntent`, `PreparePayment`, `ConfirmPayment`, `CancelPayment`, `ReconcilePayment`, `ProposeRefund`, `ApproveRefund`, `DispatchRefund`) and domain value objects (`Money`, `PaymentOutcome`).

Under no circumstances should host microservices manage wallet balances, credit accounting, or internal payment gateways directly.

---

## 2. Mandatory Integration Requirements Before Production Dispatch
Before activating a production `CentralPaymentGateway` adapter binding, the following technical facts MUST be provided and contractually verified by the central payment team:

### A. Machine-to-Machine (M2M) Authentication
- Authentication mechanism (JWT bearer token, mTLS, HMAC payload signature).
- Key rotation and secret management lifecycle.
- IP allowlisting requirements for production vs. staging environments.
- Server certificate verification rules.

### B. Two-Stage Payment API Endpoints
- **Prepare Endpoint**: Initiates payment intent with stable microservice request ID, agency ID, service/case identifier, and exact monetary amount.
- **Confirm / Capture Endpoint**: Finalizes the transaction upon user/agency confirmation.
- **Cancel / Void Endpoint**: Cancels a prepared transaction before settlement.

### C. Idempotency Guarantees
- Idempotency key scope across microservices and services.
- Retention duration of idempotency keys on the central gateway (minimum 72 hours recommended).
- Gateway behavior when identical requests with the same idempotency key are resent: must return identical authoritative transaction outcome without double-debiting.

### D. Status Reconciliation & Inquiries
- Query endpoint for definitive transaction status by idempotency key / transaction reference.
- Semantics of `not_found`: distinguishes between unreceived requests vs. pending processing.
- Handling of network timeouts: microservice initiates asynchronous reconciliation before transitioning to failed or confirmed states.

### E. Financial Units & Currency
- Standard currency code (IRR / Rials or Tomans).
- Maximum monetary bounds and fractional precision rules.
- Authority source for catalog service fee tariffs.

### F. Refunds & Adjustments
- Partial and full refund execution endpoints.
- Reconciliation procedures for settlement discrepancies.
- Webhook notification payloads and HMAC signature verification for async refund status.

---

## 3. Operational Policy
Until sections 2.B, 2.C, and 2.D are implemented with verifiable automated tests against a staging environment, the production gateway binding MUST fail closed. The integration test suite uses deterministic mock/fake adapters in test fixtures only.
