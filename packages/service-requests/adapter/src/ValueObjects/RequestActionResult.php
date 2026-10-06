<?php

declare(strict_types=1);

namespace Karband\ServiceRequestsLaravel\ValueObjects;

final readonly class RequestActionResult
{
    public function __construct(
        public bool $applied,
        public bool $replayed,
        public string $currentState,
        public ?int $historyId = null,
        public ?RequestWorkflowInstance $instance = null,
    ) {}
}
