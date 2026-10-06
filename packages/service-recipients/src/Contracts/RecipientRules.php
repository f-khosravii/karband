<?php

declare(strict_types=1);
namespace Intl\ServiceRecipients\Contracts;

use Intl\ServiceRecipients\Identity;
use Intl\ServiceRecipients\RecipientKind;

/** No default: each microservice supplies identity rules and profile schemas. */
interface RecipientRules
{
    public function assertAllowedKind(RecipientKind $kind): void;
    public function normalize(RecipientKind $kind, string $identityType, string $raw): Identity;
    /** @param array<string,mixed> $profile */
    public function validateProfile(RecipientKind $kind, array $profile): void;
    /** @param array<string,mixed> $profile */
    public function validateRepresentative(array $profile): void;
    /** Zero means stored representatives are disabled for this host. */
    public function maxActiveRepresentatives(): int;
}
