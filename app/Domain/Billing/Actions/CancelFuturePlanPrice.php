<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a not-yet-effective (FUTURE) plan price (Plan §13.9, §47; ADR-011; Phase 20A). A current
 * or historical (already-effective) price can NEVER be cancelled or deleted — attempting to →
 * 422 (no destructive edit of an effective historical price). Withdrawing a future price that
 * never took effect references no financial snapshot. Audits `subscription_plan_price.cancelled`.
 */
final class CancelFuturePlanPrice
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(SubscriptionPlanPrice $price, User $actor): void
    {
        DB::transaction(function () use ($price, $actor): void {
            $locked = SubscriptionPlanPrice::query()->whereKey($price->id)->lockForUpdate()->firstOrFail();

            if (! $locked->effective_from->isAfter(CarbonImmutable::now('Africa/Nairobi')->startOfDay())) {
                throw BillingStateException::invalidTransition('plan price', 'effective', 'cancelled');
            }

            $snapshot = [
                'plan_id' => (string) SubscriptionPlan::query()->whereKey($locked->plan_id)->value('ulid'),
                'price_id' => $locked->ulid,
                'billing_interval' => $locked->billing_interval->value,
                'currency' => $locked->currency,
            ];

            $locked->delete();

            $this->audit->record(AuditEvent::SubscriptionPlanPriceCancelled, $actor, null, null, null, $snapshot);
        });
    }
}
