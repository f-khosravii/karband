<?php

declare(strict_types=1);

namespace Karband\Audit;

interface AuditRecorderInterface
{
    public function record(AuditEvent $event): void;
}
