<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\BranchAuditVisibilityIndexRequest;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Masked assigned-branch timeline; raw Audit-account routes remain inaccessible. */
final class BranchAuditVisibilityController extends Controller
{
    public function index(
        BranchAuditVisibilityIndexRequest $request,
        MerchantBranch $branch,
    ): AnonymousResourceCollection {
        $filters = $request->validated();
        $query = AuditLog::query()
            ->where('merchant_id', $branch->merchant_id)
            ->where('branch_id', $branch->id)
            ->with('branch:id,ulid');

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return AuditLogResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }
}
