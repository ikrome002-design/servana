<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\UpdatePlanEntitlements;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\UpdatePlanEntitlementsRequest;
use App\Http\Resources\PlanEntitlementResource;
use App\Models\User;
use App\Policies\PlanEntitlementPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan-entitlement API (Plan §13.9, §20, §47; Phase 20A). Nested under a plan. Managed under
 * `platform.plan.manage` (no separate entitlement key). Super-Admin platform scope; MFA (group) +
 * fresh step-up (route) on update. Thin.
 */
final class PlanEntitlementController extends Controller
{
    public function index(Request $request, SubscriptionPlan $plan): AnonymousResourceCollection
    {
        $this->guard($request, 'viewAny');

        return PlanEntitlementResource::collection($plan->entitlements()->orderBy('entitlement_key')->get());
    }

    public function update(UpdatePlanEntitlementsRequest $request, SubscriptionPlan $plan, UpdatePlanEntitlements $action): AnonymousResourceCollection
    {
        $this->guard($request, 'update');

        /** @var User $actor */
        $actor = $request->user();

        /** @var list<array{entitlement_key:string,enabled:bool,limit_int?:int|null}> $entitlements */
        $entitlements = $request->validated('entitlements');

        $plan = $action->handle($plan, $entitlements, $actor);

        return PlanEntitlementResource::collection($plan->entitlements()->orderBy('entitlement_key')->get());
    }

    private function guard(Request $request, string $ability): void
    {
        $user = $request->user();
        abort_unless($user !== null && app(PlanEntitlementPolicy::class)->{$ability}($user), Response::HTTP_FORBIDDEN);
    }
}
