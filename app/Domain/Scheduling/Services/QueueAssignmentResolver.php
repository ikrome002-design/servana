<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Exceptions\SchedulingValidationException;
use App\Domain\Scheduling\Models\QueueEntry;

/**
 * Resolves which personnel member (if any) to assign to a queue entry for a given
 * assignment mode (Plan §37; Phase 16B). Shared by walk-in creation, appointment
 * conversion, and the assign action — so the mode rules live in ONE place.
 *
 *   - next_available      → the deterministic selector (may return null → waiting).
 *   - manual              → the explicit target, hard-validated (throws on invalid).
 *   - preferred_personnel → the preferred member; if currently UNAVAILABLE the entry
 *                           stays waiting (the preference is recorded), but a
 *                           genuinely ineligible/wrong-branch/inactive preferred
 *                           selection is a hard error.
 *
 * It never bypasses HR eligibility/availability — every candidate is gated by
 * {@see QueuePersonnelAssignmentValidator} (which reuses the Phase 15B services).
 */
final class QueueAssignmentResolver
{
    public function __construct(
        private readonly NextAvailablePersonnelSelector $selector,
        private readonly QueuePersonnelAssignmentValidator $validator,
    ) {}

    /**
     * @throws SchedulingValidationException
     */
    public function resolve(
        QueueEntry $entry,
        QueueAssignmentMode $mode,
        ?StaffProfile $target,
        ?StaffProfile $preferred,
    ): ?StaffProfile {
        return match ($mode) {
            QueueAssignmentMode::NextAvailable => $this->selector->select($entry),
            QueueAssignmentMode::Manual => $this->resolveManual($entry, $target),
            QueueAssignmentMode::PreferredPersonnel => $this->resolvePreferred($entry, $preferred),
        };
    }

    /** @throws SchedulingValidationException */
    private function resolveManual(QueueEntry $entry, ?StaffProfile $target): StaffProfile
    {
        if ($target === null) {
            throw new SchedulingValidationException('personnel_not_eligible', 'A personnel member must be selected for manual assignment.');
        }

        $this->validator->ensure($entry, $target);

        return $target;
    }

    /**
     * Preferred: assign if available now; otherwise stay waiting (preference kept).
     * An ineligible/wrong-branch/inactive preferred selection is a hard error.
     *
     * @throws SchedulingValidationException
     */
    private function resolvePreferred(QueueEntry $entry, ?StaffProfile $preferred): ?StaffProfile
    {
        if ($preferred === null) {
            throw new SchedulingValidationException('personnel_not_eligible', 'A preferred personnel member must be selected.');
        }

        $decision = $this->validator->validate($entry, $preferred);

        if ($decision->allowed) {
            return $preferred;
        }

        // A transient availability/busy denial leaves the entry waiting with the
        // preference recorded; anything else is a genuine selection error.
        if (in_array($decision->code, [QueuePersonnelAssignmentValidator::CODE_BUSY, PersonnelSchedulingValidator::CODE_UNAVAILABLE], true)) {
            return null;
        }

        throw SchedulingValidationException::fromDecision($decision);
    }
}
