<?php

declare(strict_types=1);

namespace Intl\ServiceRequests\Tests;

use Intl\ServiceRequests\ValueObjects\RecipientReference;
use Intl\ServiceRequests\ValueObjects\RequestDraft;
use Intl\ServiceRequests\ValueObjects\RequestResource;
use Intl\ServiceRequests\ValueObjects\RequestSnapshot;
use PHPUnit\Framework\TestCase;

final class RequestModelTest extends TestCase
{
    public function test_valid_draft_contains_no_user_supplied_agency(): void
    {
        $draft = new RequestDraft('benefit', new RecipientReference('person', 'customer-1'), 'browser-a');
        self::assertSame('benefit', $draft->serviceCode);
        self::assertSame(['serviceCode', 'recipient', 'idempotencyKey'], array_keys(get_object_vars($draft)));
    }

    public function test_rejects_invalid_service_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RequestDraft('../path', new RecipientReference('person', '1'), 'key');
    }

    public function test_rejects_empty_idempotency_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RequestDraft('benefit', new RecipientReference('person', '1'), '');
    }

    public function test_rejects_control_characters_in_recipient_reference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecipientReference('person', "id\nnew");
    }

    public function test_rejects_invalid_recipient_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecipientReference('Person Name', 'id');
    }

    public function test_snapshot_keeps_workflow_state_as_a_read_model(): void
    {
        $snapshot = new RequestSnapshot('id','benefit','agency-1',new RecipientReference('person','x'),
            'instance','wf',1,'draft',1,1);
        self::assertSame(RequestResource::TYPE, 'intl.service_request');
        self::assertSame('draft', $snapshot->currentState);
    }

    public function test_snapshot_rejects_unpinned_versions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RequestSnapshot('id','benefit','agency',new RecipientReference('person','x'),'inst','wf',1,'draft',0,1);
    }
}
