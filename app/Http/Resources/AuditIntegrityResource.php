<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditChainSegmentVerifier;
use App\Domain\Audit\Support\AuditChainSegmentResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Branch-safe presentation of one segment verification (Phase UI-15; Appendix A §12.4.13).
 *
 * The merchant chain is shared by every branch, so this resource discloses the chain-level
 * verdict and coverage window but counts and names ONLY rows in the caller's assigned branches.
 * A break in another branch is reported as existing, with its category, but without its event
 * reference or time. No hash, previous hash, internal id, context or actor ever appears.
 *
 * @property AuditChainSegmentResult $resource
 */
final class AuditIntegrityResource extends JsonResource
{
    /**
     * @param  list<int>  $branchIds  the caller's assigned branch ids
     */
    public function __construct(AuditChainSegmentResult $result, private readonly array $branchIds)
    {
        parent::__construct($result);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $result = $this->resource;
        $first = $result->rows->first();
        $last = $result->rows->last();

        return [
            'status' => $result->status(),
            'checked_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'segment_limit' => AuditChainSegmentVerifier::SEGMENT_LIMIT,
            'complete_history' => $result->completeHistory,
            // Null when the chain is empty; otherwise the oldest and newest checked row times.
            'coverage_from' => $first === null || $first->created_at === null ? null : $first->created_at->toIso8601String(),
            'coverage_to' => $last === null || $last->created_at === null ? null : $last->created_at->toIso8601String(),
            'branch_records_checked' => $result->rows->filter(fn (AuditLog $row): bool => $this->inScope($row))->count(),
            'first_break' => $this->breakSummary($result),
            // The daily full-chain run persists no result, so there is nothing truthful to show.
            'scheduled_verification_exposed' => false,
        ];
    }

    /** @return array{category: string, within_branch_scope: bool, event: string|null, occurred_at: string|null}|null */
    private function breakSummary(AuditChainSegmentResult $result): ?array
    {
        $row = $result->breakRow;

        if ($row === null || $result->breakCategory === null) {
            return null;
        }

        $visible = $this->inScope($row);

        return [
            'category' => $result->breakCategory,
            'within_branch_scope' => $visible,
            'event' => $visible ? $row->ulid : null,
            'occurred_at' => $visible ? $row->created_at?->toIso8601String() : null,
        ];
    }

    private function inScope(AuditLog $row): bool
    {
        return $row->branch_id !== null && in_array($row->branch_id, $this->branchIds, true);
    }
}
