<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compensation\Actions\AccrueSalaryForPayPeriod;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Compensation\Services\CompensationBusinessDate;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 20G salary-accrual scheduler (Plan §60, §67; G8/G9/G12). Scheduled DAILY in Africa/Nairobi
 * (routes/console.php; `withoutOverlapping` singleton + `onOneServer` leader-only — the established
 * scheduler cadence). It ORCHESTRATES the {@see AccrueSalaryForPayPeriod} action only (no duplicated
 * proration/segmentation logic) and accrues, at the CLOSED pay-period boundary, the most recent fully
 * closed monthly/weekly period for every staff member on an active monthly/weekly salary plan.
 *
 * Cross-tenant scanning uses scope-free `DB::table` reads bounded to {@see self::BATCH}; each staff
 * member is then processed under its own merchant tenant context with the action's own subject lock +
 * idempotency (bounded per-item transactions, never one unbounded transaction). daily/hourly/per_shift
 * fail closed inside the action (no approved attendance source). A per-item failure emits ONE bounded,
 * redacted signal and the run exits non-zero; centralized paging/runbooks remain Phase 25.
 */
final class AccrueScheduledSalary extends Command
{
    protected $signature = 'compensation:accrue-salary {--date= : Override the Africa/Nairobi run date (YYYY-MM-DD) for closed-period selection}';

    protected $description = 'Accrue the most recent closed monthly/weekly salary pay period for active salary plans (Phase 20G).';

    private const BATCH = 500;

    public function handle(TenantContext $context, CompensationBusinessDate $businessDate, AccrueSalaryForPayPeriod $accrue): int
    {
        $now = $this->option('date') !== null
            ? CarbonImmutable::parse((string) $this->option('date'), CompensationBusinessDate::TIMEZONE)->startOfDay()
            : $businessDate->today();

        $failures = 0;

        // Scope-free scan of the distinct salaried subjects with a supported cadence.
        $subjects = DB::table('personnel_compensation_plans')
            ->where('status', 'active')
            ->whereNotNull('salary_amount_minor')
            ->whereIn('salary_period', [SalaryPeriod::Monthly->value, SalaryPeriod::Weekly->value])
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['merchant_id', 'branch_id', 'staff_profile_id', 'salary_period'])
            ->unique(fn (object $r): string => $r->merchant_id.':'.$r->branch_id.':'.$r->staff_profile_id.':'.$r->salary_period);

        foreach ($subjects as $subject) {
            $cadence = SalaryPeriod::from($subject->salary_period);
            $periodStart = $this->lastClosedPeriodStart($cadence, $now);

            $failures += $this->process($context, (int) $subject->merchant_id, function () use ($subject, $cadence, $periodStart, $accrue): void {
                $staff = StaffProfile::query()->whereKey($subject->staff_profile_id)->first();
                if ($staff !== null) {
                    $accrue->handle($staff, (int) $subject->branch_id, $cadence, $periodStart);
                }
            });
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** The start of the most recent FULLY CLOSED period of the cadence (never the open current one). */
    private function lastClosedPeriodStart(SalaryPeriod $cadence, CarbonImmutable $now): CarbonImmutable
    {
        return match ($cadence) {
            SalaryPeriod::Monthly => $now->startOfMonth()->subMonth(),
            SalaryPeriod::Weekly => $now->startOfWeek(CarbonImmutable::MONDAY)->subWeek(),
            default => $now, // unreachable: only monthly/weekly are scanned.
        };
    }

    /**
     * Run one subject under its merchant tenant context, isolating failures into a bounded redacted
     * signal so one bad row never aborts the batch. Returns 1 on failure, 0 on success.
     */
    private function process(TenantContext $context, int $merchantId, \Closure $work): int
    {
        try {
            $merchant = Merchant::query()->whereKey($merchantId)->first();
            if ($merchant === null) {
                return 0;
            }
            $context->bindForJob($merchant);
            $work();

            return 0;
        } catch (\Throwable $e) {
            Log::warning('compensation.salary_accrual.item_failed', [
                'merchant_id' => $merchantId,
                'exception' => $e::class,
            ]);

            return 1;
        } finally {
            $context->reset();
        }
    }
}
