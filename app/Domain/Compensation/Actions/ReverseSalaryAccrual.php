<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\SalaryLedgerEntryType;
use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use App\Domain\Compensation\Exceptions\CompensationLedgerException;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Compensation\Services\SalaryLedgerStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Reverses one salary accrual (Plan §60; Phase 20G). Mirrors {@see ReverseCommissionEntry}: a
 * not-yet-paid accrual becomes an exact-negative `reversal` row referencing the original; an
 * already-paid accrual becomes a negative compensation_adjustments row (paid history preserved).
 * The original monetary/period fact is never edited. Idempotent per original.
 */
final class ReverseSalaryAccrual
{
    public function __construct(
        private readonly SalaryLedgerStateMachine $machine,
        private readonly RecordCompensationAdjustment $adjustments,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(SalaryLedgerEntry $original): Model
    {
        if ($original->entry_type !== SalaryLedgerEntryType::Accrual) {
            throw CompensationLedgerException::configurationInvariant('Only a salary accrual can be reversed.');
        }

        if ($original->status === SalaryLedgerStatus::Paid) {
            return $this->adjustments->paidSalaryReversal($original);
        }

        $existing = SalaryLedgerEntry::query()
            ->where('source_entry_id', $original->id)
            ->where('entry_type', SalaryLedgerEntryType::Reversal->value)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $reversal = SalaryLedgerEntry::create([
            'merchant_id' => $original->merchant_id,
            'branch_id' => $original->branch_id,
            'staff_profile_id' => $original->staff_profile_id,
            'compensation_plan_id' => $original->compensation_plan_id,
            'pay_period_start' => $original->pay_period_start->toDateString(),
            'pay_period_end' => $original->pay_period_end->toDateString(),
            'pay_period_segment_key' => 'reversal:'.$original->pay_period_segment_key,
            'amount_minor' => -$original->amount_minor,
            'currency' => $original->currency,
            'source_entry_id' => $original->id,
            'entry_type' => SalaryLedgerEntryType::Reversal->value,
            'status' => SalaryLedgerStatus::Pending->value,
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->machine->ensure($original->status, SalaryLedgerStatus::Reversed);
        $original->status = SalaryLedgerStatus::Reversed;
        $original->save();

        $this->audit->record(
            AuditEvent::CompensationSalaryReversed,
            null,
            $original->merchant_id,
            $original->branch_id,
            $reversal,
            [
                'salary_ledger_id' => $reversal->ulid,
                'source_entry_ulid' => $original->ulid,
                'entry_type' => SalaryLedgerEntryType::Reversal->value,
                'amount_minor' => -$original->amount_minor,
                'currency' => $original->currency,
            ],
        );

        return $reversal;
    }
}
