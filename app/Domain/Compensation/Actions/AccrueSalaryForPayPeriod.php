<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Enums\SalaryLedgerEntryType;
use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Compensation\Exceptions\CompensationLedgerException;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Compensation\Services\SalaryProrationCalculator;
use App\Domain\Compensation\Services\SalarySegmenter;
use App\Domain\Compensation\ValueObjects\SalaryAccrualOutcome;
use App\Domain\FinanceOps\Exceptions\FinancialPeriodLockedException;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Hr\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accrue salary for ONE staff member's pay period (Plan §60; Phase 20G). Orchestrates the pure
 * segmenter + proration calculator + the append-only salary_ledger; it does NOT compute money
 * itself. Idempotent, period-lock-aware, and safe under concurrency.
 *
 * Cadence & cutoff (documented, repository-supported): accrual happens at the CLOSED pay-period
 * boundary — the scheduler only ever passes a period whose exclusive end has already arrived in
 * Africa/Nairobi, so no future day is accrued and no provisional row is created (§6.3). Monthly and
 * weekly are supported; daily/hourly/per_shift fail closed (no approved attendance source, G9).
 *
 * Lock order (§6.5): staff profile (subject) → existing salary-ledger identity rows → insert →
 * audit, all inside one transaction. The DB unique
 * (compensation_plan_id, staff_profile_id, pay_period_segment_key, entry_type='accrual') is the
 * authoritative idempotency guard; the subject row lock serializes concurrent scheduler runs so
 * they converge on one result rather than racing on the unique.
 */
final class AccrueSalaryForPayPeriod
{
    public function __construct(
        private readonly SalarySegmenter $segmenter,
        private readonly SalaryProrationCalculator $calculator,
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(StaffProfile $staff, int $branchId, SalaryPeriod $cadence, CarbonImmutable $periodStart): SalaryAccrualOutcome
    {
        // Sub-monthly cadences have no approved attendance/shift source (G9) — fail closed before
        // touching the ledger, never inferring hours.
        if (in_array($cadence, [SalaryPeriod::Daily, SalaryPeriod::Hourly, SalaryPeriod::PerShift], true)) {
            return SalaryAccrualOutcome::failClosedAttendance($cadence->value);
        }

        $periodStart = CarbonImmutable::parse($periodStart->toDateString(), 'Africa/Nairobi')->startOfDay();
        [$periodEndExclusive, $denominator] = $this->periodBounds($cadence, $periodStart);

        $plans = PersonnelCompensationPlan::query()
            ->where('merchant_id', $staff->merchant_id)
            ->where('branch_id', $branchId)
            ->where('staff_profile_id', $staff->id)
            ->where('status', CompensationPlanStatus::Active)
            ->whereDate('effective_from', '<', $periodEndExclusive->toDateString())
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>', $periodStart->toDateString());
            })
            ->orderBy('effective_from')->orderBy('id')
            ->get();

        try {
            $segments = $this->segmenter->segment($plans, $periodStart, $periodEndExclusive, $denominator);
        } catch (CompensationLedgerException $e) {
            if ($e->errorCode() === 'approved_attendance_source_required') {
                return SalaryAccrualOutcome::failClosedAttendance($cadence->value);
            }
            throw $e;
        }

        if ($segments === []) {
            return SalaryAccrualOutcome::nothingPayable();
        }

        // Period-lock precondition: a locked period accepts no NEW original accrual (correction is
        // an additive adjustment/reversal, never a silent insert).
        try {
            $this->periodGuard->ensureOpen($staff->merchant_id, $branchId, $periodEndExclusive->subDay());
        } catch (FinancialPeriodLockedException) {
            return SalaryAccrualOutcome::skippedLocked();
        }

        $amounts = $this->calculator->allocate($segments);

        return DB::transaction(function () use ($staff, $branchId, $segments, $amounts): SalaryAccrualOutcome {
            // 1) Subject lock — serializes concurrent scheduler runs for this staff member.
            StaffProfile::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();

            $keys = array_map(static fn ($s): string => $s->segmentKey, $segments);
            // 2) Existing identity rows for these segments (idempotent replay).
            $existing = SalaryLedgerEntry::query()
                ->where('staff_profile_id', $staff->id)
                ->where('entry_type', SalaryLedgerEntryType::Accrual->value)
                ->whereIn('pay_period_segment_key', $keys)
                ->pluck('pay_period_segment_key')
                ->all();

            $created = [];
            foreach ($segments as $segment) {
                if (in_array($segment->segmentKey, $existing, true)) {
                    continue;
                }

                // 3) Insert the append-only accrual fact.
                $row = SalaryLedgerEntry::create([
                    'merchant_id' => $staff->merchant_id,
                    'branch_id' => $branchId,
                    'staff_profile_id' => $staff->id,
                    'compensation_plan_id' => $segment->compensationPlanId,
                    'pay_period_start' => $segment->payableStart->toDateString(),
                    'pay_period_end' => $segment->payableEnd->toDateString(),
                    'pay_period_segment_key' => $segment->segmentKey,
                    'amount_minor' => $amounts[$segment->segmentKey],
                    'currency' => $segment->currency,
                    'entry_type' => SalaryLedgerEntryType::Accrual->value,
                    'status' => SalaryLedgerStatus::Pending->value,
                    'created_at' => CarbonImmutable::now(),
                ]);

                // 4) Audit — safe context (public ULIDs, integer amount, boundaries; no internal ids).
                $this->audit->record(
                    AuditEvent::CompensationSalaryAccrued,
                    null,
                    $staff->merchant_id,
                    $branchId,
                    $row,
                    [
                        'salary_ledger_id' => $row->ulid,
                        'staff_profile_id' => $staff->ulid,
                        'compensation_plan_ulid' => $segment->compensationPlanUlid,
                        'entry_type' => SalaryLedgerEntryType::Accrual->value,
                        'amount_minor' => $amounts[$segment->segmentKey],
                        'currency' => $segment->currency,
                        'pay_period_start' => $segment->payableStart->toDateString(),
                        'pay_period_end' => $segment->payableEnd->toDateString(),
                        'payable_calendar_days' => $segment->payableDays,
                        'period_calendar_days' => $segment->denominator,
                    ],
                );

                $created[] = $row->id;
            }

            return SalaryAccrualOutcome::accrued($created);
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: int} [periodEndExclusive, denominator]
     */
    private function periodBounds(SalaryPeriod $cadence, CarbonImmutable $periodStart): array
    {
        return match ($cadence) {
            SalaryPeriod::Monthly => [$periodStart->addMonth()->startOfMonth(), $periodStart->daysInMonth],
            SalaryPeriod::Weekly => [$periodStart->addDays(7), 7],
            default => throw CompensationLedgerException::attendanceSourceRequired($cadence->value),
        };
    }
}
