<?php

declare(strict_types=1);

namespace Karband\AuthorizationLaravel\Tests;

use PHPUnit\Framework\TestCase;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;

final class AuthorizationAdapterTest extends TestCase
{
    public function test_actor_context_and_access_request(): void
    {
        $actor = new ActorContext('operator-1', 'agency', 'agency-a');
        self::assertSame('agency-a', $actor->organizationId);

        $req = new AccessRequest('workflow.instance.view', 'ncr.request', 'request-1');
        self::assertSame('workflow.instance.view', $req->ability);
    }

    public function test_invalid_actor_and_request_rejections(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ActorContext('', 'agency');
    }

    public function test_authorization_denied_reason_code(): void
    {
        $denied = new AuthorizationDenied('scope_denied');
        self::assertSame('scope_denied', $denied->reasonCode);
    }
}
