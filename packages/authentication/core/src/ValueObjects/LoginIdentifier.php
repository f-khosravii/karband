<?php

declare(strict_types=1);

namespace Karband\Authentication\ValueObjects;

use Karband\Authentication\Exceptions\AuthenticationValidationException;

final class LoginIdentifier implements \Stringable
{
    public readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function fromString(string $raw): self
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new AuthenticationValidationException('Login identifier cannot be empty.');
        }

        $normalized = mb_strtolower($trimmed, 'UTF-8');

        return new self($normalized);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
