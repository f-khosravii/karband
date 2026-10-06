<?php

declare(strict_types=1);
namespace Intl\ServiceRecipients;

final readonly class RecipientInput
{
    /** @param array<string,mixed> $profile */
    public function __construct(
        public RecipientKind $kind,
        public string $identityType,
        public string $identityValue,
        public array $profile,
    ) {
        if (trim($identityValue) === '' || trim($identityType) === '') {
            throw new \InvalidArgumentException('Identity is required.');
        }
        if (strlen($identityValue) > 512 || strlen($identityType) > 64) {
            throw new \InvalidArgumentException('Identity exceeds configured field size.');
        }
        self::assertJsonObject($profile);
    }

    /** @param array<string,mixed> $value */
    public static function assertJsonObject(array $value): void
    {
        if ($value !== [] && array_is_list($value)) {
            throw new \InvalidArgumentException('Profile must be a JSON object.');
        }
        try { json_encode((object) $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
        catch (\JsonException $e) { throw new \InvalidArgumentException('Profile is not valid JSON.', previous: $e); }
    }
}
