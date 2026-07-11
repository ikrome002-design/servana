<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Resolves the effective (current or historical) plan price for a plan + interval + currency on
 * a date (Plan §13.9, §47; ADR-011; Phase 20A). The effective price is the row whose half-open
 * range [effective_from, effective_to) contains the date. Exactly one row can match per
 * (plan, interval, currency, instant) — guaranteed by the DB EXCLUDE constraint. Read-only.
 */
final class ResolveEffectivePlanPrice
{
    public function resolve(
        SubscriptionPlan $plan,
        BillingInterval $interval,
        string $currency,
        ?CarbonInterface $onDate = null,
    ): ?SubscriptionPlanPrice {
        $date = ($onDate ?? CarbonImmutable::now('Africa/Nairobi'))->toDateString();

        return SubscriptionPlanPrice::query()
            ->where('plan_id', $plan->id)
            ->where('billing_interval', $interval->value)
            ->where('currency', $currency)
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
