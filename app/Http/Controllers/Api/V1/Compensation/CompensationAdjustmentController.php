<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Actions\RecordCompensationAdjustment;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\CompensationAdjustmentIndexRequest;
use App\Http\Requests\Compensation\StoreCompensationAdjustmentRequest;
use App\Http\Resources\CompensationAdjustmentResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 20G Finance compensation-adjustment API (Plan §60/§61, §19.3). Reads are merchant-scoped +
 * masked under `compensation.liability.view`; creating a MANUAL additive adjustment requires
 * `compensation.adjustment.create` with fresh MFA step-up + idempotency (route middleware) and produces
 * a high-severity audit (domain action). The adjustment is standalone `manual` — the schema forbids a
 * Finance-created source-linked row — and its `branch_id` is derived from the staff profile's primary
 * branch (never client-supplied). There is NO update or delete route (append-only).
 */
final class CompensationAdjustmentController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RecordCompensationAdjustment $adjustments,
    ) {}

    public function index(CompensationAdjustmentIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompensationAdjustment::class);

        $query = CompensationAdjustment::query()->with(['staffProfile:id,ulid,display_name', 'branch:id,ulid']);

        if ($this->context->isBranchScoped()) {
            $query->whereIn('branch_id', $this->context->branchIds());
        }
        $this->applyFilters($query, $request);

        return CompensationAdjustmentResource::collection(
            $query->orderByDesc('id')->paginate(min(max((int) $request->integer('per_page', 25), 1), 100))->withQueryString(),
        );
    }

    public function show(CompensationAdjustment $compensationAdjustment): CompensationAdjustmentResource
    {
        $this->authorize('view', $compensationAdjustment);

        if ($this->context->isBranchScoped()
            && ! in_array($compensationAdjustment->branch_id, $this->context->branchIds(), true)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return CompensationAdjustmentResource::make(
            $compensationAdjustment->load(['staffProfile:id,ulid,display_name', 'branch:id,ulid']),
        );
    }

    public function store(StoreCompensationAdjustmentRequest $request): JsonResponse
    {
        $this->authorize('create', CompensationAdjustment::class);

        /** @var StaffProfile|null $staff */
        $staff = StaffProfile::query()->where('ulid', (string) $request->validated('staff_profile_ulid'))->first();
        if ($staff === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Branch is server-derived from the staff profile's primary branch; a branch-scoped actor may
        // only adjust staff whose branch is in their assigned set (no cross-branch write).
        $branchId = (int) $staff->primary_branch_id;
        if ($this->context->isBranchScoped() && ! in_array($branchId, $this->context->branchIds(), true)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        /** @var User $actor */
        $actor = $request->user();

        $adjustment = $this->adjustments->manual(
            $staff,
            $branchId,
            (int) $request->validated('amount_minor'),
            (string) $request->validated('currency'),
            (string) $request->validated('reason'),
            $actor,
            $actor,
        );

        return CompensationAdjustmentResource::make(
            $adjustment->load(['staffProfile:id,ulid,display_name', 'branch:id,ulid']),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** @param  Builder<CompensationAdjustment>  $query */
    private function applyFilters(Builder $query, CompensationAdjustmentIndexRequest $request): void
    {
        if ($request->filled('staff_profile_ulid')) {
            $staffId = (int) StaffProfile::query()->where('ulid', (string) $request->string('staff_profile_ulid'))->value('id');
            $query->where('staff_profile_id', $staffId ?: -1);
        }
        if ($request->filled('branch_ulid')) {
            $branchId = (int) MerchantBranch::query()->where('ulid', (string) $request->string('branch_ulid'))->value('id');
            $query->where('branch_id', $branchId ?: -1);
        }
        if ($request->filled('adjustment_type')) {
            $query->where('adjustment_type', (string) $request->string('adjustment_type'));
        }
        if ($request->filled('currency')) {
            $query->where('currency', (string) $request->string('currency'));
        }
    }
}
