<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\SubscriptionPlanStatus;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Queries\ResolveEffectivePlanPrice;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Resolve and validate the plan + price a merchant selects during first-time setup (Plan §47, §48;
 * ADR-011; Phase 20B). Accepts public ULIDs only and enforces, with 422 field errors:
 *
 *   - the plan exists and is `active` (retired plans rejected);
 *   - the price exists and belongs to the selected plan (a price from another plan rejected);
 *   - the price is the currently-EFFECTIVE price for its (plan, interval, currency) on the setup
 *     date — i.e. its half-open [effective_from, effective_to) range contains today (historical /
 *     future-ineligible prices rejected), reusing {@see ResolveEffectivePlanPrice} rather than
 *     duplicating price logic.
 *
 * Plans/prices are platform-owned (no tenant scope). Returns the resolved price for
 * CreateTrialSubscription.
 */
final class ResolveSetupPlanPrice
{
    public function __construct(private readonly ResolveEffectivePlanPrice $effectivePrice) {}

    public function resolve(string $planUlid, string $priceUlid, ?CarbonImmutable $onDate = null): SubscriptionPlanPrice
    {
        $onDate ??= CarbonImmutable::now(BillingIntervalCalculator::TIMEZONE);

        $plan = SubscriptionPlan::query()->where('ulid', $planUlid)->first();
        if ($plan === null) {
            throw ValidationException::withMessages([
                'subscription_plan_ulid' => 'The selected plan does not exist.',
            ]);
        }
        if ($plan->status !== SubscriptionPlanStatus::Active) {
            throw ValidationException::withMessages([
                'subscription_plan_ulid' => 'The selected plan is not available.',
            ]);
        }

        $price = SubscriptionPlanPrice::query()->where('ulid', $priceUlid)->first();
        if ($price === null) {
            throw ValidationException::withMessages([
                'subscription_plan_price_ulid' => 'The selected price does not exist.',
            ]);
        }
        if ($price->plan_id !== $plan->id) {
            throw ValidationException::withMessages([
                'subscription_plan_price_ulid' => 'The selected price does not belong to the selected plan.',
            ]);
        }

        $effective = $this->effectivePrice->resolve($plan, $price->billing_interval, $price->currency, $onDate);
        if ($effective === null || $effective->id !== $price->id) {
            throw ValidationException::withMessages([
                'subscription_plan_price_ulid' => 'The selected price is not currently effective for this plan.',
            ]);
        }

        return $price;
    }
}
