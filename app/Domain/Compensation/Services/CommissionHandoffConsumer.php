<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Actions\EarnCommissionForValidationEvent;
use App\Domain\Compensation\Actions\ReverseCommissionEntry;
use App\Domain\Compensation\Enums\CommissionHandoffKind;
use App\Domain\Compensation\Enums\CommissionLedgerEntryType;
use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Enums\CommissionReversalReason;
use App\Domain\Compensation\Exceptions\CompensationLedgerException;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Payments\Enums\PaymentValidationDecision;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Consumes the durable `commission_handoff_events` outbox into the commission_ledger (Plan §61;
 * G3; Phase 20G). The handoff is written INSIDE the authoritative Finance-validation / refund
 * transaction (Phase 18B), so a committed source transaction always leaves a durable event; this
 * consumer turns each event into ledger effects and stamps `consumed_at` ATOMICALLY.
 *
 * Per event, one transaction: lock the event (FOR UPDATE) → re-check it is unconsumed → resolve the
 * immutable source → earn/reverse → stamp `consumed_at` → commit. Any failure rolls the whole
 * transaction back (no ledger effect, no `consumed_at`, no success audit) and leaves the event
 * retryable; a non-fatal `compensation.handoff.failed` audit is written AFTER the rollback so the
 * failure is observable. Concurrency: two workers cannot double-apply — the row lock serializes
 * them and the second sees `consumed_at` set and no-ops. Idempotency is also DB-enforced by the
 * earned/reversal unique indexes.
 *
 * Causal ordering (§9.4): a `reversal` event whose original `validated_allocation` has not yet been
 * consumed throws {@see CompensationLedgerException::originalNotYetEarned()} → retryable, never a
 * fabricated negative. Events are processed in id order, so the original (lower id) is normally
 * consumed first.
 *
 * Reversal model (Increment 4; product-owner resolution 2026-07-18, ADR-005 / Plan §61): a reversal
 * is ALWAYS the EXACT NEGATIVE of a whole earned row — never a recomputed fraction — and there is at
 * most one reversal per original (DB unique on source_entry_id). Because commission is earned per
 * validation event across all items (with no immutable item-level refund attribution), the earned
 * rows are reversed ONLY once the ENTIRE validated allocation has been refunded. The consumer derives
 * the cumulative finalized-refund total for the event's recording group from immutable source rows:
 *   cumulative < validated  → a valid NO-EFFECT event (a partial refund never fractionally reverses);
 *                             consumed + re-evaluable, since a later refund's own handoff completes it;
 *   cumulative = validated  → exact-negative reversal of every remaining earned row, exactly once;
 *   cumulative > validated  → fail CLOSED (impossible source state; never reverse more than earned).
 * Invoice void does NOT invalidate the validated allocation (payments/validated_paid are untouched),
 * so it produces NO commission reversal (the refund seam is the authoritative reversal path). The
 * pre-validation reject/correction flows earn nothing, so they can never reverse commission.
 */
final class CommissionHandoffConsumer
{
    public function __construct(
        private readonly EarnCommissionForValidationEvent $earner,
        private readonly ReverseCommissionEntry $reverser,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{consumed: int, failed: int, earned_rows: int, reversal_rows: int, deferred_partial: int}
     */
    public function process(int $limit = 100): array
    {
        $summary = ['consumed' => 0, 'failed' => 0, 'earned_rows' => 0, 'reversal_rows' => 0, 'deferred_partial' => 0];

        $handoffs = CommissionHandoffEvent::query()
            ->whereNull('consumed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($handoffs as $handoff) {
            try {
                $effect = DB::transaction(function () use ($handoff): array {
                    /** @var CommissionHandoffEvent|null $locked */
                    $locked = CommissionHandoffEvent::query()->whereKey($handoff->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->consumed_at !== null) {
                        return ['earned' => 0, 'reversed' => 0, 'deferred' => 0, 'skipped' => true];
                    }

                    $effect = match ($locked->kind) {
                        CommissionHandoffKind::ValidatedAllocation => ['earned' => count($this->consumeValidatedAllocation($locked)), 'reversed' => 0, 'deferred' => 0, 'skipped' => false],
                        CommissionHandoffKind::Reversal => $this->consumeReversal($locked) + ['skipped' => false],
                    };

                    $locked->forceFill(['consumed_at' => CarbonImmutable::now()])->save();

                    return $effect;
                });

                if ($effect['skipped'] === false) {
                    $summary['consumed']++;
                    $summary['earned_rows'] += $effect['earned'];
                    $summary['reversal_rows'] += $effect['reversed'];
                    $summary['deferred_partial'] += $effect['deferred'];
                }
            } catch (Throwable $e) {
                $summary['failed']++;
                $this->audit->record(
                    AuditEvent::CompensationHandoffFailed,
                    null,
                    $handoff->merchant_id,
                    $handoff->branch_id,
                    $handoff,
                    [
                        'commission_handoff_event_id' => $handoff->ulid,
                        'kind' => $handoff->kind->value,
                        'error_code' => $e instanceof CompensationLedgerException ? $e->errorCode() : 'handoff_processing_failed',
                    ],
                );
            }
        }

        return $summary;
    }

    /**
     * @return list<int> earned commission ids
     */
    private function consumeValidatedAllocation(CommissionHandoffEvent $handoff): array
    {
        /** @var PaymentValidationEvent|null $event */
        $event = PaymentValidationEvent::query()->whereKey($handoff->payment_validation_event_id)->first();
        if ($event === null) {
            throw CompensationLedgerException::configurationInvariant('Handoff references a missing validation event.');
        }
        if ($event->merchant_id !== $handoff->merchant_id || $event->branch_id !== $handoff->branch_id) {
            throw CompensationLedgerException::configurationInvariant('Handoff and validation event tenancy disagree.');
        }

        return $this->earner->handle($event);
    }

    /**
     * Consume a refund-finalization reversal handoff under the exact-negative rule (ADR-005; §61).
     *
     * @return array{earned: int, reversed: int, deferred: int}
     */
    private function consumeReversal(CommissionHandoffEvent $handoff): array
    {
        /** @var PaymentRecord|null $component */
        $component = PaymentRecord::query()->whereKey($handoff->payment_record_id)->first();
        if ($component === null) {
            throw CompensationLedgerException::configurationInvariant('Reversal handoff references a missing payment component.');
        }

        // The single VALIDATED event for the component's recording group. A group validates at most once
        // (state machine), but a prior correction/rejection leaves non-validated events, so filter by
        // the validated decision to resolve the earning source deterministically.
        /** @var PaymentValidationEvent|null $event */
        $event = PaymentValidationEvent::query()
            ->where('payment_recording_group_id', $component->payment_recording_group_id)
            ->where('decision', PaymentValidationDecision::Validated->value)
            ->first();
        if ($event === null) {
            throw CompensationLedgerException::configurationInvariant('Reversal handoff has no validated source event.');
        }

        // Cumulative finalized refunds against the WHOLE validated allocation (every component of the
        // recording group), derived only from immutable source rows. Commission is earned per validation
        // event across all items; under ADR-005 a reversal is the exact negative of a whole earned row
        // (never a fraction), so the earned rows are reversed only once the entire validated allocation
        // has been refunded.
        $componentIds = PaymentRecord::query()
            ->where('payment_recording_group_id', $component->payment_recording_group_id)
            ->pluck('id')
            ->all();
        $validatedAllocation = (int) $event->validated_amount_minor;
        $cumulativeRefunded = (int) Refund::query()
            ->whereIn('payment_record_id', $componentIds)
            ->where('status', RefundStatus::Finalized->value)
            ->sum('amount_minor');

        // Over-reversal is impossible (FinalizeRefund caps refunds at the recognised balance); if it ever
        // occurs, fail CLOSED and leave the event retryable — never reverse more than was earned.
        if ($cumulativeRefunded > $validatedAllocation) {
            throw CompensationLedgerException::cumulativeReversalExceedsValidatedAllocation();
        }

        // Partial: the validated allocation is not yet fully reversed. The exact-negative rule forbids a
        // fractional reversal, so this is a valid NO-EFFECT source event — consumed and re-evaluable,
        // because the later refund that completes the full reversal re-derives the cumulative total.
        if ($cumulativeRefunded < $validatedAllocation) {
            return ['earned' => 0, 'reversed' => 0, 'deferred' => 1];
        }

        // Full reversal of the validated allocation → exact-negative reverse every remaining earned row.
        $earned = CommissionLedgerEntry::query()
            ->where('payment_validation_event_id', $event->id)
            ->where('entry_type', CommissionLedgerEntryType::Earned->value)
            ->where('status', '!=', CommissionLedgerStatus::Reversed->value)
            ->orderBy('id')
            ->get();

        if ($earned->isEmpty()) {
            // Either the original was never earned yet (causal order) or there was legitimately no
            // commission (salary_only / ineligible / already reversed). Distinguish by whether the
            // earning handoff for this event has been consumed.
            $originConsumed = CommissionHandoffEvent::query()
                ->where('payment_validation_event_id', $event->id)
                ->where('kind', CommissionHandoffKind::ValidatedAllocation->value)
                ->whereNotNull('consumed_at')
                ->exists();
            if (! $originConsumed) {
                throw CompensationLedgerException::originalNotYetEarned();
            }

            return ['earned' => 0, 'reversed' => 0, 'deferred' => 0];
        }

        $reversed = 0;
        foreach ($earned as $row) {
            $this->reverser->handle($row, CommissionReversalReason::RefundFinalized);
            $reversed++;
        }

        return ['earned' => 0, 'reversed' => $reversed, 'deferred' => 0];
    }
}
