<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Domain\Billing\Actions\CancelScheduledPlanChange;
use App\Domain\Billing\Actions\SchedulePlanChange;
use App\Domain\Billing\Enums\SubscriptionPlanStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Queries\ResolveEffectivePlanPrice;
use App\Domain\Billing\Services\ResolveSetupPlanPrice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CancelScheduledPlanChangeRequest;
use App\Http\Requests\Billing\SchedulePlanChangeRequest;
use App\Http\Resources\MerchantSubscriptionResource;
use App\Http\Resources\ScheduledPlanChangeResource;
use App\Http\Resources\SubscriptionPlanOptionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant subscription self-service (Plan §22, §47, §48; Phase 20B). Merchant Administrator, merchant
 * scope. Reads the subscription/dashboard, plan options (effective prices), and the pending scheduled
 * change; schedules / cancels a no-proration next-cycle plan change. Tenant isolation is enforced by
 * the BelongsToMerchant scope (the merchant's own single subscription). Thin: authorize → action →
 * resource. NO trial / activation / invoice-issue / void / payment / Wallet endpoint lives here.
 */
final class MerchantSubscriptionController extends Controller
{
    public function show(): MerchantSubscriptionResource
    {
        $this->authorize('view', MerchantSubscription::class);

        return MerchantSubscriptionResource::make($this->currentSubscription());
    }

    public function plans(): AnonymousResourceCollection
    {
        $this->authorize('view', MerchantSubscription::class);

        $subscription = $this->currentSubscription();
        $interval = $subscription->billing_interval;
        /** @var SubscriptionPlanPrice $currentPrice */
        $currentPrice = $subscription->price;
        $currency = $currentPrice->currency;
        $resolver = app(ResolveEffectivePlanPrice::class);

        $plans = SubscriptionPlan::query()
            ->where('status', SubscriptionPlanStatus::Active->value)
            ->orderBy('sort_order')->orderBy('key')
            ->get()
            ->each(function (SubscriptionPlan $plan) use ($resolver, $interval, $currency, $subscription): void {
                $plan->setAttribute('effective_price', $resolver->resolve($plan, $interval, $currency));
                $plan->setAttribute('is_current', $plan->id === $subscription->plan_id);
            });

        return SubscriptionPlanOptionResource::collection($plans);
    }

    public function scheduledChange(): JsonResponse
    {
        $this->authorize('view', MerchantSubscription::class);

        $pending = $this->currentSubscription()->pendingScheduledChange();

        return response()->json([
            'data' => $pending !== null ? ScheduledPlanChangeResource::make($pending) : null,
        ]);
    }

    public function scheduleChange(SchedulePlanChangeRequest $request, SchedulePlanChange $action, ResolveSetupPlanPrice $resolver): JsonResponse
    {
        $this->authorize('scheduleChange', MerchantSubscription::class);

        $subscription = $this->currentSubscription();

        // At most one pending change per subscription (Plan §48). A second request is a conflict,
        // not a silent overwrite — surface a structured 409 rather than hit the DB unique index.
        if ($subscription->pendingScheduledChange() !== null) {
            return response()->json([
                'error' => [
                    'code' => 'scheduled_plan_change_exists',
                    'message' => 'A scheduled plan change is already pending; cancel it before scheduling another.',
                    'fields' => (object) [],
                    'meta' => (object) [],
                ],
            ], Response::HTTP_CONFLICT);
        }

        /** @var array{subscription_plan_ulid:string,subscription_plan_price_ulid:string} $data */
        $data = $request->validated();
        $price = $resolver->resolve($data['subscription_plan_ulid'], $data['subscription_plan_price_ulid']);

        /** @var User $actor */
        $actor = $request->user();

        return ScheduledPlanChangeResource::make($action->handle($subscription, $price, $actor))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function cancelScheduledChange(CancelScheduledPlanChangeRequest $request, CancelScheduledPlanChange $action): ScheduledPlanChangeResource
    {
        $this->authorize('scheduleChange', MerchantSubscription::class);

        $pending = $this->currentSubscription()->pendingScheduledChange();
        abort_if($pending === null, Response::HTTP_NOT_FOUND);

        /** @var User $actor */
        $actor = $request->user();

        return ScheduledPlanChangeResource::make($action->handle($pending, $actor));
    }

    /** The merchant's single subscription (BelongsToMerchant-scoped). */
    private function currentSubscription(): MerchantSubscription
    {
        $subscription = MerchantSubscription::query()->latest('id')->first();
        abort_if($subscription === null, Response::HTTP_NOT_FOUND);

        return $subscription;
    }
}
