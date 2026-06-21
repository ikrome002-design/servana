<?php

declare(strict_types=1);

namespace App\Domain\Audit\Contracts;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;

/**
 * Records audit events (Plan §70, ADR-008).
 *
 * Callers pass a canonical {@see AuditEvent} (never a free-form string); the
 * recorder derives the action name and severity from it and appends an
 * immutable, hash-chained row. Introduced in Phase 8 as a stable seam; R2
 * completes core event coverage, per-merchant/platform chains, and the verifier.
 */
interface AuditRecorder
{
    /**
     * Append an immutable, hash-chained audit record.
     *
     * @param  AuditEvent  $event  Canonical typed event (action + severity).
     * @param  User|null  $actor  The acting user, or null for public/pre-auth events.
     * @param  int|null  $merchantId  Owning merchant; null = platform/governance chain.
     * @param  int|null  $branchId  Owning branch for branch-scoped events; null otherwise.
     * @param  object|null  $subject  Optional polymorphic subject of the event.
     * @param  array<string, mixed>  $context  Non-secret event detail (masked at read time).
     */
    public function record(
        AuditEvent $event,
        ?User $actor = null,
        ?int $merchantId = null,
        ?int $branchId = null,
        ?object $subject = null,
        array $context = [],
    ): AuditLog;
}
