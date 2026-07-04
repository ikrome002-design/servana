<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Services\PaymentRecordingGroupStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resubmit a corrected group back to pending_validation (Plan §42; Phase 18B). Only a
 * group in `correction_required` may be resubmitted, and only after an explicit
 * correction. One atomic transaction: period gate → lock the group + components →
 * require correction_required → group + components → pending_validation → safe audit.
 * No validation event is created (a resubmission is not a checker decision); it simply
 * returns the whole group to the Finance validation queue.
 */
final class ResubmitPaymentRecordingGroup
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly PaymentRecordingGroupStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PaymentRecordingGroup $group, User $actor): PaymentRecordingGroup
    {
        $this->periodGuard->ensureOpen($group->merchant_id, $group->branch_id);

        return DB::transaction(function () use ($group, $actor): PaymentRecordingGroup {
            /** @var PaymentRecordingGroup $locked */
            $locked = PaymentRecordingGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, PaymentRecordingGroupStatus::PendingValidation);

            /** @var list<PaymentRecord> $components */
            $components = PaymentRecord::query()
                ->where('payment_recording_group_id', $locked->id)
                ->lockForUpdate()->get()->all();

            foreach ($components as $component) {
                $component->forceFill(['status' => PaymentRecordStatus::PendingValidation->value])->save();
            }

            $locked->forceFill([
                'status' => PaymentRecordingGroupStatus::PendingValidation->value,
                'submitted_for_validation_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::CustomerPaymentResubmitted, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'group_id' => $locked->ulid,
                'invoice_id' => $locked->invoice()->firstOrFail()->ulid,
                'component_count' => count($components),
                'new_state' => PaymentRecordingGroupStatus::PendingValidation->value,
            ]);

            return $locked->load(['maker', 'invoice', 'records']);
        });
    }
}
