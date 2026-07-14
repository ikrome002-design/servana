<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\PlatformFeeAdjustmentType;
use App\Domain\Billing\Enums\PlatformFeeEntryType;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Models\PlatformFeeAdjustment;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records an ADDITIVE percentage platform-fee correction over an original earned ledger entry (Plan
 * §13.10, §51, §953; Phase 20E, Increment 5B). This is the single writer behind the void/refund/
 * correction/dispute hooks: it NEVER edits the original earned amount. Each call, inside the owning
 * financial transaction (which already enforces the Phase 18 period lock + maker/checker), writes two
 * append-only facts and a non-monetary status marker:
 *
 *   1. a new `platform_fee_ledger_entries` row — `entry_type='reversal'` for a full reversal, else
 *      `'adjustment'`; `reversed_entry_id` = the original; the magnitude split by the original tier
 *      snapshot; `status='pending'` so it is eligible for the NEXT authorized billing cycle;
 *   2. a `platform_fee_adjustments` row carrying the SIGNED amount, reason, source reference, actor,
 *      and business date (the correction evidence, immutable after insert);
 *   3. the original row's `status` marker → `reversed` (reversal) / `adjusted` (other) when it is still
 *      on a billing status — the remaining reversible balance, not the marker, gates further corrections.
 *
 * Sign rules (mirroring the DB CHECKs): `reversal`/`partial_refund` amounts are negative; a negative
 * amount may never exceed the remaining reversible balance (else 409 over-reversal). Currency always
 * equals the original's. Idempotent per source correction event (a partial-unique key on both tables +
 * an application pre-check): a replay returns the existing adjustment and writes nothing new.
 */
final class RecordPlatformFeeAdjustment
{
    public function __construct(
        private readonly CalculatePlatformFee $calculator,
        private readonly PlatformFeeLedgerEntryStateMachine $ledgerMachine,
        private readonly AuditRecorder $audit,
    ) {}

    public function record(
        PlatformFeeLedgerEntry $original,
        PlatformFeeAdjustmentType $type,
        int $signedAmountMinor,
        string $reason,
        ?string $sourceReference,
        string $idempotencyKey,
        User $actor,
        CarbonImmutable $businessDate,
    ): PlatformFeeAdjustment {
        $this->assertInTransaction();

        // Idempotency — a source correction event may create its correction at most once.
        $existing = PlatformFeeAdjustment::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($signedAmountMinor === 0) {
            throw PlatformFeeException::invalidCorrectionSign($type->value);
        }

        // Sign coherence with the adjustment type (mirrors the DB CHECK; fails before any write).
        $mustBeNegative = in_array($type, [PlatformFeeAdjustmentType::Reversal, PlatformFeeAdjustmentType::PartialRefund], true);
        if ($mustBeNegative && $signedAmountMinor > 0) {
            throw PlatformFeeException::invalidCorrectionSign($type->value);
        }

        // A reduction (negative amount) may not exceed the remaining reversible balance.
        $remaining = $this->remainingReversible($original);
        if ($signedAmountMinor < 0 && -$signedAmountMinor > $remaining) {
            throw PlatformFeeException::overReversal(-$signedAmountMinor, $remaining);
        }

        $magnitude = abs($signedAmountMinor);
        $tier = $original->service_fee_tier_snapshot;
        $split = $original->shared_split_snapshot;
        $currency = $original->currency;

        // The additive ledger fact — magnitude split by the ORIGINAL tier snapshot (keeps
        // client_shifted + absorbed = gross and liability = gross).
        $fee = $this->calculator->splitByTier($magnitude, $tier, $split, $currency);

        $ledgerType = $type === PlatformFeeAdjustmentType::Reversal
            ? PlatformFeeEntryType::Reversal
            : PlatformFeeEntryType::Adjustment;

        $correctionEntry = PlatformFeeLedgerEntry::create([
            'merchant_id' => $original->merchant_id,
            'branch_id' => $original->branch_id,
            'source_invoice_id' => $original->source_invoice_id,
            'source_invoice_item_id' => $original->source_invoice_item_id,
            'entry_type' => $ledgerType->value,
            'status' => PlatformFeeLedgerStatus::Pending->value,
            'billing_mode_snapshot' => $original->billing_mode_snapshot->value,
            'service_fee_tier_snapshot' => $fee->tier->value,
            'fee_basis_type' => $original->fee_basis_type->value,
            'fee_basis_amount_minor' => $magnitude,
            'percentage_rate_snapshot' => $original->percentage_rate_snapshot,
            'shared_split_snapshot' => $split,
            'gross_platform_fee_minor' => $fee->grossMinor,
            'client_shifted_amount_minor' => $fee->clientShiftedMinor,
            'merchant_absorbed_amount_minor' => $fee->merchantAbsorbedMinor,
            'merchant_liability_minor' => $fee->merchantLiabilityMinor,
            'currency' => $currency,
            'effective_configuration_id' => $original->effective_configuration_id,
            'reversed_entry_id' => $original->id,
            'source_validation_event_id' => null,
            'idempotency_key' => 'ledger:'.$idempotencyKey,
            'billable_at' => $businessDate,
        ]);

        $adjustment = PlatformFeeAdjustment::create([
            'merchant_id' => $original->merchant_id,
            'branch_id' => $original->branch_id,
            'platform_fee_ledger_entry_id' => $original->id,
            'adjustment_type' => $type->value,
            'amount_minor' => $signedAmountMinor,
            'currency' => $currency,
            'reason' => $reason,
            'source_reference' => $sourceReference,
            'effective_date' => $businessDate->toDateString(),
            'created_by' => $actor->id,
            'approved_by' => null,
            'idempotency_key' => $idempotencyKey,
        ]);

        // Non-monetary lifecycle marker on the original (only when still on a billing status; the
        // remaining-balance guard — not the marker — gates further corrections, so multiple partials
        // are supported).
        $marker = $type === PlatformFeeAdjustmentType::Reversal
            ? PlatformFeeLedgerStatus::Reversed
            : PlatformFeeLedgerStatus::Adjusted;
        if (! $original->status->isTerminal() && $this->ledgerMachine->canTransition($original->status, $marker)) {
            $original->forceFill(['status' => $marker->value])->save();
        }

        $event = $type === PlatformFeeAdjustmentType::Reversal
            ? AuditEvent::PlatformFeeReversed
            : AuditEvent::PlatformFeeAdjusted;

        $this->audit->record($event, $actor, $original->merchant_id, $original->branch_id, $adjustment, [
            'platform_fee_adjustment_id' => $adjustment->ulid,
            'original_entry_id' => $original->ulid,
            'correction_entry_id' => $correctionEntry->ulid,
            'adjustment_type' => $type->value,
            'amount_minor' => $signedAmountMinor,
            'currency' => $currency,
            'source_reference' => $sourceReference,
            'remaining_reversible_minor' => $remaining + $signedAmountMinor,
        ]);

        return $adjustment;
    }

    /** Remaining reversible balance = original gross + Σ signed prior adjustments (≤ gross). */
    public function remainingReversible(PlatformFeeLedgerEntry $original): int
    {
        $prior = (int) PlatformFeeAdjustment::query()
            ->where('platform_fee_ledger_entry_id', $original->id)
            ->sum('amount_minor');

        return $original->gross_platform_fee_minor + $prior;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Platform-fee corrections must run inside the owning financial transaction.');
        }
    }
}
