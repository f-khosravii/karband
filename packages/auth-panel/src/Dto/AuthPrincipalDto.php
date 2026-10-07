<?php

declare(strict_types=1);

namespace Karband\AuthPanel\Dto;

final class AuthPrincipalDto
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $loginIdentifier,
    ) {
    }
}
