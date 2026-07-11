<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\PlanContextResolver;
use App\Domain\Billing\Models\SubscriptionPlan;

/**
 * Phase 20A default {@see PlanContextResolver}: no merchant is bound to a plan yet because
 * `merchant_subscriptions` is Phase 20B. Returns null for every merchant — the entitlement gate
 * therefore denies entitlement-dependent actions until Phase 20B provides the real binding.
 * This fabricates no subscription rows and never guesses a plan.
 */
final class UnboundPlanContextResolver implements PlanContextResolver
{
    public function resolveActivePlan(int $merchantId): ?SubscriptionPlan
    {
        return null;
    }
}
