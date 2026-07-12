<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Services\BillingIntervalCalculator;
use App\Domain\Billing\Services\FreePeriodOfferStateMachine;
use App\Domain\Billing\Services\PromotionalDiscountStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Promotion / free-period lifecycle scheduler (Plan §53, §67; Phase 20C). Scheduled DAILY in
 * `Africa/Nairobi` (routes/console.php; `withoutOverlapping` singleton + `onOneServer` leader-only).
 *
 * Platform-scoped (no tenant context — these are global configuration rows). It drives, from
 * authoritative effective-window state (never hardcoded), for BOTH promotional discounts and
 * free-period offers:
 *   - activation: `scheduled → active` once `effective_from` is reached;
 *   - expiry: `active → expired` once `effective_to` is reached (half-open window; expires when
 *     `effective_to <= today`).
 *
 * Each record is processed under its own row lock in a bounded per-item transaction; each transition
 * runs through the state machine and emits exactly one typed high-severity audit event. Idempotent —
 * re-selection by status guarantees exactly-once, and snapshots on existing subscriptions/invoices are
 * never touched. A per-item failure emits one bounded, redacted signal and the run exits non-zero.
 */
final class ProcessPromotionLifecycle extends Command
{
    protected $signature = 'billing:process-promotion-lifecycle';

    protected $description = 'Activate due scheduled promotions/free-period offers and expire due active ones (Phase 20C).';

    private const BATCH = 500;

    public function handle(
        AuditRecorder $audit,
        PromotionalDiscountStateMachine $promotionMachine,
        FreePeriodOfferStateMachine $offerMachine,
    ): int {
        $today = CarbonImmutable::now(BillingIntervalCalculator::TIMEZONE)->toDateString();
        $failures = 0;

        // --- Promotional discounts -----------------------------------------------------------
        foreach ($this->dueIds('promotional_discounts', PromotionStatus::Scheduled->value, 'effective_from', '<=', $today) as $id) {
            $failures += $this->guard($id, function () use ($id, $audit, $promotionMachine): void {
                DB::transaction(function () use ($id, $audit, $promotionMachine): void {
                    $promotion = PromotionalDiscount::query()->whereKey($id)->lockForUpdate()->first();
                    if ($promotion === null || $promotion->status !== PromotionStatus::Scheduled) {
                        return;
                    }
                    $promotionMachine->ensure($promotion->status, PromotionStatus::Active);
                    $promotion->status = PromotionStatus::Active;
                    $promotion->save();
                    $audit->record(AuditEvent::PromotionActivated, null, null, null, $promotion, [
                        'promotion_id' => $promotion->ulid,
                        'trigger' => 'lifecycle_activation',
                    ]);
                });
            });
        }

        foreach ($this->dueExpiryIds('promotional_discounts', PromotionStatus::Active->value, $today) as $id) {
            $failures += $this->guard($id, function () use ($id, $audit, $promotionMachine): void {
                DB::transaction(function () use ($id, $audit, $promotionMachine): void {
                    $promotion = PromotionalDiscount::query()->whereKey($id)->lockForUpdate()->first();
                    if ($promotion === null || $promotion->status !== PromotionStatus::Active) {
                        return;
                    }
                    $promotionMachine->ensure($promotion->status, PromotionStatus::Expired);
                    $promotion->status = PromotionStatus::Expired;
                    $promotion->save();
                    $audit->record(AuditEvent::PromotionExpired, null, null, null, $promotion, [
                        'promotion_id' => $promotion->ulid,
                        'trigger' => 'lifecycle_expiry',
                    ]);
                });
            });
        }

        // --- Free-period offers --------------------------------------------------------------
        foreach ($this->dueIds('free_period_offers', FreePeriodOfferStatus::Scheduled->value, 'effective_from', '<=', $today) as $id) {
            $failures += $this->guard($id, function () use ($id, $audit, $offerMachine): void {
                DB::transaction(function () use ($id, $audit, $offerMachine): void {
                    $offer = FreePeriodOffer::query()->whereKey($id)->lockForUpdate()->first();
                    if ($offer === null || $offer->status !== FreePeriodOfferStatus::Scheduled) {
                        return;
                    }
                    $offerMachine->ensure($offer->status, FreePeriodOfferStatus::Active);
                    $offer->status = FreePeriodOfferStatus::Active;
                    $offer->save();
                    $audit->record(AuditEvent::FreePeriodOfferActivated, null, null, null, $offer, [
                        'free_period_offer_id' => $offer->ulid,
                        'trigger' => 'lifecycle_activation',
                    ]);
                });
            });
        }

        foreach ($this->dueExpiryIds('free_period_offers', FreePeriodOfferStatus::Active->value, $today) as $id) {
            $failures += $this->guard($id, function () use ($id, $audit, $offerMachine): void {
                DB::transaction(function () use ($id, $audit, $offerMachine): void {
                    $offer = FreePeriodOffer::query()->whereKey($id)->lockForUpdate()->first();
                    if ($offer === null || $offer->status !== FreePeriodOfferStatus::Active) {
                        return;
                    }
                    $offerMachine->ensure($offer->status, FreePeriodOfferStatus::Expired);
                    $offer->status = FreePeriodOfferStatus::Expired;
                    $offer->save();
                    $audit->record(AuditEvent::FreePeriodOfferExpired, null, null, null, $offer, [
                        'free_period_offer_id' => $offer->ulid,
                        'trigger' => 'lifecycle_expiry',
                    ]);
                });
            });
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Bounded id scan for activation (status + effective_from reached).
     *
     * @return list<int>
     */
    private function dueIds(string $table, string $status, string $column, string $operator, string $value): array
    {
        /** @var list<int> $ids */
        $ids = DB::table($table)
            ->where('status', $status)
            ->where($column, $operator, $value)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }

    /**
     * Bounded id scan for expiry (active + effective_to reached; half-open window).
     *
     * @return list<int>
     */
    private function dueExpiryIds(string $table, string $status, string $today): array
    {
        /** @var list<int> $ids */
        $ids = DB::table($table)
            ->where('status', $status)
            ->whereNotNull('effective_to')
            ->where('effective_to', '<=', $today)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }

    /**
     * Isolate a per-item failure into one bounded, redacted signal so a bad row never aborts the run.
     * Returns 1 on failure, 0 on success.
     */
    private function guard(int $id, \Closure $work): int
    {
        try {
            $work();

            return 0;
        } catch (\Throwable $e) {
            // §71 — one bounded, redacted failure signal (no payload/context/ids beyond the class).
            Log::warning('billing.promotion_lifecycle.item_failed', [
                'record_id' => $id,
                'exception' => $e::class,
            ]);

            return 1;
        }
    }
}
