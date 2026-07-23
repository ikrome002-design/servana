<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\PlanContextResolver;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Queries\ResolvePlanEntitlement;
use App\Http\Middleware\EnsureBillingMutable;
use App\Http\Middleware\EnsureEntitlement;

/**
 * The concrete {@see PlanContextResolver} (Plan §20 "Merchant's effective entitlements derive from
 * the active `merchant_subscriptions.plan_id`"; Phase 21S).
 *
 * WHY THIS EXISTS AND WHY IT LANDS IN 21S. Phase 20A shipped the entitlement resolver
 * ({@see ResolvePlanEntitlement}) plus this interface, and bound it to
 * {@see UnboundPlanContextResolver} with the docblock "Phase 20B provides the real implementation
 * reading the active subscription". Phase 20B shipped `merchant_subscriptions` (with `plan_id` and
 * a partial unique index for one non-terminal subscription per merchant) but never replaced the
 * binding, so `resolveActivePlan()` returned `null` for every merchant and NO entitlement could
 * ever resolve. Phase 21S is the first phase with an entitlement-gated permission
 * (`personnel.my_sms.send`, `entitlement_key: sms` in the matrix), so it is the first phase where
 * that gap is load-bearing: without this class the `sms` entitlement denies unconditionally and
 * Phase 21S cannot satisfy its own acceptance criteria.
 *
 * BLAST RADIUS. Nothing else in the codebase consumes the entitlement resolver, and the only route
 * class that enforces entitlements is the Phase 21S preview/create/confirm set, which opts in
 * explicitly through {@see EnsureEntitlement}. Replacing the binding therefore
 * changes behaviour for Phase 21S alone.
 *
 * FAIL CLOSED. `null` is returned — and the gate denies with `no_active_plan` — when:
 *   - the merchant has no subscription row at all;
 *   - every subscription is terminal (`cancelled` / `expired`);
 *   - the subscription's plan row is missing.
 * A `suspended_billing`, `read_only_grace` or `overdue` subscription still RESOLVES its plan,
 * because entitlement ("does your plan include SMS?") and billing access ("may you mutate right
 * now?") are two independent gates by Plan §9.4 — the billing gate
 * ({@see EnsureBillingMutable}) is what blocks a send in those states, and it
 * reads `merchants.billing_status`, never the subscription record.
 */
final class SubscriptionPlanContextResolver implements PlanContextResolver
{
    public function resolveActivePlan(int $merchantId): ?SubscriptionPlan
    {
        // The MerchantScope global scope stays ON deliberately (Plan §8.2): the gate always asks
        // about the RESOLVED merchant, so the scope agrees with the explicit predicate below and
        // adds a second, independent guarantee that a foreign subscription can never be read. When
        // no context is bound at all the scope no-ops and the explicit predicate governs.
        /** @var MerchantSubscription|null $subscription */
        $subscription = MerchantSubscription::query()
            ->where('merchant_id', $merchantId)
            // Terminal records are history, never an entitlement source.
            ->whereNotIn('status', [
                MerchantSubscriptionStatus::Cancelled->value,
                MerchantSubscriptionStatus::Expired->value,
            ])
            ->orderByDesc('id')
            ->first();

        if ($subscription === null) {
            return null;
        }

        /** @var SubscriptionPlan|null $plan */
        $plan = SubscriptionPlan::query()->whereKey($subscription->plan_id)->first();

        return $plan;
    }
}
