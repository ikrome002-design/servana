<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Enums\ScheduledPlanChangeStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Schedule a no-proration next-cycle plan change (Plan §48; Phase 20B). effective_at is the
 * subscription's current period end; the target price must belong to the target plan (composite FK).
 * At most one scheduled change per (subscription, effective boundary) — the partial unique index is
 * the concurrency backstop. Requires an active tenant context.
 */
final class SchedulePlanChange
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(MerchantSubscription $subscription, SubscriptionPlanPrice $targetPrice, User $actor): ScheduledPlanChange
    {
        return DB::transaction(function () use ($subscription, $targetPrice, $actor): ScheduledPlanChange {
            $locked = MerchantSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $change = new ScheduledPlanChange;
            $change->merchant_id = $locked->merchant_id;
            $change->merchant_subscription_id = $locked->id;
            $change->target_plan_id = $targetPrice->plan_id;
            $change->target_price_id = $targetPrice->id;
            $change->effective_at = $locked->current_period_end;
            $change->status = ScheduledPlanChangeStatus::Scheduled;
            $change->created_by = $actor->id;
            $change->save();

            $this->audit->record(AuditEvent::SubscriptionPlanChangeScheduled, $actor, $locked->merchant_id, null, $change, [
                'subscription_id' => $locked->ulid,
                'target_plan_id' => $change->target_plan_id,
                'target_price_id' => $change->target_price_id,
                'effective_at' => $change->effective_at->toDateString(),
            ]);

            return $change;
        });
    }
}
