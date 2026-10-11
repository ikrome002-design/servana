<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Domain\Audit\Enums\AuditReviewView;
use App\Domain\Audit\Models\AuditLog;
use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Shared, allowlisted audit-log filtering + sorting for the merchant and platform
 * read endpoints (Plan §11, §70). All inputs are validated by
 * {@see AuditLogIndexRequest}; this only applies the allowlisted predicates.
 */
trait FiltersAuditLogs
{
    /**
     * @param  Builder<AuditLog>  $query
     * @param  int|null  $branchId  Pre-resolved internal branch id (merchant endpoint only).
     */
    private function applyFilters(Builder $query, AuditLogIndexRequest $request, ?int $branchId = null): void
    {
        $filters = $request->validated();

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['subject_type'])) {
            $query->where('auditable_type', 'like', '%\\'.$filters['subject_type']);
        }

        if (isset($filters['actor']) && is_string($filters['actor'])) {
            // Actor is a global identity; resolve the ULID to its id (or an
            // impossible id so an unknown actor yields an empty result).
            $actorId = User::query()->where('ulid', $filters['actor'])->value('id') ?? -1;
            $query->where('actor_id', $actorId);
        }

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        // Phase UI-15 — a review view is a server-decided action set (AuditReviewView); it can
        // only narrow the segment the route's permission already returns.
        if (isset($filters['view']) && is_string($filters['view'])) {
            $query->whereIn('action', AuditReviewView::from($filters['view'])->actions());
        }

        if (isset($filters['correlation_id']) && is_string($filters['correlation_id'])) {
            $query->where('correlation_id', $filters['correlation_id']);
        }

        // Flags inherit their audit row's merchant and branch, so an existence check against the
        // row id cannot reach a flag outside the already-scoped outer query. A platform row is never
        // flagged: `flagged` is then honestly empty and `unflagged` returns everything.
        if (isset($filters['flagged'])) {
            $flags = static function (QueryBuilder $flag): void {
                $flag->select(DB::raw(1))
                    ->from('audit_flagged_events')
                    ->whereColumn('audit_flagged_events.audit_log_id', 'audit_logs.id');
            };
            $filters['flagged'] === 'flagged' ? $query->whereExists($flags) : $query->whereNotExists($flags);
        }

        $this->applySort($query, is_string($filters['sort'] ?? null) ? $filters['sort'] : '-created_at');
    }

    /** @param Builder<AuditLog> $query */
    private function applySort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        $query->orderBy($column, $direction)->orderByDesc('id');
    }

    private function perPage(AuditLogIndexRequest $request): int
    {
        $perPage = $request->validated()['per_page'] ?? 25;

        return (int) $perPage;
    }
}
