<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Domain\Audit\Enums\AuditDomain;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Merchant audit-log read API (Scope §4.8, Plan §19.2/§19.3, §70; Phase 19).
 *
 * Read-only, field-masked, and domain-segmented. Every read is confined to the
 * caller's merchant AND its actively-assigned branch(es); merchant-level rows
 * (branch_id null) are never exposed here (Phase 19 decision Q2). The canonical
 * §19.2 Audit read keys are enforced per route:
 *   - `audit.branch_events.view` → general branch events ({@see index}/{@see show});
 *   - `audit.finance.view` / `finance.audit.view` → finance domain ({@see finance});
 *   - `audit.compensation.view` → compensation domain ({@see compensation}).
 * Row-level access is enforced by AuditLogPolicy; foreign-tenant ULIDs 404 with
 * no existence leak.
 */
final class AuditLogController extends Controller
{
    use FiltersAuditLogs;

    public function __construct(private readonly TenantContext $context) {}

    /** General branch audit events (excludes the finance + compensation segments). */
    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $excluded = array_merge(
            AuditEvent::actionsIn(AuditDomain::Finance),
            AuditEvent::actionsIn(AuditDomain::Compensation),
        );

        $query = $this->scopedBranchQuery()->whereNotIn('action', $excluded);
        $this->applyFilters($query, $request, $this->resolveBranchId($request));

        return AuditLogResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    /** Finance-domain branch audit events (Audit `audit.finance.view` / Finance `finance.audit.view`). */
    public function finance(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = $this->scopedBranchQuery()->whereIn('action', AuditEvent::actionsIn(AuditDomain::Finance));
        $this->applyFilters($query, $request, $this->resolveBranchId($request));

        return AuditLogResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    /** Compensation-domain branch audit events (empty until Phases 20F–20H emit them). */
    public function compensation(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = $this->scopedBranchQuery()->whereIn('action', AuditEvent::actionsIn(AuditDomain::Compensation));
        $this->applyFilters($query, $request, $this->resolveBranchId($request));

        return AuditLogResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        // Foreign-merchant row → 404 (no existence leak), then branch-scope policy.
        abort_if($auditLog->merchant_id !== $this->context->merchantId(), 404);
        $this->authorize('view', $auditLog);

        return AuditLogResource::make($auditLog->load('branch'));
    }

    /**
     * Base merchant query, confined to the caller's actively-assigned branch(es)
     * and excluding merchant-level (branch_id null) rows (Phase 19 Q2). Empty
     * branch assignment yields an empty result (established denial posture).
     *
     * @return Builder<AuditLog>
     */
    private function scopedBranchQuery(): Builder
    {
        return AuditLog::query()
            ->with('branch')
            ->where('merchant_id', $this->context->merchantId())
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $this->context->branchIds());
    }

    /** Resolve a branch ULID filter to an internal id within the merchant. */
    private function resolveBranchId(AuditLogIndexRequest $request): ?int
    {
        $ulid = $request->validated()['branch'] ?? null;

        if (! is_string($ulid)) {
            return null;
        }

        $branch = MerchantBranch::query()
            ->where('merchant_id', $this->context->merchantId())
            ->where('ulid', $ulid)
            ->first();

        // Unknown/foreign branch ULID → impossible id so the result is empty (no leak).
        return $branch !== null ? $branch->id : -1;
    }
}
