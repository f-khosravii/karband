<?php

declare(strict_types=1);

namespace Karband\Authentication\Contracts;

/**
 * Consumer-owned port for password hashing, secure verification, and timing-safe dummy evaluation.
 */
interface PasswordHasherPortInterface
{
    /**
     * Hash the given plain text password.
     */
    public function hash(string $plainText): string;

    /**
     * Securely verify that the plain text matches the hash.
     */
    public function verify(string $plainText, string $hashedValue): bool;

    /**
     * Provide a valid dummy hash for timing-safe verification when account does not exist.
     */
    public function dummyHash(): string;
}
