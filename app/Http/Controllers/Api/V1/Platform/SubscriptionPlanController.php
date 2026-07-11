<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\CreateSubscriptionPlan;
use App\Domain\Billing\Actions\RetireSubscriptionPlan;
use App\Domain\Billing\Actions\UpdateSubscriptionPlanMetadata;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreSubscriptionPlanRequest;
use App\Http\Requests\Billing\UpdateSubscriptionPlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Subscription-plan catalogue API (Plan §13.9, §47; ADR-011; Phase 20A). Super-Admin platform
 * scope; MFA (group) + fresh step-up (route) on mutations. NON-PRICE metadata only. Thin.
 */
final class SubscriptionPlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SubscriptionPlan::class);

        $query = SubscriptionPlan::query()->orderBy('sort_order')->orderBy('key');

        if (in_array($request->string('status')->value(), ['active', 'retired'], true)) {
            $query->where('status', $request->string('status')->value());
        }

        return SubscriptionPlanResource::collection(
            $query->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function store(StoreSubscriptionPlanRequest $request, CreateSubscriptionPlan $action): JsonResponse
    {
        $this->authorize('create', SubscriptionPlan::class);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array{key:string,name:string,description?:string|null,tier?:string|null,metadata?:array<string,mixed>,sort_order?:int} $data */
        $data = $request->validated();

        return SubscriptionPlanResource::make($action->handle($data, $actor))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(SubscriptionPlan $plan): SubscriptionPlanResource
    {
        $this->authorize('view', $plan);

        return SubscriptionPlanResource::make($plan->load(['prices', 'entitlements']));
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $plan, UpdateSubscriptionPlanMetadata $action): SubscriptionPlanResource
    {
        $this->authorize('update', $plan);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array{name?:string,description?:string|null,tier?:string|null,metadata?:array<string,mixed>,sort_order?:int} $data */
        $data = $request->validated();

        return SubscriptionPlanResource::make($action->handle($plan, $data, $actor));
    }

    public function retire(Request $request, SubscriptionPlan $plan, RetireSubscriptionPlan $action): SubscriptionPlanResource
    {
        $this->authorize('retire', $plan);

        /** @var User $actor */
        $actor = $request->user();

        return SubscriptionPlanResource::make($action->handle($plan, $actor));
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }
}
