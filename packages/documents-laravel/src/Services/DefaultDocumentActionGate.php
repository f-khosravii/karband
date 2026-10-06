<?php

declare(strict_types=1);

namespace Intl\DocumentsLaravel\Services;

use Intl\Contracts\Authorization\AccessRequest;
use Intl\Contracts\Authorization\ActorContext;
use Intl\Contracts\Authorization\AuthorizationDenied;
use Intl\Contracts\Authorization\AuthorizerInterface;
use Intl\DocumentsLaravel\Contracts\DocumentActionGate;
use Intl\DocumentsLaravel\Contracts\DocumentEditGate;
use Intl\DocumentsLaravel\Contracts\ReplacementPolicy;

final readonly class DefaultDocumentActionGate implements DocumentActionGate
{
    public function __construct(
        private AuthorizerInterface $authorizer,
        private ?ReplacementPolicy $replacementPolicy = null,
        private ?DocumentEditGate $editGate = null,
    ) {
    }

    public function allows(
        ActorContext $actor,
        string $action,
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        array $context = [],
    ): bool {
        try {
            $this->assertAllowed($actor, $action, $serviceCode, $resourceType, $resourceId, $slot, $context);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function assertAllowed(
        ActorContext $actor,
        string $action,
        string $serviceCode,
        string $resourceType,
        string $resourceId,
        string $slot,
        array $context = [],
    ): void {
        $permission = match ($action) {
            DocumentActionGate::ACTION_VIEW => 'documents.file.view',
            DocumentActionGate::ACTION_UPLOAD => 'documents.file.upload',
            DocumentActionGate::ACTION_REPLACE => 'documents.file.replace',
            DocumentActionGate::ACTION_WITHDRAW => 'documents.file.withdraw',
            default => throw new \InvalidArgumentException("Unknown document action: {$action}"),
        };

        $this->authorizer->authorize($actor, new AccessRequest($permission, $resourceType, $resourceId));

        if ($action === DocumentActionGate::ACTION_REPLACE && $this->replacementPolicy !== null) {
            $allowed = $this->replacementPolicy->allows($actor, $serviceCode, $resourceType, $resourceId, $slot);
            if (!$allowed) {
                throw new AuthorizationDenied('replacement_not_allowed');
            }
        }

        if (in_array($action, [DocumentActionGate::ACTION_UPLOAD, DocumentActionGate::ACTION_REPLACE, DocumentActionGate::ACTION_WITHDRAW], true)) {
            if ($this->editGate !== null && $resourceType === 'intl.service_request') {
                $this->editGate->assertDocumentSlot($serviceCode, $resourceType, $resourceId, $slot, $action);
            }
        }
    }
}
