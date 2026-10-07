<?php

declare(strict_types=1);

namespace Karband\Directory\ValueObjects;

use Karband\Directory\Exceptions\DirectoryValidationException;

/**
 * Validated generic business classification/title within an organization.
 * It is NOT an Authorization Role and carries zero implicit permissions.
 */
final readonly class OrganizationRole
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 64) {
            throw new DirectoryValidationException('OrganizationRole must be 1-64 characters.');
        }
        $this->value = $trimmed;
    }

    public static function default(): self
    {
        return new self('member');
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return strtolower($this->value) === strtolower($other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
