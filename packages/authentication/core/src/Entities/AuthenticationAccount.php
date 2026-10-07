<?php

declare(strict_types=1);

namespace Karband\Authentication\Entities;

use Karband\Authentication\Enums\AuthenticationAccountStatus;
use Karband\Authentication\Exceptions\AuthenticationValidationException;

/**
 * Public domain entity representing an authentication account.
 * Encapsulates identity and lifecycle state; never holds or exposes sensitive credential hashes.
 */
final class AuthenticationAccount implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $loginIdentifier,
        public readonly AuthenticationAccountStatus $status,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
        if (trim($this->id) === '') {
            throw new AuthenticationValidationException('Authentication account ID cannot be empty.');
        }

        if (trim($this->loginIdentifier) === '') {
            throw new AuthenticationValidationException('Login identifier cannot be empty.');
        }
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isDisabled(): bool
    {
        return $this->status->isDisabled();
    }

    public function withStatus(AuthenticationAccountStatus $status): self
    {
        return new self(
            id: $this->id,
            loginIdentifier: $this->loginIdentifier,
            status: $status,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Mask debug output to prevent inadvertent leakages.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'loginIdentifier' => $this->loginIdentifier,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * Ensure JSON serialization only exposes public account properties.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'login_identifier' => $this->loginIdentifier,
            'status' => $this->status->value,
            'created_at' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
