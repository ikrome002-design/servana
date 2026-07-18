<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\CommissionLedgerEntryType;
use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Enums\CommissionReversalReason;
use App\Domain\Compensation\Exceptions\CompensationLedgerException;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Services\CommissionLedgerStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Reverses one earned commission entry (Plan §61; Phase 20G). The original monetary fact is NEVER
 * recomputed or edited:
 *  - a NOT-yet-paid original → a new append-only `reversal` row whose amount is the EXACT NEGATIVE
 *    of the stored original, referencing it via `source_entry_id`, with a canonical
 *    `reversal_reason`; the original transitions earned → reversed (status only).
 *  - an ALREADY-PAID original → a negative compensation_adjustments row (paid history is never
 *    rewritten; Plan §61) for a future Phase 20H payout.
 * Idempotent: the DB unique on (source_entry_id) WHERE entry_type='reversal' and the paid-adjustment
 * unique guarantee one financial effect per original.
 */
final class ReverseCommissionEntry
{
    public function __construct(
        private readonly CommissionLedgerStateMachine $machine,
        private readonly RecordCompensationAdjustment $adjustments,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(CommissionLedgerEntry $original, CommissionReversalReason $reason): Model
    {
        if ($original->entry_type !== CommissionLedgerEntryType::Earned) {
            throw CompensationLedgerException::configurationInvariant('Only an earned commission entry can be reversed.');
        }

        // Already paid → additive negative adjustment; never rewrite paid history.
        if ($original->status === CommissionLedgerStatus::Paid) {
            return $this->adjustments->paidCommissionReversal($original);
        }

        // Idempotent: an original is reversed at most once.
        $existing = CommissionLedgerEntry::query()
            ->where('source_entry_id', $original->id)
            ->where('entry_type', CommissionLedgerEntryType::Reversal->value)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $reversal = CommissionLedgerEntry::create([
            'merchant_id' => $original->merchant_id,
            'branch_id' => $original->branch_id,
            'staff_profile_id' => $original->staff_profile_id,
            'compensation_plan_id' => $original->compensation_plan_id,
            'commission_rule_id' => $original->commission_rule_id,
            'service_session_id' => $original->service_session_id,
            'invoice_id' => $original->invoice_id,
            'invoice_item_id' => $original->invoice_item_id,
            'payment_record_id' => $original->payment_record_id,
            'payment_validation_event_id' => $original->payment_validation_event_id,
            'source_entry_id' => $original->id,
            'entry_type' => CommissionLedgerEntryType::Reversal->value,
            'reversal_reason' => $reason->value,
            'calculation_basis_minor' => $original->calculation_basis_minor,
            'rate_basis_points' => $original->rate_basis_points,
            'fixed_rate_minor' => $original->fixed_rate_minor,
            'amount_minor' => -$original->amount_minor,
            'currency' => $original->currency,
            'earned_at' => null,
            'status' => CommissionLedgerStatus::Earned->value,
            'created_at' => CarbonImmutable::now(),
        ]);

        // Original transitions earned → reversed (status only; monetary columns immutable at the DB).
        $this->machine->ensure($original->status, CommissionLedgerStatus::Reversed);
        $original->status = CommissionLedgerStatus::Reversed;
        $original->save();

        $this->audit->record(
            AuditEvent::CompensationCommissionReversed,
            null,
            $original->merchant_id,
            $original->branch_id,
            $reversal,
            [
                'commission_ledger_id' => $reversal->ulid,
                'source_entry_ulid' => $original->ulid,
                'entry_type' => CommissionLedgerEntryType::Reversal->value,
                'reversal_reason' => $reason->value,
                'amount_minor' => -$original->amount_minor,
                'currency' => $original->currency,
            ],
        );

        return $reversal;
    }
}
