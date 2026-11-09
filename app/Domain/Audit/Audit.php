<?php

namespace App\Domain\Audit;

use App\Domain\Accounts\TenantContext;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for state transitions and security-sensitive changes.
 * Metadata is redacted before storage; never pass raw secrets anyway.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?User $actor = null,
        ?string $accountId = null,
    ): AuditEvent {
        $context = app(TenantContext::class);

        $event = new AuditEvent;
        $event->forceFill([
            'account_id' => $accountId ?? ($context->has() ? $context->accountId() : null),
            'actor_user_id' => $actor->id ?? $context->user()?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'metadata' => $metadata === [] ? null : Redactor::redact($metadata),
        ])->save();

        return $event;
    }
}
