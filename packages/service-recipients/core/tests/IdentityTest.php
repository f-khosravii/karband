<?php

declare(strict_types=1);
namespace Karband\ServiceRecipients\Tests;
use Karband\ServiceRecipients\Identity;
use Karband\ServiceRecipients\RecipientInput;
use Karband\ServiceRecipients\RecipientKind;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    public function test_digest_is_deterministic_and_changes_with_kind_or_key(): void
    {
        $id = new Identity('national_id', '1234567890');
        $key = str_repeat('K', 32);
        self::assertSame($id->digest(RecipientKind::Person, 'agency-1', $key), $id->digest(RecipientKind::Person, 'agency-1', $key));
        self::assertNotSame($id->digest(RecipientKind::Person, 'agency-1', $key), $id->digest(RecipientKind::Legal, 'agency-1', $key));
        self::assertNotSame($id->digest(RecipientKind::Person, 'agency-1', $key), $id->digest(RecipientKind::Person, 'agency-1', str_repeat('Q', 32)));
        self::assertNotSame($id->digest(RecipientKind::Person, 'agency-1', $key), $id->digest(RecipientKind::Person, 'agency-2', $key));
        self::assertStringNotContainsString('1234567890', $id->digest(RecipientKind::Person, 'agency-1', $key));
    }
    public function test_rejects_short_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Identity('passport', 'ABC'))->digest(RecipientKind::Person, 'agency-1', 'test');
    }
    public function test_rejects_invalid_identity_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Identity('Not Allowed', '123');
    }
    public function test_rejects_list_instead_of_profile_object(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecipientInput(RecipientKind::Person, 'id', '123', ['bad']);
    }
}
