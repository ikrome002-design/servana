<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Enums\PaymentValidationDecision;
use App\Domain\Payments\Exceptions\PaymentValidationException;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Payments\Services\PaymentMakerCheckerGuard;
use App\Domain\Payments\Services\PaymentRecordingGroupStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Return a WHOLE pending payment recording group for correction as the Finance checker
 * (Plan §42; Phase 18B). One atomic transaction: period gate → lock the group +
 * components → maker != checker → require pending_validation → one immutable
 * correction_required validation event (mandatory reason) → every component
 * correction_required → group correction_required → safe audit. The invoice is NEVER
 * touched; NO receipt is created. The maker later corrects and resubmits
 * ({@see ResubmitPaymentRecordingGroup}), returning the group to pending_validation.
 */
final class RequestPaymentGroupCorrection
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly PaymentMakerCheckerGuard $makerChecker,
        private readonly PaymentRecordingGroupStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PaymentRecordingGroup $group, User $checker, string $reason): PaymentValidationEvent
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw PaymentValidationException::reasonRequired();
        }

        $this->periodGuard->ensureOpen($group->merchant_id, $group->branch_id);

        return DB::transaction(function () use ($group, $checker, $reason): PaymentValidationEvent {
            /** @var PaymentRecordingGroup $locked */
            $locked = PaymentRecordingGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            $this->makerChecker->ensureNotMaker($locked, $checker->id);
            $this->machine->ensure($locked->status, PaymentRecordingGroupStatus::CorrectionRequired);

            $invoice = $locked->invoice()->firstOrFail();

            /** @var list<PaymentRecord> $components */
            $components = PaymentRecord::query()
                ->where('payment_recording_group_id', $locked->id)
                ->lockForUpdate()->get()->all();

            $event = PaymentValidationEvent::create([
                'merchant_id' => $locked->merchant_id,
                'branch_id' => $locked->branch_id,
                'payment_recording_group_id' => $locked->id,
                'invoice_id' => $locked->invoice_id,
                'checker_user_id' => $checker->id,
                'decision' => PaymentValidationDecision::CorrectionRequired,
                'validated_amount_minor' => null,
                'reason' => $reason,
            ]);

            foreach ($components as $component) {
                $component->forceFill(['status' => PaymentRecordStatus::CorrectionRequired->value])->save();
            }

            $locked->forceFill(['status' => PaymentRecordingGroupStatus::CorrectionRequired->value])->save();

            $this->audit->record(AuditEvent::CustomerPaymentCorrectionRequested, $checker, $locked->merchant_id, $locked->branch_id, $locked, [
                'group_id' => $locked->ulid,
                'invoice_id' => $invoice->ulid,
                'component_count' => count($components),
                'reason' => $reason,
                'new_state' => PaymentRecordingGroupStatus::CorrectionRequired->value,
            ]);

            return $event->setRelation('group', $locked)->setRelation('invoice', $invoice);
        });
    }
}
