<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * Component payment_records lifecycle states (Plan §13.8, §41, §42, §44; Phases 18A,
 * 18B). Mirrors the payment_records.status DB CHECK.
 *
 * Phase 18A always creates a component at {@see PendingValidation} (the duplicate
 * hold is a GROUP-level state). Phase 18B transitions each component COHERENTLY with
 * its group (no component may diverge from the group's final decision):
 * validated/rejected/correction_required with the group decision; adjusted (partial
 * refund) or reversed (full refund) on finalized reversal.
 */
enum PaymentRecordStatus: string
{
    case PendingValidation = 'pending_validation';
    case Validated = 'validated';
    case Rejected = 'rejected';
    case CorrectionRequired = 'correction_required';
    case Reversed = 'reversed';
    case Adjusted = 'adjusted';

    /**
     * Authoritative component transition inventory (coherent with the group).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingValidation => [self::Validated, self::Rejected, self::CorrectionRequired],
            self::CorrectionRequired => [self::PendingValidation],
            self::Validated => [self::Adjusted, self::Reversed],
            // Adjusted (partially refunded) may be further adjusted or fully reversed.
            self::Adjusted => [self::Adjusted, self::Reversed],
            self::Rejected, self::Reversed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
