<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Exceptions\CashUpException;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Branches\Services\CashUpSnapshotWriter;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a branch-day cash-up DRAFT (Plan §45; Gate G/H; Phase 18B). The
 * Branch Manager enters per-method counted amounts; the expected amounts are ALWAYS
 * server-derived ({@see CashUpSnapshotWriter}) — client input never sets an expected
 * value. One cash-up per (branch, business_date). This is a same-state PUT, not a
 * transition: it only applies to a `draft` or `correction_requested` cash-up; a
 * submitted/approved/locked cash-up is never destructively overwritten.
 */
final class CreateOrUpdateCashUpDraft
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly CashUpSnapshotWriter $snapshot,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, int>  $counts  concrete method value => counted minor units
     */
    public function handle(MerchantBranch $branch, string $businessDate, array $counts, User $actor): BranchCashUp
    {
        $this->periodGuard->ensureOpen($branch->merchant_id, $branch->id, CarbonImmutable::parse($businessDate, 'Africa/Nairobi'));

        foreach (array_keys($counts) as $method) {
            $enum = PaymentMethod::tryFrom((string) $method);
            if ($enum === null || ! $enum->isConcreteComponentMethod()) {
                throw CashUpException::invalidMethod();
            }
        }

        return DB::transaction(function () use ($branch, $businessDate, $counts, $actor): BranchCashUp {
            $cashUp = BranchCashUp::query()
                ->where('branch_id', $branch->id)
                ->where('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if ($cashUp === null) {
                $cashUp = new BranchCashUp;
                $cashUp->forceFill([
                    'merchant_id' => $branch->merchant_id,
                    'branch_id' => $branch->id,
                    'business_date' => $businessDate,
                    'status' => CashUpStatus::Draft->value,
                ]);
                $cashUp->save();
            } elseif (! in_array($cashUp->status, [CashUpStatus::Draft, CashUpStatus::CorrectionRequested], true)) {
                throw CashUpException::notEditable();
            }

            $this->snapshot->rebuild($cashUp, $counts);

            $this->audit->record(AuditEvent::CashUpDraftUpdated, $actor, $cashUp->merchant_id, $cashUp->branch_id, $cashUp, [
                'cash_up_id' => $cashUp->ulid,
                'business_date' => $businessDate,
                'expected_minor' => $cashUp->expected_minor,
                'counted_minor' => $cashUp->counted_minor,
                'variance_minor' => $cashUp->variance_minor,
                'line_count' => $cashUp->lines->count(),
            ]);

            return $cashUp;
        });
    }
}
