<?php

declare(strict_types=1);

namespace Karband\Authorization\Tests;

use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActorContextTest extends TestCase
{
    public function test_actor_context_properties(): void
    {
        $actor = new ActorContext(
            id: 'actor-1',
            role: 'operator',
            organizationId: 'org-100'
        );

        self::assertSame('actor-1', $actor->id);
        self::assertSame('operator', $actor->role);
        self::assertSame('org-100', $actor->organizationId);
    }

    public function test_actor_context_validation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ActorContext(id: '', role: 'operator');
    }

    public function test_access_request_and_denied_exception(): void
    {
        $request = new AccessRequest(
            ability: 'requests.approve',
            resourceType: 'request',
            resourceId: 'req-999'
        );

        self::assertSame('requests.approve', $request->ability);
        self::assertSame('request', $request->resourceType);
        self::assertSame('req-999', $request->resourceId);

        $denied = new AuthorizationDenied('custom_reason');
        self::assertSame('custom_reason', $denied->reasonCode);
        self::assertSame('Authorization denied: custom_reason', $denied->getMessage());
    }
}
