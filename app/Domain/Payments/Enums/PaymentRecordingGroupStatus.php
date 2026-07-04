<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use App\Domain\Payments\Services\PaymentRecordingGroupStateMachine;

/**
 * Payment recording group lifecycle states (Plan §13.15, §25, §41; Phase 18A).
 * Mirrors the payment_recording_groups.status DB CHECK.
 *
 * Status is never assigned directly; every change goes through a named domain
 * action via {@see PaymentRecordingGroupStateMachine}.
 * Phase 18A reaches only the recording-owned states ({@see Recorded},
 * {@see PendingValidation}); {@see Validated}/{@see Rejected}/
 * {@see CorrectionRequired}/{@see Reversed} are defined + unit-tested here but are
 * Phase-18B-driven (no Phase 18A route reaches them).
 */
enum PaymentRecordingGroupStatus: string
{
    case Draft = 'draft';
    case Recorded = 'recorded';
    case PendingValidation = 'pending_validation';
    case Validated = 'validated';
    case Rejected = 'rejected';
    case CorrectionRequired = 'correction_required';
    case Reversed = 'reversed';

    /**
     * Statuses that reserve capacity against the invoice balance (non-terminal,
     * not-yet-validated). Used by the pending-total calculation under the invoice
     * row lock.
     *
     * @return list<self>
     */
    public static function activePendingStatuses(): array
    {
        return [self::Recorded, self::PendingValidation];
    }

    /**
     * Authoritative transition inventory (Plan §25). Phase 18A owns
     * `recorded → pending_validation`; the rest are Phase 18B.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Recorded],
            self::Recorded => [self::PendingValidation],
            self::PendingValidation => [self::Validated, self::Rejected, self::CorrectionRequired],
            // Phase 18B: an explicitly corrected group is resubmitted for validation.
            self::CorrectionRequired => [self::PendingValidation],
            self::Validated => [self::Reversed],
            self::Rejected, self::Reversed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Transitions Phase 18A production actions may perform (defence in depth over the full machine). */
    public function isPhase18aTransition(self $next): bool
    {
        return ($this === self::Draft && $next === self::Recorded)
            || ($this === self::Recorded && $next === self::PendingValidation);
    }

    /**
     * @param  list<self>  $statuses
     * @return list<string>
     */
    public static function values(array $statuses): array
    {
        return array_map(static fn (self $s): string => $s->value, $statuses);
    }
}
