<?php

declare(strict_types=1);

namespace Intl\ExternalAuthLaravel\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Intl\ExternalAuth\ValueObjects\ExternalIdentity;
use Intl\ExternalAuth\ValueObjects\ExternalSessionStatus;

final readonly class ExternalSessionStorage
{
    public ConnectionInterface $connection;

    public function __construct(DatabaseManager $db)
    {
        $this->connection = $db->connection(config('intl-external-auth.connection'));
    }

    public function record(ExternalIdentity $identity): object
    {
        $now = now();

        $existing = $this->find($identity->provider, $identity->externalSessionId);
        $id = $existing !== null ? $existing->id : (string) Str::ulid();

        $this->connection->table('intl_external_sessions')->updateOrInsert(
            [
                'provider' => $identity->provider,
                'external_session_id' => $identity->externalSessionId,
            ],
            [
                'id' => $id,
                'subject_id' => $identity->subjectId,
                'organization_id' => $identity->organizationId,
                'expires_at' => $identity->expiresAt->format('Y-m-d H:i:s'),
                'revoked_at' => $identity->status === ExternalSessionStatus::Revoked ? $now : null,
                'created_at' => $existing !== null ? $existing->created_at : $now,
                'updated_at' => $now,
            ]
        );

        return $this->connection->table('intl_external_sessions')
            ->where('provider', $identity->provider)
            ->where('external_session_id', $identity->externalSessionId)
            ->first();
    }

    public function find(string $provider, string $sessionId): ?object
    {
        return $this->connection->table('intl_external_sessions')
            ->where('provider', $provider)
            ->where('external_session_id', $sessionId)
            ->first();
    }

    public function markRevoked(string $provider, string $sessionId): void
    {
        $this->connection->table('intl_external_sessions')
            ->where('provider', $provider)
            ->where('external_session_id', $sessionId)
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
