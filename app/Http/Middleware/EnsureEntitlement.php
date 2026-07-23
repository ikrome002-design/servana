<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Billing\Contracts\PlanContextResolver;
use App\Domain\Billing\Exceptions\EntitlementDeniedException;
use App\Domain\Billing\Queries\ResolvePlanEntitlement;
use App\Domain\Billing\Services\SubscriptionPlanContextResolver;
use App\Domain\Billing\ValueObjects\EntitlementDecision;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan-entitlement gate (Plan §20; §9.4 step 10 — after permission resolution, before period-lock;
 * Phase 21S).
 *
 * OPT-IN, NEVER GLOBAL. A route enables it explicitly with the entitlement key as a parameter:
 *
 *     ->middleware(EnsureEntitlement::class.':sms')
 *
 * so no existing route's behaviour changes by adding this class. Phase 21S wires it onto the SMS
 * preview / create / confirm routes only; the served-client READ is deliberately not gated,
 * matching the matrix (`personnel.my_served_clients.view` carries `entitlement_key: null`).
 *
 * FAILS CLOSED at every step:
 *   - no tenant context            -> 403 `no_tenant_context`
 *   - no resolvable active plan    -> 403 `no_active_plan`
 *   - entitlement row absent       -> 403 `entitlement_absent`
 *   - entitlement disabled         -> 403 `entitlement_disabled`
 *   - limit reached                -> 403 `entitlement_limit_exceeded`
 *
 * The plan binding comes from {@see PlanContextResolver}
 * ({@see SubscriptionPlanContextResolver} in Phase 21S), and the
 * verdict from the pure Phase 20A {@see ResolvePlanEntitlement}. Entitlement is independent of
 * billing ACCESS: a merchant in `read_only_grace` still HAS the entitlement, and it is
 * {@see EnsureBillingMutable} that blocks the mutation. Attach after ResolveTenantContext +
 * EnsurePermission.
 */
final class EnsureEntitlement
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlanContextResolver $plans,
        private readonly ResolvePlanEntitlement $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $entitlementKey): Response
    {
        $merchantId = $this->context->merchantId();

        if ($merchantId === null) {
            throw TenantAccessException::noTenantContext();
        }

        $plan = $this->plans->resolveActivePlan($merchantId);

        if ($plan === null) {
            throw EntitlementDeniedException::from(
                $entitlementKey,
                EntitlementDecision::deny(EntitlementDecision::CODE_NO_PLAN),
            );
        }

        $decision = $this->entitlements->resolve($plan, $entitlementKey);

        if (! $decision->allowed) {
            throw EntitlementDeniedException::from($entitlementKey, $decision);
        }

        return $next($request);
    }
}
