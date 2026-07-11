<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Actions\ApprovePreferredPersonnelFeeRule;
use App\Domain\Billing\Actions\CancelPreferredPersonnelFeeRule;
use App\Domain\Billing\Actions\CreatePreferredPersonnelFeeRule;
use App\Domain\Billing\Actions\SupersedePreferredPersonnelFeeRule;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Platform\Services\PlatformServiceLocator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StorePreferredPersonnelFeeRuleRequest;
use App\Http\Requests\Billing\SupersedePreferredPersonnelFeeRuleRequest;
use App\Http\Resources\PreferredPersonnelFeeRuleResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Preferred-personnel fee-rule management API (Plan §13.10, §47; ADR-005; Phase 20A). Super-Admin
 * platform scope; MFA (group) + fresh step-up (route) + idempotency on create. Named actions per
 * transition — no generic status route. Thin.
 */
final class PreferredPersonnelFeeRuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PreferredPersonnelFeeRule::class);

        $query = PreferredPersonnelFeeRule::query()->orderByDesc('effective_from');

        if (in_array($request->string('scope')->value(), ['platform_default', 'service'], true)) {
            $query->where('scope', $request->string('scope')->value());
        }
        if (in_array($request->string('status')->value(), ['draft', 'scheduled', 'active', 'superseded', 'expired', 'cancelled'], true)) {
            $query->where('status', $request->string('status')->value());
        }

        return PreferredPersonnelFeeRuleResource::collection(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function store(StorePreferredPersonnelFeeRuleRequest $request, CreatePreferredPersonnelFeeRule $action): JsonResponse
    {
        $this->authorize('manage', PreferredPersonnelFeeRule::class);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array<string,mixed> $data */
        $data = $request->validated();

        // Resolve the service ULID to its internal id (platform route — no tenant scope).
        if (($data['scope'] ?? null) === 'service' && isset($data['service_id']) && is_string($data['service_id'])) {
            $data['service_id'] = app(PlatformServiceLocator::class)->idForUlid($data['service_id']);
        }

        /** @var array{calculation_type:string,fixed_amount_minor?:int|null,percentage_basis_points?:int|null,currency?:string|null,calculation_basis:string,scope:string,service_id?:int|null,effective_from:string,effective_to?:string|null,change_reason:string} $data */
        return PreferredPersonnelFeeRuleResource::make($action->handle($data, $actor))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PreferredPersonnelFeeRule $preferredPersonnelFeeRule): PreferredPersonnelFeeRuleResource
    {
        $this->authorize('view', $preferredPersonnelFeeRule);

        return PreferredPersonnelFeeRuleResource::make($preferredPersonnelFeeRule);
    }

    public function approve(Request $request, PreferredPersonnelFeeRule $preferredPersonnelFeeRule, ApprovePreferredPersonnelFeeRule $action): PreferredPersonnelFeeRuleResource
    {
        $this->authorize('manage', $preferredPersonnelFeeRule);

        /** @var User $actor */
        $actor = $request->user();

        return PreferredPersonnelFeeRuleResource::make($action->handle($preferredPersonnelFeeRule, $actor));
    }

    public function supersede(SupersedePreferredPersonnelFeeRuleRequest $request, PreferredPersonnelFeeRule $preferredPersonnelFeeRule, SupersedePreferredPersonnelFeeRule $action): JsonResponse
    {
        $this->authorize('manage', $preferredPersonnelFeeRule);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array{calculation_type:string,fixed_amount_minor?:int|null,percentage_basis_points?:int|null,currency?:string|null,calculation_basis:string,effective_from:string,effective_to?:string|null,change_reason:string} $data */
        $data = $request->validated();

        return PreferredPersonnelFeeRuleResource::make($action->handle($preferredPersonnelFeeRule, $data, $actor))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function cancel(Request $request, PreferredPersonnelFeeRule $preferredPersonnelFeeRule, CancelPreferredPersonnelFeeRule $action): PreferredPersonnelFeeRuleResource
    {
        $this->authorize('manage', $preferredPersonnelFeeRule);

        /** @var User $actor */
        $actor = $request->user();

        return PreferredPersonnelFeeRuleResource::make($action->handle($preferredPersonnelFeeRule, $actor));
    }
}
