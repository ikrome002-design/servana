<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Compensation\Actions\CreateEarningsQuery;
use App\Domain\Compensation\Enums\EarningsQuerySubjectType;
use App\Domain\Compensation\Enums\EarningsQueryType;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\EarningsQueryIndexRequest;
use App\Http\Requests\Compensation\StoreEarningsQueryRequest;
use App\Http\Resources\EarningsQueryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 20H personnel own-scope earnings-query API (Plan §63, §19.3; §H12). Personnel raise + read their
 * OWN queries only; the subject must be one of their own facts (validated in the domain action — a
 * foreign/non-existent subject 404s with no existence leak). The acting staff profile is derived from
 * the authenticated membership; status/assignment/tenant fields are server-owned. Personnel see status +
 * resolution note only; a monetary correction is an additive adjustment created by Finance, never a
 * ledger edit.
 */
final class PersonnelEarningsQueryController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CreateEarningsQuery $createAction,
    ) {}

    public function index(EarningsQueryIndexRequest $request): AnonymousResourceCollection
    {
        abort_unless($this->context->can('personnel.my_earnings_query.create'), 403);
        $staff = $this->ownStaffProfileOrFail();

        $query = EarningsQuery::query()->where('staff_profile_id', $staff->id)->with('resolvedAdjustment:id,ulid');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        return EarningsQueryResource::collection(
            $query->orderByDesc('id')->paginate(min(max((int) $request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function show(EarningsQuery $earningsQuery): EarningsQueryResource
    {
        $this->authorize('viewOwn', $earningsQuery);
        $staff = $this->ownStaffProfileOrFail();

        // Own-scope: a same-tenant query raised by another staff member is 404 (no existence leak).
        if ($earningsQuery->staff_profile_id !== $staff->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return EarningsQueryResource::make($earningsQuery->load(['staffProfile:id,ulid,display_name', 'resolvedAdjustment:id,ulid']));
    }

    public function store(StoreEarningsQueryRequest $request): JsonResponse
    {
        $this->authorize('create', EarningsQuery::class);
        $staff = $this->ownStaffProfileOrFail();

        $query = $this->createAction->handle(
            $staff,
            EarningsQuerySubjectType::from((string) $request->validated('subject_type')),
            (string) $request->validated('subject_ulid'),
            EarningsQueryType::from((string) $request->validated('query_type')),
            (string) $request->validated('body'),
        );

        return EarningsQueryResource::make($query->load(['staffProfile:id,ulid,display_name', 'resolvedAdjustment:id,ulid']))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function ownStaffProfileOrFail(): StaffProfile
    {
        $merchantUser = $this->context->merchantUser();

        $profile = $merchantUser === null
            ? null
            : StaffProfile::query()->where('merchant_user_id', $merchantUser->id)->first();

        if ($profile === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $profile;
    }
}
