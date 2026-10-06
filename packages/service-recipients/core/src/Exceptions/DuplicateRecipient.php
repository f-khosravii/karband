<?php

declare(strict_types=1);
namespace Karband\ServiceRecipients\Exceptions;

/** This ID may only be returned AFTER verifying current agency ownership. */
final class DuplicateRecipient extends RecipientConflict
{
    public function __construct(public readonly string $existingId)
    {
        parent::__construct('Recipient already exists in the current agency.');
    }
}
