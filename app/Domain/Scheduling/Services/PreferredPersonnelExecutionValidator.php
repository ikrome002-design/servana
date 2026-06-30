<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Exceptions\SchedulingValidationException;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\ValueObjects\SchedulingDecision;

/**
 * Enforces the existing preferred-personnel request at service EXECUTION (Plan §37,
 * §80; Phase 16C). It does NOT duplicate eligibility/branch/lifecycle/availability
 * validation — that is the reused {@see QueuePersonnelAssignmentValidator} (which
 * delegates to the Phase 15B {@see PersonnelSchedulingValidator}). This validator
 * only resolves the preferred-personnel EXECUTION EVIDENCE for the session and
 * rejects a preferred request that was overridden without the recorded
 * authorisation.
 *
 * A preferred request never bypasses eligibility (eligibility is enforced by the
 * scheduling validator for whichever personnel member actually performs the
 * service). The override authority + reason were already required at queue-assign
 * time (16B, `preferred_personnel.select`); this re-asserts that a recorded reason
 * exists when the executing personnel differs from the preferred request. Phase 16C
 * calculates and stores NO preferred-personnel fee.
 */
final class PreferredPersonnelExecutionValidator
{
    public const CODE_OVERRIDE_REASON_REQUIRED = 'preferred_personnel_override_reason_required';

    /**
     * Resolve the immutable preferred-personnel execution evidence for a session
     * started from this queue entry by {@see $assigned}:
     *   - null  → no preferred request on the source;
     *   - true  → the preferred request was honoured;
     *   - false → an authorised override (a recorded override reason exists).
     *
     * @throws SchedulingValidationException when a preferred request was overridden
     *                                       without the recorded authorisation reason.
     */
    public function resolveHonored(QueueEntry $entry, StaffProfile $assigned): ?bool
    {
        $preferredId = $entry->preferred_personnel_staff_profile_id;

        if ($preferredId === null) {
            return null;
        }

        if ((int) $preferredId === (int) $assigned->id) {
            return true;
        }

        // Overridden to a different person — the override reason must already be
        // recorded on the queue entry (enforced at queue-assign time, 16B).
        $reason = $entry->preferred_personnel_override_reason;
        if ($reason === null || trim($reason) === '') {
            throw SchedulingValidationException::fromDecision(SchedulingDecision::deny(
                self::CODE_OVERRIDE_REASON_REQUIRED,
                'A preferred-personnel override requires a recorded reason.',
            ));
        }

        return false;
    }
}
