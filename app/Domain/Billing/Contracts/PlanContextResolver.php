<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Queries\ResolvePlanEntitlement;
use App\Domain\Billing\Services\UnboundPlanContextResolver;

/**
 * Resolves the effective subscription plan a merchant is currently on (Plan §20; Phase 20A
 * substrate). The merchant→plan binding lives in `merchant_subscriptions`, which is **Phase 20B**
 * — so Phase 20A depends on this interface and ships a no-op default
 * ({@see UnboundPlanContextResolver}) that returns null (no plan
 * bound yet). Phase 20B provides the real implementation reading the active subscription. The
 * 20A entitlement resolver ({@see ResolvePlanEntitlement}) is fully
 * testable against a plan directly; only the merchant→plan binding is deferred.
 */
interface PlanContextResolver
{
    public function resolveActivePlan(int $merchantId): ?SubscriptionPlan;
}
