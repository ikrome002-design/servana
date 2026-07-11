<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\CancelFuturePlanPrice;
use App\Domain\Billing\Actions\CreatePlanPrice;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StorePlanPriceRequest;
use App\Http\Resources\SubscriptionPlanPriceResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan-price API (Plan §13.9, §47; ADR-011; Phase 20A) — the SOLE price source. Super-Admin
 * platform scope; MFA (group) + fresh step-up + idempotency (route) on create. A future
 * `effective_from` schedules a price. Cancel withdraws a not-yet-effective price. Thin.
 */
final class SubscriptionPlanPriceController extends Controller
{
    public function index(Request $request, SubscriptionPlan $plan): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SubscriptionPlanPrice::class);

        $query = SubscriptionPlanPrice::query()
            ->where('plan_id', $plan->id)
            ->orderByDesc('effective_from');

        return SubscriptionPlanPriceResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function store(StorePlanPriceRequest $request, SubscriptionPlan $plan, CreatePlanPrice $action): JsonResponse
    {
        $this->authorize('create', SubscriptionPlanPrice::class);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array{amount_minor:int,currency:string,billing_interval:string,effective_from:string,effective_to?:string|null} $data */
        $data = $request->validated();

        return SubscriptionPlanPriceResource::make($action->handle($plan, $data, $actor))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function cancel(Request $request, SubscriptionPlanPrice $planPrice, CancelFuturePlanPrice $action): JsonResponse
    {
        $this->authorize('cancel', $planPrice);

        /** @var User $actor */
        $actor = $request->user();

        $action->handle($planPrice, $actor);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
