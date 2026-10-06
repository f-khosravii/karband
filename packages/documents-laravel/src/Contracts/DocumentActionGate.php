<?php

declare(strict_types=1);

namespace Intl\DocumentsLaravel\Contracts;

use Intl\Contracts\Authorization\ActorContext;

interface DocumentActionGate
{
    public const ACTION_VIEW = 'view';
    public const ACTION_UPLOAD = 'upload';
    public const ACTION_REPLACE = 'replace';
    public const ACTION_WITHDRAW = 'withdraw';

    /**
     * @param array<string,mixed> $context
     */
    public function allows(
        ActorContext $actor,
        string $action,
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        array $context = [],
    ): bool;

    /**
     * @param array<string,mixed> $context
     */
    public function assertAllowed(
        ActorContext $actor,
        string $action,
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        array $context = [],
    ): void;
}
