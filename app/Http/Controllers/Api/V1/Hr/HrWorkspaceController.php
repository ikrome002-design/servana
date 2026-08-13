<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Hr;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Hr\Services\HrWorkspaceReadModel;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\HrAuditActivityIndexRequest;
use App\Http\Requests\Hr\HrWorkspaceOverviewRequest;
use App\Http\Resources\AuditLogResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * UI-11 HR read presentation. Existing route permissions and assigned-branch
 * context authorize both reads; this controller exposes no mutation.
 */
final class HrWorkspaceController extends Controller
{
    public function show(
        HrWorkspaceOverviewRequest $request,
        HrWorkspaceReadModel $readModel,
    ): JsonResponse {
        return response()->json(['data' => ['overview' => $readModel->read()]]);
    }

    public function audit(
        HrAuditActivityIndexRequest $request,
        HrWorkspaceReadModel $readModel,
    ): AnonymousResourceCollection {
        $filters = $request->validated();
        $branch = $readModel->branch();
        $query = AuditLog::query()
            ->where('merchant_id', $branch->merchant_id)
            ->where('branch_id', $branch->id)
            ->where(static function (Builder $events): void {
                $events
                    ->where('action', 'like', 'invitation.%')
                    ->orWhere('action', 'like', 'membership.%')
                    ->orWhere('action', 'like', 'branch_assignment.%')
                    ->orWhere('action', 'like', 'permission.override.%')
                    ->orWhere('action', 'like', 'personnel_eligibility.%')
                    ->orWhere('action', 'like', 'personnel_availability.%')
                    ->orWhere('action', 'like', 'compensation.plan.%')
                    ->orWhere('action', 'like', 'commission_rule.%')
                    ->orWhere('action', 'like', 'payout_run.%');
            })
            ->with('branch:id,ulid');

        if (isset($filters['domain'])) {
            $this->applyDomainFilter($query, $filters['domain']);
        }
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

    /** @param Builder<AuditLog> $query */
    private function applyDomainFilter(Builder $query, string $domain): void
    {
        $query->where(static function (Builder $events) use ($domain): void {
            match ($domain) {
                'staff' => $events
                    ->where('action', 'like', 'invitation.%')
                    ->orWhere('action', 'like', 'membership.%')
                    ->orWhere('action', 'like', 'branch_assignment.%')
                    ->orWhere('action', 'like', 'permission.override.%'),
                'readiness' => $events
                    ->where('action', 'like', 'personnel_eligibility.%')
                    ->orWhere('action', 'like', 'personnel_availability.%'),
                'compensation' => $events
                    ->where('action', 'like', 'compensation.plan.%')
                    ->orWhere('action', 'like', 'commission_rule.%'),
                'payout' => $events->where('action', 'like', 'payout_run.%'),
                default => null,
            };
        });
    }
}
