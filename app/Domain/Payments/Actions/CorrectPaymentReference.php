<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Exceptions\PaymentValidationException;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Services\PaymentMakerCheckerGuard;
use App\Domain\Payments\Services\PaymentMethodReferenceValidator;
use App\Domain\Payments\Services\PaymentReferenceDuplicateChecker;
use App\Domain\Payments\Services\PaymentReferenceNormalizer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Correct a component's payment reference on a correctable group (Plan §42; Phase 18B).
 * Only a group in `correction_required` may have a reference corrected. One atomic
 * transaction: period gate → lock the group + component → maker != checker → method-aware
 * validation of the NEW reference → update the component's normalized + encrypted display
 * value → a NEW durable `payment_reference_checks` result (the ORIGINAL reference-check
 * evidence rows are append-only and preserved) → safe before/after MASKED audit. The
 * full/normalized reference is never surfaced. After correcting, the maker resubmits the
 * whole group ({@see ResubmitPaymentRecordingGroup}).
 */
final class CorrectPaymentReference
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly PaymentMakerCheckerGuard $makerChecker,
        private readonly PaymentMethodReferenceValidator $methodReference,
        private readonly PaymentReferenceNormalizer $normalizer,
        private readonly PaymentReferenceDuplicateChecker $duplicates,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PaymentRecord $record, User $actor, string $newReference): PaymentRecord
    {
        $group = $record->group;
        $this->periodGuard->ensureOpen($record->merchant_id, $record->branch_id);

        return DB::transaction(function () use ($record, $actor, $newReference): PaymentRecord {
            /** @var PaymentRecord $locked */
            $locked = PaymentRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentRecordingGroup $group */
            $group = $locked->group()->lockForUpdate()->firstOrFail();

            // Only a correctable group may have its references corrected.
            if ($group->status !== PaymentRecordingGroupStatus::CorrectionRequired) {
                throw PaymentValidationException::notCorrectable();
            }

            $this->makerChecker->ensureNotMaker($group, $actor->id);

            // Method-aware validation of the NEW reference (cash has no reference).
            $this->methodReference->validate($locked->method, $newReference);

            $maskedBefore = $locked->maskedReference();

            // Update the component to the new reference (the original check-evidence rows
            // remain — this is not a silent overwrite; the change is audited masked).
            $locked->forceFill([
                'reference_normalized' => $this->normalizer->normalize($locked->method, $newReference),
                'reference_display_encrypted' => $this->normalizer->display($newReference),
            ])->save();

            // A NEW durable payment_reference_checks result for the corrected reference.
            if ($locked->method->runsDuplicateCheck()) {
                $this->duplicates->check($locked);
            }

            $maskedAfter = $locked->maskedReference();

            $this->audit->record(AuditEvent::CustomerPaymentReferenceCorrected, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'group_id' => $group->ulid,
                'payment_record_id' => $locked->ulid,
                'method' => $locked->method->value,
                'reference_masked_before' => $maskedBefore,
                'reference_masked_after' => $maskedAfter,
            ]);

            return $locked;
        });
    }
}
