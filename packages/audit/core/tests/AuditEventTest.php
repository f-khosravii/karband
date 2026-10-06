<?php

declare(strict_types=1);

namespace Karband\Audit\Tests;

use Karband\Audit\AuditEvent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditEventTest extends TestCase
{
    public function test_typed_safe_event_is_accepted(): void
    {
        $event = new AuditEvent('billing.payment.unknown', 'request', 'opaque-123', [
            'result' => 'unknown', 'operation_id' => 'op-123',
        ]);
        self::assertSame('billing.payment.unknown', $event->code);
        self::assertSame('opaque-123', $event->subjectId);
    }

    public function test_raw_payload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuditEvent('billing.payment.confirmed', 'request', 'r-1', ['api_response' => '{"token":"secret"}']);
    }

    public function test_nested_metadata_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuditEvent('requests.request.created', 'request', 'r-1', ['result' => ['password' => 'secret']]);
    }

    public function test_freeform_subject_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuditEvent('requests.request.created', 'request', 'a human name');
    }

    public function test_unstructured_event_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuditEvent('Logged customer message', 'request', 'r-1');
    }
}
