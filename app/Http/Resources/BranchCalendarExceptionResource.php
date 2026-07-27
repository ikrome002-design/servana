<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Branches\Enums\CalendarExceptionType;
use App\Domain\Branches\Models\BranchCalendarException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A branch calendar exception (REM-SCR-002B).
 *
 * The table has no `ulid` column (it is as-built branch configuration, not an externally
 * referenced business record), so the composite `(date, type)` is the natural public identity —
 * it is exactly what `UNIQUE(branch_id, date, type)` guarantees and what the scheduling gate
 * looks up. `closes_branch` is a derived, explicit flag so the UI never has to re-implement the
 * closure-vs-modified-hours rule that lives in the domain.
 *
 * No internal id, no `branch_id`/`merchant_id`, no `created_by` user id is exposed.
 *
 * @mixin BranchCalendarException
 */
final class BranchCalendarExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date->toDateString(),
            'type' => $this->type->value,
            'closes_branch' => $this->type !== CalendarExceptionType::ModifiedHours,
            'opens_at' => $this->opens_at,
            'closes_at' => $this->closes_at,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
