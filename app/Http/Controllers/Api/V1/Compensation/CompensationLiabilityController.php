<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compensation;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Services\CompensationLiabilityReadModel;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compensation\CompensationLiabilityIndexRequest;
use App\Http\Resources\CompensationLiabilityEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 20G Finance compensation-liability READ API (Plan §61/§80, §19.3; `compensation.liability.view`).
 * Merchant scope, masked, read-only. Server-side scope is authoritative: a branch-scoped role sees only
 * its assigned branches; an optional `branch_ulid` filter narrows within the allowed set (a foreign or
 * out-of-scope branch → 404, no existence leak). All totals are server-derived integer minor units,
 * grouped by currency (never combined across currencies). Thin: authorize → resolve scope + filters →
 * read model → masked payload.
 */
final class CompensationLiabilityController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CompensationLiabilityReadModel $readModel,
    ) {}

    public function summary(CompensationLiabilityIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CommissionLedgerEntry::class);

        [$merchantId, $branchIds, $filters] = $this->scope($request);

        return response()->json(['data' => $this->readModel->summary($merchantId, $branchIds, $filters)]);
    }

    public function index(CompensationLiabilityIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommissionLedgerEntry::class);

        [$merchantId, $branchIds, $filters] = $this->scope($request);
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);

        $entries = $this->readModel->entries(
            $merchantId,
            $branchIds,
            $filters,
            $perPage,
            (int) $request->integer('page', 1),
        );

        return CompensationLiabilityEntryResource::collection($entries);
    }

    /**
     * Resolve the merchant id, the effective branch restriction, and the server-side filter set from the
     * tenant context + validated request. `branchIds === null` means the whole merchant.
     *
     * @return array{0: int, 1: list<int>|null, 2: array<string, mixed>}
     */
    private function scope(CompensationLiabilityIndexRequest $request): array
    {
        $merchantId = (int) $this->context->merchantId();

        $branchIds = $this->context->isBranchScoped() ? $this->context->branchIds() : null;

        if ($request->filled('branch_ulid')) {
            $branchId = (int) MerchantBranch::query()
                ->where('ulid', (string) $request->string('branch_ulid'))
                ->value('id');
            if ($branchId === 0 || ($branchIds !== null && ! in_array($branchId, $branchIds, true))) {
                abort(Response::HTTP_NOT_FOUND);
            }
            $branchIds = [$branchId];
        }

        $filters = [
            'liability_type' => $request->filled('liability_type') ? (string) $request->string('liability_type') : null,
            'entry_type' => $request->filled('entry_type') ? (string) $request->string('entry_type') : null,
            'status' => $request->filled('status') ? (string) $request->string('status') : null,
            'currency' => $request->filled('currency') ? (string) $request->string('currency') : null,
            'date_from' => $request->filled('date_from') ? (string) $request->string('date_from') : null,
            'date_to' => $request->filled('date_to') ? (string) $request->string('date_to') : null,
            'staff_profile_id' => null,
        ];

        if ($request->filled('staff_profile_ulid')) {
            $staffId = (int) StaffProfile::query()
                ->where('ulid', (string) $request->string('staff_profile_ulid'))
                ->value('id');
            if ($staffId === 0) {
                abort(Response::HTTP_NOT_FOUND);
            }
            $filters['staff_profile_id'] = $staffId;
        }

        return [$merchantId, $branchIds, $filters];
    }
}
