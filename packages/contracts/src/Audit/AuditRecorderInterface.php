<?php

declare(strict_types=1);

namespace Intl\Contracts\Audit;

/** Host supplies a trusted authenticated principal; caller supplies no actor identity. */
interface AuditRecorderInterface
{
    public function record(AuditEvent $event): void;
}
