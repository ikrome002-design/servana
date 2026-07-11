<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Models\PlanEntitlement;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\ValueObjects\EntitlementDecision;

/**
 * Resolves a plan entitlement decision (Plan §20; Phase 20A). Pure and side-effect-free:
 *
 *   - entitlement absent  → deny (entitlement_absent);
 *   - entitlement disabled → deny (entitlement_disabled);
 *   - enabled, no limit    → allow (unlimited);
 *   - enabled, currentCount < limit → allow;
 *   - enabled, currentCount >= limit → deny (entitlement_limit_exceeded).
 *
 * The merchant→plan binding is resolved elsewhere (Phase 20B via PlanContextResolver); this
 * query takes an explicit plan so it is fully testable in Phase 20A. Denying an over-limit
 * action never deletes data — downgrade is no-data-loss at the service level.
 */
final class ResolvePlanEntitlement
{
    public function resolve(SubscriptionPlan $plan, string $entitlementKey, int $currentCount = 0): EntitlementDecision
    {
        /** @var PlanEntitlement|null $entitlement */
        $entitlement = $plan->entitlements()->where('entitlement_key', $entitlementKey)->first();

        if ($entitlement === null) {
            return EntitlementDecision::deny(EntitlementDecision::CODE_ABSENT);
        }

        if (! $entitlement->enabled) {
            return EntitlementDecision::deny(EntitlementDecision::CODE_DISABLED);
        }

        if ($entitlement->limit_int === null) {
            return EntitlementDecision::allow(null);
        }

        if ($currentCount >= $entitlement->limit_int) {
            return EntitlementDecision::deny(EntitlementDecision::CODE_LIMIT_EXCEEDED, $entitlement->limit_int);
        }

        return EntitlementDecision::allow($entitlement->limit_int);
    }
}
