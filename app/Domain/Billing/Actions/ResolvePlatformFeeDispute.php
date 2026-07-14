<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PlatformFeeAdjustmentType;
use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Exceptions\PlatformFeeDisputeException;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Services\PlatformFeeDisputeStateMachine;
use App\Domain\Billing\Services\RecordPlatformFeeAdjustment;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resolve a platform-fee dispute (Plan §13.10 [Correction 3], §953; Phase 20E, Increment 5C;
 * `under_review → resolved`). A mandatory resolution note is required. The creator may not self-resolve
 * (maker/checker). A money-changing resolution creates an ADDITIVE `platform_fee_adjustments` row
 * (`adjustment_type='dispute_resolution'`) against the disputed ledger entry — it NEVER edits the
 * original ledger amount and NEVER rewrites the issued subscription invoice — and is blocked when the
 * target financial period is locked. An invalid transition raises `422 invalid_state_transition`.
 */
final class ResolvePlatformFeeDispute
{
    public function __construct(
        private readonly PlatformFeeDisputeStateMachine $machine,
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly RecordPlatformFeeAdjustment $adjustments,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        PlatformFeeDispute $dispute,
        User $resolver,
        string $resolutionNote,
        ?int $moneyChangeAmountMinor = null,
    ): PlatformFeeDispute {
        $resolutionNote = trim($resolutionNote);
        if ($resolutionNote === '') {
            throw PlatformFeeDisputeException::resolutionNoteRequired();
        }

        if ($dispute->created_by === $resolver->id) {
            throw PlatformFeeDisputeException::selfResolutionBlocked();
        }

        $changesMoney = $moneyChangeAmountMinor !== null && $moneyChangeAmountMinor !== 0;

        // Period-lock is enforced for a money-changing resolution (same guard as refunds/adjustments).
        if ($changesMoney) {
            $this->periodGuard->ensureOpen($dispute->merchant_id, $dispute->branch_id);
        }

        return DB::transaction(function () use ($dispute, $resolver, $resolutionNote, $moneyChangeAmountMinor, $changesMoney): PlatformFeeDispute {
            /** @var PlatformFeeDispute $locked */
            $locked = PlatformFeeDispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, PlatformFeeDisputeStatus::Resolved);

            $adjustmentUlid = null;
            if ($changesMoney) {
                if ($locked->platform_fee_ledger_entry_id === null) {
                    throw PlatformFeeDisputeException::moneyChangeRequiresLedgerEntry();
                }

                /** @var PlatformFeeLedgerEntry $entry */
                $entry = PlatformFeeLedgerEntry::query()
                    ->where('merchant_id', $locked->merchant_id)
                    ->whereKey($locked->platform_fee_ledger_entry_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $adjustment = $this->adjustments->record(
                    $entry,
                    PlatformFeeAdjustmentType::DisputeResolution,
                    (int) $moneyChangeAmountMinor,
                    $resolutionNote,
                    $locked->ulid,
                    'adjustment:dispute:'.$locked->id,
                    $resolver,
                    CarbonImmutable::now('Africa/Nairobi'),
                );
                $adjustmentUlid = $adjustment->ulid;
            }

            $locked->forceFill([
                'status' => PlatformFeeDisputeStatus::Resolved->value,
                'resolved_by' => $resolver->id,
                'resolved_at' => CarbonImmutable::now(),
                'resolution_note' => $resolutionNote,
            ])->save();

            $this->audit->record(AuditEvent::PlatformFeeDisputeResolved, $resolver, $locked->merchant_id, $locked->branch_id, $locked, [
                'dispute_id' => $locked->ulid,
                'changes_money' => $changesMoney,
                'adjustment_amount_minor' => $changesMoney ? (int) $moneyChangeAmountMinor : 0,
                'platform_fee_adjustment_id' => $adjustmentUlid,
            ]);

            return $locked;
        });
    }
}
