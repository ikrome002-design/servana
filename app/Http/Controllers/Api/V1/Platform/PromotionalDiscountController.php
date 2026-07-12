<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\ApprovePromotionalDiscount;
use App\Domain\Billing\Actions\CancelPromotionalDiscount;
use App\Domain\Billing\Actions\CreatePromotionalDiscount;
use App\Domain\Billing\Actions\PausePromotionalDiscount;
use App\Domain\Billing\Actions\ResumePromotionalDiscount;
use App\Domain\Billing\Actions\UpdatePromotionalDiscountDraft;
use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Merchants\Models\Merchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ChangeReasonRequest;
use App\Http\Requests\Billing\StorePromotionalDiscountRequest;
use App\Http\Requests\Billing\UpdatePromotionalDiscountRequest;
use App\Http\Resources\PromotionalDiscountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Promotional-discount management API (Plan §53; Phase 20C). Super-Admin platform scope; MFA (group) +
 * fresh step-up (mutating routes) + idempotency on create. Named actions per transition — no generic
 * status route; the controller never assigns a status string. Thin: authorize → validate → action →
 * masked Resource.
 */
final class PromotionalDiscountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PromotionalDiscount::class);

        $query = PromotionalDiscount::query()->with(['targets.merchant', 'targets.plan'])->orderByDesc('effective_from');

        if (in_array($request->string('status')->value(), PromotionStatus::values(), true)) {
            $query->where('status', $request->string('status')->value());
        }
        if (in_array($request->string('target_scope')->value(), PromotionTargetScope::values(), true)) {
            $query->where('target_scope', $request->string('target_scope')->value());
        }

        return PromotionalDiscountResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function store(StorePromotionalDiscountRequest $request, CreatePromotionalDiscount $action): JsonResponse
    {
        $this->authorize('manage', PromotionalDiscount::class);

        /** @var User $actor */
        $actor = $request->user();
        /** @var array<string,mixed> $data */
        $data = $request->validated();

        $discount = $action->handle($this->attributes($data), $this->mapTargets($data), $actor);

        return PromotionalDiscountResource::make($discount->load(['targets.merchant', 'targets.plan']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PromotionalDiscount $promotionalDiscount): PromotionalDiscountResource
    {
        $this->authorize('view', $promotionalDiscount);

        return PromotionalDiscountResource::make($promotionalDiscount->load(['targets.merchant', 'targets.plan']));
    }

    public function update(UpdatePromotionalDiscountRequest $request, PromotionalDiscount $promotionalDiscount, UpdatePromotionalDiscountDraft $action): PromotionalDiscountResource
    {
        $this->authorize('manage', $promotionalDiscount);

        /** @var User $actor */
        $actor = $request->user();
        /** @var array<string,mixed> $data */
        $data = $request->validated();

        $discount = $action->handle($promotionalDiscount, $this->attributes($data), $this->mapTargets($data), $actor);

        return PromotionalDiscountResource::make($discount->load(['targets.merchant', 'targets.plan']));
    }

    public function approve(ChangeReasonRequest $request, PromotionalDiscount $promotionalDiscount, ApprovePromotionalDiscount $action): PromotionalDiscountResource
    {
        $this->authorize('manage', $promotionalDiscount);

        return PromotionalDiscountResource::make(
            $action->handle($promotionalDiscount, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['targets.merchant', 'targets.plan']),
        );
    }

    public function pause(ChangeReasonRequest $request, PromotionalDiscount $promotionalDiscount, PausePromotionalDiscount $action): PromotionalDiscountResource
    {
        $this->authorize('manage', $promotionalDiscount);

        return PromotionalDiscountResource::make(
            $action->handle($promotionalDiscount, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['targets.merchant', 'targets.plan']),
        );
    }

    public function resume(ChangeReasonRequest $request, PromotionalDiscount $promotionalDiscount, ResumePromotionalDiscount $action): PromotionalDiscountResource
    {
        $this->authorize('manage', $promotionalDiscount);

        return PromotionalDiscountResource::make(
            $action->handle($promotionalDiscount, $this->actor($request), (string) $request->validated('change_reason'))
                ->load(['targets.merchant', 'targets.plan']),
        );
    }

    public function cancel(ChangeReasonRequest $request, PromotionalDiscount $promotionalDiscount, CancelPromotionalDiscount $action): PromotionalDiscountResource
    {
        $this->authorize('manage', $promotionalDiscount);

        return PromotionalDiscountResource::make(
            $action->handle($promotionalDiscount, $this->actor($request), (string) $request->validated('change_reason'))
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
        return Arr::only($data, ['name', 'type', 'value', 'currency', 'target_scope', 'effective_from', 'effective_to']);
    }

    /**
     * Resolve the validated target ULIDs to internal ids for the domain action (platform route — no
     * tenant scope). Global scope has no targets.
     *
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
