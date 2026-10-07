<?php

declare(strict_types=1);

namespace Karband\AuthenticationLaravel\Services;

use Illuminate\Contracts\Hashing\Hasher;
use Karband\Authentication\Contracts\PasswordHasherPortInterface;

final class LaravelPasswordHasher implements PasswordHasherPortInterface
{
    private string $dummyHash;

    public function __construct(
        private readonly Hasher $hasher,
        ?string $dummyHash = null
    ) {
        // Pre-compute or accept configured dummy hash at boot/construction time
        // to guarantee no dynamic expensive hash generation happens inside the authentication request path.
        $this->dummyHash = $dummyHash ?? $this->hasher->make('karband_dummy_credential_timing_safe_token');
    }

    public function hash(string $plainText): string
    {
        return $this->hasher->make($plainText);
    }

    public function verify(string $plainText, string $hashedValue): bool
    {
        return $this->hasher->check($plainText, $hashedValue);
    }

    public function dummyHash(): string
    {
        return $this->dummyHash;
    }
}
