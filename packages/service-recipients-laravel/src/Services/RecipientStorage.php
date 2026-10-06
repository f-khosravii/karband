<?php

declare(strict_types=1);
namespace Intl\ServiceRecipientsLaravel\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Intl\ServiceRecipients\Identity;
use Intl\ServiceRecipients\RecipientKind;
use Intl\ServiceRecipients\RecipientSnapshot;

final readonly class RecipientStorage
{
    public function __construct(public Connection $connection) {}

    public function hmac(string $agencyId, RecipientKind $kind, Identity $identity): string
    {
        $configured = config('intl-service-recipients.identity_hmac_key');
        if (!is_string($configured) || $configured === '') {
            throw new \RuntimeException('Recipient identity key is not configured.');
        }
        if (str_starts_with($configured, 'base64:')) {
            $key = base64_decode(substr($configured, 7), true);
            if ($key === false) { throw new \RuntimeException('Invalid recipient HMAC key.'); }
        } else { $key = $configured; }
        return $identity->digest($kind, $agencyId, $key);
    }

    /** @param array<string,mixed> $data */
    public function encrypt(array $data): string
    {
        return Crypt::encryptString(json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    public function decrypt(string $value): array
    {
        $decoded = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) { throw new \UnexpectedValueException('Invalid stored profile.'); }
        return $decoded;
    }
    public function encryptIdentity(Identity $identity): string
    {
        return Crypt::encryptString($identity->normalized);
    }
    public function decryptIdentity(object $row): Identity
    {
        return new Identity((string)$row->identity_type, Crypt::decryptString((string)$row->identity_cipher));
    }
    public function snapshot(object $row): RecipientSnapshot
    {
        return new RecipientSnapshot((string) $row->id, (string) $row->agency_id,
            RecipientKind::from((string) $row->kind), $this->decryptIdentity($row),
            $this->decrypt((string) $row->profile_cipher), (string) $row->status, (int) $row->revision);
    }
}
