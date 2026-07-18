<?php

declare(strict_types=1);

namespace App\Domain\Compensation\ValueObjects;

/**
 * The outcome of accruing salary for one staff member's pay period (Plan §60; Phase 20G). A safe,
 * observable result so the scheduler can record a fail-closed/skip without leaking internals and
 * without corrupting other staff.
 */
final readonly class SalaryAccrualOutcome
{
    /**
     * @param  list<int>  $createdEntryIds  salary_ledger ids created by this run (empty on replay/skip)
     */
    private function __construct(
        public string $status,
        public array $createdEntryIds,
        public ?string $reason = null,
    ) {}

    /** @param list<int> $createdEntryIds */
    public static function accrued(array $createdEntryIds): self
    {
        return new self('accrued', $createdEntryIds);
    }

    /** The pay period is financially locked — no original accrual was inserted. */
    public static function skippedLocked(): self
    {
        return new self('skipped_period_locked', [], 'financial_period_locked');
    }

    /** A sub-monthly cadence with no approved attendance/shift source — failed closed. */
    public static function failClosedAttendance(string $cadence): self
    {
        return new self('fail_closed_attendance', [], "approved_attendance_source_required:{$cadence}");
    }

    /** Nothing payable for the period (commission-only, gap, pause, or no active plan). */
    public static function nothingPayable(): self
    {
        return new self('nothing_payable', []);
    }

    public function isAccrued(): bool
    {
        return $this->status === 'accrued';
    }
}
