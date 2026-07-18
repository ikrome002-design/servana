<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Compensation\Enums\SuspensionSalaryPolicy;
use App\Domain\Compensation\Exceptions\CompensationLedgerException;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\ValueObjects\SalarySegment;
use Carbon\CarbonImmutable;

/**
 * Splits one pay period into payable salary segments (Plan §60; G8/G10). PURE domain service —
 * it reads no database and writes nothing; the accrual action supplies the active plan versions.
 *
 * The pay period is half-open [periodStart, periodEndExclusive) in Africa/Nairobi. Each calendar
 * day is assigned to the ONE active plan version covering it (the DB EXCLUDE guarantees at most
 * one active plan per staff/branch per day). A day is PAYABLE only when a plan covers it, the plan
 * carries salary, and its `suspension_salary_policy` is `continue`; a `pause` version window and a
 * configuration gap accrue nothing (never rewritten). Contiguous payable days under the same plan
 * become one segment. Suspension/resumption/termination are expressed as plan VERSIONS (G10
 * supersede-not-edit): a `pause` version = suspension; a later `continue` version = resumption;
 * the lineage ending (no covering plan) = termination.
 *
 * A daily/hourly/per_shift covering plan FAILS CLOSED (no approved attendance/shift source exists,
 * G9) via {@see CompensationLedgerException::attendanceSourceRequired()} — never inferred hours,
 * never a zero-value row.
 */
final class SalarySegmenter
{
    /**
     * @param  iterable<PersonnelCompensationPlan>  $activePlans  ACTIVE plans overlapping the period
     * @return list<SalarySegment> payable segments (empty when the whole period is gap/pause/no-salary)
     *
     * @throws CompensationLedgerException when a covering plan uses a sub-monthly cadence
     */
    public function segment(
        iterable $activePlans,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEndExclusive,
        int $denominator,
    ): array {
        $plans = [];
        foreach ($activePlans as $plan) {
            $plans[] = $plan;
        }

        $periodStart = $periodStart->startOfDay();
        $periodEndExclusive = $periodEndExclusive->startOfDay();

        /** @var list<array{plan: PersonnelCompensationPlan, start: CarbonImmutable}> $payableDays */
        $payableDays = [];

        for ($day = $periodStart; $day->lessThan($periodEndExclusive); $day = $day->addDay()) {
            $plan = $this->planCovering($plans, $day);
            if ($plan === null) {
                continue; // configuration gap or after termination — not payable.
            }

            if (in_array($plan->salary_period, [SalaryPeriod::Daily, SalaryPeriod::Hourly, SalaryPeriod::PerShift], true)) {
                throw CompensationLedgerException::attendanceSourceRequired($plan->salary_period->value);
            }

            if (! $plan->compensation_model->requiresSalary() || $plan->salary_amount_minor === null) {
                continue; // commission_only — no salary accrues.
            }

            if ($plan->suspension_salary_policy === SuspensionSalaryPolicy::Pause) {
                continue; // suspended with the prospective pause override — not payable.
            }

            $payableDays[] = ['plan' => $plan, 'start' => $day];
        }

        return $this->coalesce($payableDays, $periodStart, $periodEndExclusive, $denominator);
    }

    /**
     * @param  list<PersonnelCompensationPlan>  $plans
     */
    private function planCovering(array $plans, CarbonImmutable $day): ?PersonnelCompensationPlan
    {
        foreach ($plans as $plan) {
            $from = CarbonImmutable::parse($plan->effective_from->toDateString(), 'Africa/Nairobi')->startOfDay();
            if ($day->lessThan($from)) {
                continue;
            }
            if ($plan->effective_to !== null) {
                $to = CarbonImmutable::parse($plan->effective_to->toDateString(), 'Africa/Nairobi')->startOfDay();
                if ($day->greaterThanOrEqualTo($to)) {
                    continue; // half-open [from, to): a plan ending ON `to` no longer covers it.
                }
            }

            return $plan;
        }

        return null;
    }

    /**
     * Coalesce contiguous payable days under the same plan into segments.
     *
     * @param  list<array{plan: PersonnelCompensationPlan, start: CarbonImmutable}>  $payableDays
     * @return list<SalarySegment>
     */
    private function coalesce(array $payableDays, CarbonImmutable $periodStart, CarbonImmutable $periodEndExclusive, int $denominator): array
    {
        $segments = [];
        $count = count($payableDays);
        $i = 0;

        while ($i < $count) {
            $plan = $payableDays[$i]['plan'];
            $segStart = $payableDays[$i]['start'];
            $days = 1;
            $segLastDay = $segStart;

            // Extend while the next day is contiguous AND under the same plan.
            while ($i + 1 < $count
                && $payableDays[$i + 1]['plan']->id === $plan->id
                && $payableDays[$i + 1]['start']->equalTo($segLastDay->addDay())) {
                $i++;
                $days++;
                $segLastDay = $payableDays[$i]['start'];
            }

            $segEndExclusive = $segLastDay->addDay();

            $segments[] = new SalarySegment(
                segmentKey: $this->segmentKey($plan->id, $periodStart, $periodEndExclusive, $segStart, $segEndExclusive),
                compensationPlanId: $plan->id,
                compensationPlanUlid: $plan->ulid,
                salaryMinor: (int) $plan->salary_amount_minor,
                currency: (string) $plan->salary_currency,
                payableDays: $days,
                denominator: $denominator,
                payableStart: $segStart,
                payableEnd: $segLastDay,
            );

            $i++;
        }

        return $segments;
    }

    /**
     * Deterministic, collision-resistant segment key encoding the plan + period + segment
     * boundaries (§6.4). Distinguishes every segment within one (plan, staff, period).
     */
    private function segmentKey(int $planId, CarbonImmutable $periodStart, CarbonImmutable $periodEndExclusive, CarbonImmutable $segStart, CarbonImmutable $segEndExclusive): string
    {
        return sprintf(
            'accrual:p%d:%s..%s#seg:%s..%s',
            $planId,
            $periodStart->toDateString(),
            $periodEndExclusive->toDateString(),
            $segStart->toDateString(),
            $segEndExclusive->toDateString(),
        );
    }
}
