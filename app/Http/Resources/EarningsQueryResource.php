<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Compensation\Models\EarningsQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 20H earnings-query masked read (Plan §63). Exposes the query ULID, the subject TYPE (not the
 * internal subject id), the query type + body, status, triage routing role, resolution note, the public
 * ULID of any resolving compensation adjustment, and the responded timestamp. Internal numeric ids and
 * actor ids never leave the server; personnel see status + resolution note only. A monetary correction
 * is an additive adjustment (referenced here), never a ledger edit.
 *
 * @mixin EarningsQuery
 */
final class EarningsQueryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'staff_profile_id' => $this->staffProfile === null ? null : $this->staffProfile->ulid,
            'subject_type' => $this->subject_type->value,
            'query_type' => $this->query_type->value,
            'body' => $this->body,
            'status' => $this->status->value,
            'assigned_role' => $this->assigned_role?->value,
            'resolution_note' => $this->resolution_note,
            'resolved_adjustment_id' => $this->resolvedAdjustment === null ? null : $this->resolvedAdjustment->ulid,
            // Explicit ternary (not ?->) so the generator publishes the genuinely-nullable type: an open
            // query has no responded_at until Finance resolves/rejects it.
            'responded_at' => $this->responded_at === null ? null : $this->responded_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
