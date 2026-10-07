<?php

declare(strict_types=1);

namespace Karband\Authentication\Tests;

use Karband\Authentication\Exceptions\AuthenticationValidationException;
use Karband\Authentication\ValueObjects\LoginIdentifier;
use PHPUnit\Framework\TestCase;

final class LoginIdentifierTest extends TestCase
{
    public function test_normalizes_case_and_trims_whitespace(): void
    {
        $id1 = LoginIdentifier::fromString('  Admin@Example.COM  ');
        self::assertSame('admin@example.com', (string) $id1);

        $id2 = LoginIdentifier::fromString('admin@example.com');
        self::assertTrue($id1->equals($id2));
    }

    public function test_rejects_empty_or_whitespace_only(): void
    {
        $this->expectException(AuthenticationValidationException::class);
        LoginIdentifier::fromString('   ');
    }
}
