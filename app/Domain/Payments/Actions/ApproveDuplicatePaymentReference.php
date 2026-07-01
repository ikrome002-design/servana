<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Payments\Exceptions\PaymentRecordingException;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Domain\Payments\Services\NotifyFinanceOfRecordedPayment;
use App\Domain\Payments\Services\PaymentMakerCheckerGuard;
use App\Domain\Payments\Services\PaymentRecordingGroupStateMachine;
use App\Domain\Payments\ValueObjects\PaymentRecordingResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finance override of a suspected duplicate payment reference (Plan §41, Gate C;
 * Phase 18A). Route-gated by `customer_payment.duplicate_override` + MFA + a fresh
 * step-up + idempotency. Requires a non-empty sanitized reason. Enforces
 * maker/checker separation (the recording maker may not override their own group).
 * Writes durable `override_approved` evidence WITHOUT editing the original
 * reference, preserves the `duplicate_suspected` record, and — once every suspected
 * duplicate in the group is cleared — advances the held group
 * `recorded → pending_validation` and notifies Finance. Emits a high-severity audit
 * event. The override clears ONLY the duplicate hold; it cannot bypass currency,
 * overpayment, branch, tenant, invoice-state, billing, or period controls (all
 * enforced at recording time and by the route middleware).
 */
final class ApproveDuplicatePaymentReference
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PaymentMakerCheckerGuard $makerChecker,
        private readonly PaymentRecordingGroupStateMachine $machine,
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly NotifyFinanceOfRecordedPayment $notify,
    ) {}

    public function handle(PaymentReferenceCheck $check, User $actor, string $reason): PaymentReferenceCheck
    {
        if ($check->result !== PaymentReferenceCheckResult::DuplicateSuspected) {
            throw PaymentRecordingException::noDuplicateToOverride();
        }

        $sanitizedReason = $this->sanitize($reason);
        if ($sanitizedReason === '') {
            throw PaymentRecordingException::overrideReasonRequired();
        }

        $record = PaymentRecord::query()->with('invoice')->findOrFail($check->payment_record_id);
        $group = $record->group()->firstOrFail();

        // Maker/checker separation — the recording maker cannot override their group.
        $this->makerChecker->ensureNotMaker($group, (int) $actor->id);

        // Advancing a held group is a financial mutation — enforce period openness.
        $this->periodGuard->ensureOpen($group->merchant_id, $group->branch_id);

        [$override, $result] = DB::transaction(function () use ($check, $record, $group, $actor, $sanitizedReason): array {
            /** @var PaymentRecordingGroup $lockedGroup */
            $lockedGroup = PaymentRecordingGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            // Durable override evidence — original reference is never edited.
            $override = PaymentReferenceCheck::create([
                'merchant_id' => $record->merchant_id,
                'branch_id' => $record->branch_id,
                'payment_record_id' => $record->id,
                'method' => $record->method,
                'reference_normalized' => $record->reference_normalized,
                'result' => PaymentReferenceCheckResult::OverrideApproved,
                'matched_payment_record_id' => $check->matched_payment_record_id,
                'checked_at' => now(),
                'override_by' => $actor->id,
                'override_reason' => $sanitizedReason,
            ]);

            // Advance the group only when NO suspected duplicate remains unresolved.
            if ($this->unresolvedDuplicateCount($lockedGroup) === 0
                && $lockedGroup->status === PaymentRecordingGroupStatus::Recorded) {
                $this->machine->ensurePhase18a(PaymentRecordingGroupStatus::Recorded, PaymentRecordingGroupStatus::PendingValidation);
                $lockedGroup->status = PaymentRecordingGroupStatus::PendingValidation;
                $lockedGroup->submitted_for_validation_at = now();
                $lockedGroup->save();
            }

            $this->audit->record(
                AuditEvent::CustomerPaymentDuplicateOverrideApproved,
                $actor,
                $record->merchant_id,
                $record->branch_id,
                $lockedGroup,
                [
                    'group_id' => $lockedGroup->ulid,
                    'invoice_id' => $record->invoice?->ulid,
                    'invoice_number' => $record->invoice?->invoice_number,
                    'method' => $record->method->value,
                    'masked_reference' => $record->maskedReference(),
                    'override_reason' => $sanitizedReason,
                    'new_state' => $lockedGroup->status->value,
                ],
            );

            $held = $lockedGroup->status === PaymentRecordingGroupStatus::Recorded;

            return [$override, new PaymentRecordingResult($lockedGroup->load('records'), $held, [], 0, 0, 0)];
        });

        // After commit: if the group is now pending validation, notify Finance.
        if (! $result->held) {
            $this->notify->dispatch($result);
        }

        return $override;
    }

    private function unresolvedDuplicateCount(PaymentRecordingGroup $group): int
    {
        $suspected = PaymentReferenceCheck::query()
            ->where('merchant_id', $group->merchant_id)
            ->whereIn('payment_record_id', $group->records()->select('id'))
            ->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)
            ->pluck('payment_record_id')
            ->unique();

        $overridden = PaymentReferenceCheck::query()
            ->where('merchant_id', $group->merchant_id)
            ->whereIn('payment_record_id', $group->records()->select('id'))
            ->where('result', PaymentReferenceCheckResult::OverrideApproved->value)
            ->pluck('payment_record_id')
            ->unique();

        return $suspected->diff($overridden)->count();
    }

    private function sanitize(string $reason): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $reason)), 480, '');
    }
}
