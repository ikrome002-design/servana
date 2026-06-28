<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Domain\Catalogue\Actions\AssignEligibility;
use App\Domain\Catalogue\Actions\RevokeEligibility;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreEligibilityRequest;
use App\Http\Resources\ServicePersonnelEligibilityResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Personnel-service eligibility, nested under a service (Plan §39). HR owns
 * mutation (`personnel.eligibility.manage`, route-gated); reads also serve the
 * Branch Manager catalogue's read-only eligibility summary (`service.view`). The
 * `{service}` binding resolves inside tenant/branch scope (foreign 404). Service
 * and personnel must share branch + merchant (DB composite FKs + action guard).
 */
final class ServiceEligibilityController extends Controller
{
    public function index(Service $service): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ServicePersonnelEligibility::class);

        $eligibilities = $service->eligibilities()
            ->with('staffProfile')
            ->orderByDesc('active')
            ->get();

        return ServicePersonnelEligibilityResource::collection($eligibilities);
    }

    public function store(StoreEligibilityRequest $request, Service $service, AssignEligibility $action): JsonResponse
    {
        $this->authorize('manage', ServicePersonnelEligibility::class);

        /** @var StaffProfile $staff */
        $staff = StaffProfile::query()
            ->where('ulid', (string) $request->validated()['staff_profile_id'])
            ->firstOr(fn () => abort(404));

        abort_if(
            $staff->merchant_id !== $service->merchant_id || $staff->primary_branch_id !== $service->branch_id,
            422,
            'Personnel must belong to the same branch as the service.',
        );

        /** @var User $actor */
        $actor = $request->user();
        $eligibility = $action->handle($service, $staff, $actor);

        return ServicePersonnelEligibilityResource::make($eligibility->load(['service', 'staffProfile']))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Service $service, StaffProfile $staff, RevokeEligibility $action): ServicePersonnelEligibilityResource
    {
        $this->authorize('manage', ServicePersonnelEligibility::class);

        /** @var ServicePersonnelEligibility $eligibility */
        $eligibility = ServicePersonnelEligibility::query()
            ->where('service_id', $service->id)
            ->where('staff_profile_id', $staff->id)
            ->where('active', true)
            ->firstOr(fn () => abort(404));

        /** @var User $actor */
        $actor = request()->user();

        return ServicePersonnelEligibilityResource::make($action->handle($eligibility, $actor)->load(['service', 'staffProfile']));
    }
}
