<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Exceptions;

use RuntimeException;

/**
 * A Phase 20G ledger-runtime invariant failure (Plan §60/§61). Distinct from the Phase 20F
 * `CompensationValidationException` (a 422 configuration error): these are internal financial
 * invariants raised during salary accrual or commission earning/consumption. They fail CLOSED —
 * no ledger row, no success audit — and (for the handoff consumer) leave the source event
 * retryable. Messages are generic and safe: no SQLSTATE, constraint name, internal id, or
 * private detail.
 */
final class CompensationLedgerException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** A sub-monthly cadence (daily/hourly/per_shift) has no approved attendance/shift source. */
    public static function attendanceSourceRequired(string $cadence): self
    {
        return new self(
            'approved_attendance_source_required',
            "Salary accrual for the '{$cadence}' cadence requires an approved attendance/shift source, which does not exist.",
        );
    }

    /** A configuration invariant is broken (ambiguous/corrupt) — never silently treated as ineligible. */
    public static function configurationInvariant(string $detail): self
    {
        return new self('compensation_configuration_invariant', $detail);
    }

    /** A reversal handoff was seen before its original earned fact was consumed — leave it retryable. */
    public static function originalNotYetEarned(): self
    {
        return new self(
            'commission_original_not_yet_earned',
            'The original earned commission for this reversal has not been recorded yet; the event remains retryable.',
        );
    }

    /**
     * The cumulative finalized refunds for a validated allocation exceed the amount that was validated —
     * an impossible source state (FinalizeRefund caps refunds at the recognised balance). Fail CLOSED:
     * a commission reversal must never exceed the exact-negative of what was earned (ADR-005; §61).
     */
    public static function cumulativeReversalExceedsValidatedAllocation(): self
    {
        return new self(
            'commission_cumulative_reversal_exceeds_allocation',
            'Cumulative finalized refunds exceed the validated allocation for this commission source; refusing to reverse more than was earned.',
        );
    }
}
