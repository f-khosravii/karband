<?php

declare(strict_types=1);

namespace Karband\AuditLaravel\Tests;

use PHPUnit\Framework\TestCase;
use Karband\Audit\AuditEvent;

final class AuditAdapterTest extends TestCase
{
    public function test_audit_event_creation(): void
    {
        $event = new AuditEvent('billing.payment.unknown', 'request', 'req-1', ['operation_id' => 'op-42']);
        self::assertSame('op-42', $event->metadata['operation_id']);
    }

    public function test_audit_event_invalid_rejections(): void
    {
        $invalidCases = [
            fn () => new AuditEvent('bad event', 'request', 'r-1'),
            fn () => new AuditEvent('requests.request.created', 'request', 'r-1', ['token' => 'secret']),
            fn () => new AuditEvent('requests.request.created', 'request', 'r-1', ['result' => ['nested']]),
            fn () => new AuditEvent('requests.request.created', 'request', 'some user name'),
        ];

        foreach ($invalidCases as $case) {
            $threw = false;
            try {
                $case();
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            self::assertTrue($threw, 'Expected InvalidArgumentException for invalid audit event');
        }
    }
}
