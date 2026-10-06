<?php

declare(strict_types=1);

return [
    // A LOCAL database, unique to this deployed microservice. Never use the central provider DB.
    'connection' => null,
    // MANDATORY deployment-unique stable identifier; must never change while payments exist.
    'client_namespace' => env('INTL_PAYMENT_CLIENT_NAMESPACE', ''),
    // A selected host role may INITIATE payment; all actions additionally require explicit permissions.
    'initiator_role' => 'agency',
    // A possibly in-flight call must not be looked up too early. Reconcile via an operator or a scheduled JOB.
    'reconcile_after_seconds' => 60,
    'max_attempts' => 3,
    // Set true in each real host; fail closed if the atomic catalog pricing bridge is absent.
    'require_atomic_financial_terms' => true,
    // A prepared quote is fixed for one attempt only, subject to an explicit confirmation TTL.
    'quote_ttl_seconds' => 900,
    'refund_approver_role' => 'government_admin',
];
