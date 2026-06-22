<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mfa;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Models\User;

/**
 * Records MFA / step-up audit events on the canonical R2 chain (Plan §70, §18).
 *
 * MFA is identity-level, so events are written to the platform/governance chain
 * (null merchant) with the acting user as actor. The ONLY context is the safe
 * public user ULID — never a TOTP secret, code, recovery code, session id, or
 * request payload (Plan §9 rule 13).
 */
final class MfaAuditLogger
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $extra  Additional NON-secret context.
     */
    public function record(AuditEvent $event, User $user, array $extra = []): void
    {
        $this->audit->record(
            $event,
            actor: $user,
            merchantId: null,
            branchId: null,
            subject: null,
            context: array_merge(['user_ulid' => $user->ulid], $extra),
        );
    }
}
