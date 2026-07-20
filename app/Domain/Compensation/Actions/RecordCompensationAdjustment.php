<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\CompensationAdjustmentType;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Models\StaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Creates append-only compensation_adjustments rows (Plan §60/§61; Phase 20G). Two sources:
 *  - a Finance MANUAL adjustment (additive; the API path in Increment 5 gates it with MFA + fresh
 *    step-up and records the high-severity audit);
 *  - a system NEGATIVE adjustment offsetting an ALREADY-PAID ledger row — Plan §61 forbids
 *    rewriting paid history, so a paid reversal becomes an adjustment for a future Phase 20H payout.
 * Paid-reversal creation is idempotent via the DB unique on the source ledger id. No payout run is
 * created here.
 */
final class RecordCompensationAdjustment
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function manual(
        StaffProfile $staff,
        int $branchId,
        int $amountMinor,
        string $currency,
        string $reason,
        ?User $createdBy,
        User $approvedBy,
    ): CompensationAdjustment {
        $adjustment = CompensationAdjustment::create([
            'merchant_id' => $staff->merchant_id,
            'branch_id' => $branchId,
            'staff_profile_id' => $staff->id,
            'adjustment_type' => CompensationAdjustmentType::Manual->value,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'reason' => $reason,
            'created_by' => $createdBy?->id,
            'approved_by' => $approvedBy->id,
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record(
            AuditEvent::CompensationAdjustmentCreated,
            $createdBy,
            $staff->merchant_id,
            $branchId,
            $adjustment,
            [
                'compensation_adjustment_id' => $adjustment->ulid,
                'staff_profile_id' => $staff->ulid,
                'adjustment_type' => CompensationAdjustmentType::Manual->value,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
            ],
        );

        return $adjustment;
    }

    /** Idempotent negative adjustment offsetting an already-paid commission entry. */
    public function paidCommissionReversal(CommissionLedgerEntry $original): CompensationAdjustment
    {
        $existing = CompensationAdjustment::query()
            ->where('source_commission_ledger_id', $original->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $adjustment = CompensationAdjustment::create([
            'merchant_id' => $original->merchant_id,
            'branch_id' => $original->branch_id,
            'staff_profile_id' => $original->staff_profile_id,
            'adjustment_type' => CompensationAdjustmentType::PaidCommissionReversal->value,
            'amount_minor' => -$original->amount_minor,
            'currency' => $original->currency,
            'reason' => 'Reversal of already-paid commission (paid history preserved).',
            'source_commission_ledger_id' => $original->id,
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record(
            AuditEvent::CompensationCommissionReversed,
            null,
            $original->merchant_id,
            $original->branch_id,
            $adjustment,
            [
                'compensation_adjustment_id' => $adjustment->ulid,
                'source_commission_ledger_id' => $original->ulid,
                'adjustment_type' => CompensationAdjustmentType::PaidCommissionReversal->value,
                'amount_minor' => -$original->amount_minor,
                'currency' => $original->currency,
            ],
        );

        return $adjustment;
    }

    /** Idempotent negative adjustment offsetting an already-paid salary accrual. */
    public function paidSalaryReversal(SalaryLedgerEntry $original): CompensationAdjustment
    {
        $existing = CompensationAdjustment::query()
            ->where('source_salary_ledger_id', $original->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $adjustment = CompensationAdjustment::create([
            'merchant_id' => $original->merchant_id,
            'branch_id' => $original->branch_id,
            'staff_profile_id' => $original->staff_profile_id,
            'adjustment_type' => CompensationAdjustmentType::PaidSalaryReversal->value,
            'amount_minor' => -$original->amount_minor,
            'currency' => $original->currency,
            'reason' => 'Reversal of already-paid salary (paid history preserved).',
            'source_salary_ledger_id' => $original->id,
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record(
            AuditEvent::CompensationSalaryReversed,
            null,
            $original->merchant_id,
            $original->branch_id,
            $adjustment,
            [
                'compensation_adjustment_id' => $adjustment->ulid,
                'source_salary_ledger_id' => $original->ulid,
                'adjustment_type' => CompensationAdjustmentType::PaidSalaryReversal->value,
                'amount_minor' => -$original->amount_minor,
                'currency' => $original->currency,
            ],
        );

        return $adjustment;
    }
}
