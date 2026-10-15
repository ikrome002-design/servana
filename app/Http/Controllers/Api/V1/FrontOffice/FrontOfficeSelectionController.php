<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FrontOffice;

use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Enums\StaffEmploymentStatus;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontOffice\FrontOfficePersonnelOptionRequest;
use App\Http\Requests\FrontOffice\FrontOfficeServiceOptionRequest;
use App\Http\Resources\FrontOfficePersonnelOptionResource;
use App\Http\Resources\FrontOfficeServiceOptionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * LOCAL-UAT-001, LUAT-D01 — Front Office operational selection reads (product-owner decision:
 * "Narrow FO endpoint").
 *
 * The walk-in, appointment, appointment-detail, queue-entry and queue-transfer screens must let Front
 * Office choose a service and eligible personnel. They used the catalogue reads (`GET /services`,
 * `GET /services/{service}/eligibility`), which are authorized by `service.view` — a Branch Manager
 * key Front Office does not and must not hold — so in real use every picker was refused with 403.
 *
 * Following the `hr/service-options` and `branch/personnel-options` precedents, these reads are
 * gated by the permission the caller ALREADY holds for the workflow named by `purpose`; no permission
 * key was created and no role grant was widened. They return only what a picker needs: active
 * services of the acting branch, and active, employed personnel with ACTIVE eligibility for one active
 * service. Nothing is written. A choice made here reserves nothing and authorizes nothing: every
 * create/assign/transfer still re-checks eligibility, availability, state and scope on submission.
 * Both collections use the Plan §23 default 25 / maximum 100 pagination contract.
 */
final class FrontOfficeSelectionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function services(FrontOfficeServiceOptionRequest $request): AnonymousResourceCollection
    {
        abort_unless($this->context->can($request->requiredPermission()), 403);

        // Tenancy global scopes bound this to the acting merchant + branch; the explicit predicates
        // are defence in depth (cf. BranchPersonnelOptionController).
        $query = Service::query()
            ->active()
            ->where('merchant_id', $this->context->merchantId());

        if ($this->context->isBranchScoped()) {
            $query->whereIn('branch_id', $this->context->branchIds());
        }

        return FrontOfficeServiceOptionResource::collection(
            $query->orderBy('name')->orderBy('ulid')->paginate(ApiPagination::perPage($request->validated())),
        );
    }

    public function personnel(FrontOfficePersonnelOptionRequest $request, Service $service): AnonymousResourceCollection
    {
        abort_unless($this->context->can($request->requiredPermission()), 403);

        // `{service}` resolves inside tenant/branch scope (foreign → 404). An archived or otherwise
        // non-bookable service is not a selection target: 404, never its personnel.
        abort_unless(
            $service->status === ServiceStatus::Active
                && $service->merchant_id === $this->context->merchantId()
                && (! $this->context->isBranchScoped() || in_array($service->branch_id, $this->context->branchIds(), true)),
            404,
        );

        $eligible = ServicePersonnelEligibility::query()
            ->where('service_id', $service->id)
            ->where('active', true)
            ->whereHas('staffProfile', static function (Builder $staff) use ($service): void {
                $staff->where('is_active', true)
                    ->where('employment_status', StaffEmploymentStatus::Employed->value)
                    ->where('primary_branch_id', $service->branch_id);
            })
            ->with('staffProfile')
            ->orderBy(StaffProfile::query()->select('display_name')
                ->whereColumn('staff_profiles.id', 'service_personnel_eligibility.staff_profile_id'))
            ->orderBy('staff_profile_id')
            ->paginate(ApiPagination::perPage($request->validated()));

        return FrontOfficePersonnelOptionResource::collection($eligible);
    }
}
