<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Merchants\Actions\DeactivateMerchant;
use App\Domain\Merchants\Actions\ReactivateMerchant;
use App\Domain\Merchants\Actions\SuspendMerchant;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\MerchantGovernanceRequest;
use App\Http\Resources\MerchantRegistrationMonitorResource;
use App\Http\Resources\PlatformMerchantResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform merchant governance (Plan §22, §24.1; Phase 20B). Super-Admin platform scope (no merchant
 * tenant context): registration monitoring, merchant list/detail, and the operational suspend /
 * reactivate / deactivate mutations (each requires a mandatory reason + fresh step-up on the route).
 * Mutations touch `merchants.status` ONLY — never billing status, never a subscription/payment row.
 * NO merchant-creation, first-admin, impersonation, manual-payment, or billing-recovery endpoint
 * exists here. Thin: authorize → action → resource.
 */
final class PlatformMerchantGovernanceController extends Controller
{
    public function registrationMonitor(Request $request): AnonymousResourceCollection
    {
        $this->authorize('monitorRegistrations', Merchant::class);

        $query = Merchant::query()->orderByDesc('id');
        $this->applyStatusFilter($query, $request);

        return MerchantRegistrationMonitorResource::collection(
            $query->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewGovernance', Merchant::class);

        $query = Merchant::query()->orderByDesc('id');
        $this->applyStatusFilter($query, $request);

        return PlatformMerchantResource::collection(
            $query->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(Merchant $merchant): PlatformMerchantResource
    {
        $this->authorize('viewGovernance', Merchant::class);

        return PlatformMerchantResource::make($merchant);
    }

    public function suspend(MerchantGovernanceRequest $request, Merchant $merchant, SuspendMerchant $action): PlatformMerchantResource
    {
        $this->authorize('suspend', $merchant);

        return PlatformMerchantResource::make($action->handle($merchant, $this->reason($request), $this->actor($request)));
    }

    public function reactivate(MerchantGovernanceRequest $request, Merchant $merchant, ReactivateMerchant $action): PlatformMerchantResource
    {
        $this->authorize('reactivate', $merchant);

        return PlatformMerchantResource::make($action->handle($merchant, $this->reason($request), $this->actor($request)));
    }

    public function deactivate(MerchantGovernanceRequest $request, Merchant $merchant, DeactivateMerchant $action): PlatformMerchantResource
    {
        $this->authorize('deactivate', $merchant);

        return PlatformMerchantResource::make($action->handle($merchant, $this->reason($request), $this->actor($request)));
    }

    /** @param  Builder<Merchant>  $query */
    private function applyStatusFilter(Builder $query, Request $request): void
    {
        $status = $request->string('status')->value();
        $allowed = array_map(static fn (MerchantStatus $s): string => $s->value, MerchantStatus::cases());
        if (in_array($status, $allowed, true)) {
            $query->where('status', $status);
        }
    }

    private function reason(MerchantGovernanceRequest $request): string
    {
        /** @var array{reason:string} $data */
        $data = $request->validated();

        return $data['reason'];
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }
}
