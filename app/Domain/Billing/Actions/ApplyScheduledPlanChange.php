<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\ScheduledPlanChangeStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Services\BillingIntervalCalculator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Apply a scheduled plan change at the next cycle boundary (Plan §48; Phase 20B). NO proration.
 *
 * Row-locks the change and its subscription, moves the change scheduled → applied (exactly once — a
 * concurrent/replayed apply short-circuits on the terminal status), swaps the subscription's
 * plan/price/interval to the target, and advances the period: the new period starts at the old
 * period end and ends one target-interval later (canonical calculator; anchor preserved). History is
 * retained. Requires an active tenant context.
 */
final class ApplyScheduledPlanChange
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly BillingIntervalCalculator $calculator,
    ) {}

    public function handle(ScheduledPlanChange $change, ?User $actor = null): ScheduledPlanChange
    {
        return DB::transaction(function () use ($change, $actor): ScheduledPlanChange {
            $locked = ScheduledPlanChange::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ScheduledPlanChangeStatus::Scheduled) {
                return $locked; // exactly-once: already applied or cancelled — no-op
            }

            $subscription = MerchantSubscription::query()->whereKey($locked->merchant_subscription_id)->lockForUpdate()->firstOrFail();
            $targetPrice = SubscriptionPlanPrice::query()->whereKey($locked->target_price_id)->firstOrFail();

            $newStart = CarbonImmutable::parse($subscription->current_period_end)->setTimezone(BillingIntervalCalculator::TIMEZONE)->startOfDay();
            $newEnd = $this->calculator->nextBoundary($newStart, $targetPrice->billing_interval);

            $subscription->plan_id = $targetPrice->plan_id;
            $subscription->price_id = $targetPrice->id;
            $subscription->billing_interval = $targetPrice->billing_interval;
            $subscription->current_period_start = $newStart;
            $subscription->current_period_end = $newEnd;
            $subscription->save();

            $locked->status = ScheduledPlanChangeStatus::Applied;
            $locked->applied_at = CarbonImmutable::now();
            $locked->save();

            $this->audit->record(AuditEvent::SubscriptionPlanChangeApplied, $actor, $locked->merchant_id, null, $locked, [
                'subscription_id' => $subscription->ulid,
                'target_plan_id' => $subscription->plan_id,
                'target_price_id' => $subscription->price_id,
                'period_start' => $newStart->toDateString(),
                'period_end' => $newEnd->toDateString(),
            ]);

            return $locked;
        });
    }
}
