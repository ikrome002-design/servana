<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\ApproveFreePeriodOffer;
use App\Domain\Billing\Actions\CancelFreePeriodOffer;
use App\Domain\Billing\Actions\CreateFreePeriodOffer;
use App\Domain\Billing\Actions\PauseFreePeriodOffer;
use App\Domain\Billing\Actions\ResumeFreePeriodOffer;
use App\Domain\Billing\Actions\UpdateFreePeriodOfferDraft;
use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Merchants\Models\Merchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ChangeReasonRequest;
use App\Http\Requests\Billing\StoreFreePeriodOfferRequest;
use App\Http\Requests\Billing\UpdateFreePeriodOfferRequest;
use App\Http\Resources\FreePeriodOfferResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Free-period-offer management API (Plan §53; Phase 20C). Super-Admin platform scope; MFA (group) +
 * fresh step-up (mutating routes) + idempotency on create. Named actions per transition — no generic
 * status route; the controller never assigns a status string. Thin.
 */
final class FreePeriodOfferController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FreePeriodOffer::class);

        $query = FreePeriodOffer::query()->with(['targets.merchant', 'targets.plan'])->orderByDesc('effective_from');

        if (in_array($request->string('status')->value(), FreePeriodOfferStatus::values(), true)) {
            $query->where('status', $request->string('status')->value());
        }
        if (in_array($request->string('target_scope')->value(), PromotionTargetScope::values(), true)) {
            $query->where('target_scope', $request->string('target_scope')->value());
        }

        return FreePeriodOfferResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function store(StoreFreePeriodOfferRequest $request, CreateFreePeriodOffer $action): JsonResponse
    {
        $this->authorize('manage', FreePeriodOffer::class);

        /** @var array<string,mixed> $data */
        $data = $request->validated();

        $offer = $action->handle($this->attributes($data), $this->mapTargets($data), $this->actor($request));

        return FreePeriodOfferResource::make($offer->load(['targets.merchant', 'targets.plan']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(FreePeriodOffer $freePeriodOffer): FreePeriodOfferResource
    {
        $this->authorize('view', $freePeriodOffer);

        return FreePeriodOfferResource::make($freePeriodOffer->load(['targets.merchant', 'targets.plan']));
    }

    public function update(UpdateFreePeriodOfferRequest $request, FreePeriodOffer $freePeriodOffer, UpdateFreePeriodOfferDraft $action): FreePeriodOfferResource
    {
        $this->authorize('manage', $freePeriodOffer);

        /** @var array<string,mixed> $data */
        $data = $request->validated();

        $offer = $action->handle($freePeriodOffer, $this->attributes($data), $this->mapTargets($data), $this->actor($request));

        return FreePeriodOfferResource::make($offer->load(['targets.merchant', 'targets.plan']));
    }

    public function approve(ChangeReasonRequest $request, FreePeriodOffer $freePeriodOffer, ApproveFreePeriodOffer $action): FreePeriodOfferResource
    {
        $this->authorize('manage', $freePeriodOffer);

        return FreePeriodOfferResource::make(
            $action->handle($freePeriodOffer, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['targets.merchant', 'targets.plan']),
        );
    }

    public function pause(ChangeReasonRequest $request, FreePeriodOffer $freePeriodOffer, PauseFreePeriodOffer $action): FreePeriodOfferResource
    {
        $this->authorize('manage', $freePeriodOffer);

        return FreePeriodOfferResource::make(
            $action->handle($freePeriodOffer, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['targets.merchant', 'targets.plan']),
        );
    }

    public function resume(ChangeReasonRequest $request, FreePeriodOffer $freePeriodOffer, ResumeFreePeriodOffer $action): FreePeriodOfferResource
    {
        $this->authorize('manage', $freePeriodOffer);

        return FreePeriodOfferResource::make(
            $action->handle($freePeriodOffer, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['targets.merchant', 'targets.plan']),
        );
    }

    public function cancel(ChangeReasonRequest $request, FreePeriodOffer $freePeriodOffer, CancelFreePeriodOffer $action): FreePeriodOfferResource
    {
        $this->authorize('manage', $freePeriodOffer);

        return FreePeriodOfferResource::make(
            $action->handle($freePeriodOffer, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['targets.merchant', 'targets.plan']),
        );
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function attributes(array $data): array
    {
        return Arr::only($data, ['name', 'free_period_days', 'target_scope', 'effective_from', 'effective_to']);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<array{target_type:string,merchant_id?:int|null,subscription_plan_id?:int|null,billing_mode?:string|null}>
     */
    private function mapTargets(array $data): array
    {
        if (($data['target_scope'] ?? null) === PromotionTargetScope::AllNewMerchants->value) {
            return [];
        }

        /** @var list<array<string,mixed>> $targets */
        $targets = $data['targets'] ?? [];
        $specs = [];
        foreach ($targets as $target) {
            $spec = ['target_type' => (string) $target['target_type']];
            if (! empty($target['merchant_id'])) {
                $spec['merchant_id'] = Merchant::query()->where('ulid', $target['merchant_id'])->value('id');
            }
            if (! empty($target['subscription_plan_id'])) {
                $spec['subscription_plan_id'] = SubscriptionPlan::query()->where('ulid', $target['subscription_plan_id'])->value('id');
            }
            if (! empty($target['billing_mode'])) {
                $spec['billing_mode'] = (string) $target['billing_mode'];
            }
            $specs[] = $spec;
        }

        return $specs;
    }
}
