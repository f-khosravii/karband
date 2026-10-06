<?php

declare(strict_types=1);
namespace Intl\ServiceRecipients;

final readonly class RecipientSnapshot
{
    /** @param array<string,mixed> $profile */
    public function __construct(
        public string $id,
        public string $agencyId,
        public RecipientKind $kind,
        public Identity $identity,
        public array $profile,
        public string $status,
        public int $revision,
    ) {}
}
