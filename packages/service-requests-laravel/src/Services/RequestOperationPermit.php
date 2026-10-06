<?php

declare(strict_types=1);

namespace Intl\ServiceRequestsLaravel\Services;

/** Request-local permit: prohibits bypassing lifecycle actions via direct WorkflowRuntime calls. */
final class RequestOperationPermit
{
    /** @var list<array{id:string,purpose:string}> */
    private array $stack = [];

    public function within(string $requestId, string $purpose, callable $operation): mixed
    {
        $this->stack[] = ['id'=>$requestId, 'purpose'=>$purpose];
        try { return $operation(); }
        finally { array_pop($this->stack); }
    }

    public function permits(string $requestId, string $purpose): bool
    {
        $last = $this->stack === [] ? null : $this->stack[count($this->stack)-1];
        return $last !== null && $last['id'] === $requestId && $last['purpose'] === $purpose;
    }
}
