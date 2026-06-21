<?php

declare(strict_types=1);

namespace App\Domain\Auth\Support;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Support\AuditValueMasker;

/**
 * Auth-domain audit helper (Plan §9.1, §70; R2).
 *
 * Writes authentication events to the hash-chained `audit_logs` table via the
 * single {@see AuditRecorder} — this REPLACES the interim, log-only
 * AuthEventLogger; there is no second audit system.
 *
 * Auth events are recorded with a NULL actor and NULL merchant: most are
 * pre-auth, and even on success we never store a full email (the masked email is
 * the only identifier) so the audit trail can never be used to enumerate
 * accounts. Raw Magic Link tokens, session ids, and request bodies are never
 * passed in. The masked email + safe public user ULID are the only correlators.
 */
final class AuthAuditLogger
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function record(AuditEvent $event, ?string $email = null, ?string $reason = null, ?string $userUlid = null): void
    {
        $this->audit->record(
            $event,
            actor: null,
            merchantId: null,
            branchId: null,
            subject: null,
            context: array_filter([
                'email' => $email !== null ? AuditValueMasker::maskEmail($email) : null,
                'reason' => $reason,
                'user_ulid' => $userUlid,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }
}
