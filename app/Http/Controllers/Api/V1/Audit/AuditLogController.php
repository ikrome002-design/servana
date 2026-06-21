<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Merchant audit-log read API (Scope §4.8, Plan §70).
 *
 * Read-only and field-masked. Scoped to the caller's merchant; a branch-scoped
 * Audit user sees only its assigned branch(es). `audit.view_full` is enforced by
 * route middleware; row-level access is enforced by AuditLogPolicy. Foreign-tenant
 * ULIDs 404 with no existence leak (project posture).
 */
final class AuditLogController extends Controller
{
    use FiltersAuditLogs;

    public function __construct(private readonly TenantContext $context) {}

    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()
            ->with('branch')
            ->where('merchant_id', $this->context->merchantId());

        // A branch-scoped Audit user is confined to its assigned branch rows.
        if ($this->context->isBranchScoped()) {
            $query->whereIn('branch_id', $this->context->branchIds());
        }

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
