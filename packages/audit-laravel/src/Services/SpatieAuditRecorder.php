<?php

declare(strict_types=1);

namespace Intl\AuditLaravel\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Intl\AuditLaravel\Exceptions\AuditRecordDenied;
use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
use Intl\Contracts\Audit\AuditEvent;
use Intl\Contracts\Audit\AuditRecorderInterface;

/**
 * Deliberate synchronous audit events using Spatie as storage.
 * Business code MUST authorize the action independently; recording an event is not authorization.
 * For locally-atomic audit events, call inside the SAME database transaction/connection as the business write.
 */
final readonly class SpatieAuditRecorder implements AuditRecorderInterface
{
    public function __construct(private PrincipalProvider $principals)
    {
    }

    public function record(AuditEvent $event): void
    {
        $allowed = config('intl-audit.allowed_events', []);
        if (!is_array($allowed) || !in_array($event->code, $allowed, true)) {
            throw new AuditRecordDenied('Audit event is not registered.');
        }
        $logName = config('intl-audit.log_name');
        if (!is_string($logName) || !preg_match('/^[a-z][a-z0-9_.-]*$/D', $logName)) {
            throw new AuditRecordDenied('Invalid audit log name.');
        }

        try {
            // The host MUST bind PrincipalProvider from a verified Laravel guard/session.
            $principal = $this->principals->current();
            $loggedIn = Auth::guard($principal->guard)->user();
            $user = $principal->user;
            if (!$user instanceof Model || !$loggedIn instanceof Model ||
                $loggedIn::class !== $user::class ||
                (string) $loggedIn->getAuthIdentifier() !== (string) $user->getAuthIdentifier() ||
                (string) $user->getAuthIdentifier() !== $principal->actor->id ||
                !method_exists($user, 'hasRole') ||
                !$user->hasRole($principal->actor->role, $principal->guard)) {
                throw new AuditRecordDenied('Audit principal is untrusted.');
            }
        } catch (AuditRecordDenied $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new AuditRecordDenied('No trusted, authenticated audit principal.', 0, $exception);
        }

        // Fixed, audited property schema: never log request arrays, document data or external API responses.
        $properties = [
            'actor_role' => $principal->actor->role,
            'actor_organization_id' => $principal->actor->organizationId,
            'actor_guard' => $principal->guard,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'metadata' => $event->metadata,
        ];

        // Spatie's synchronous default is required. Do not enable activitylog buffering
        // or queue this write when audit + business change must roll back together.
        activity($logName)
            ->event($event->code)
            ->causedBy($user)
            ->withProperties($properties)
            ->log($event->code);
    }
}
