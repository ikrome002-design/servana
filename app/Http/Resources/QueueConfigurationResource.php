<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Branches\Models\BranchDayRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Queue configuration payload (Plan §37; Phase 16B) — the queue-operational
 * settings on today's Branch Day aggregate. Read by Branch Manager (configure) and
 * Front Office (operate). `active_count` is injected by the controller (current
 * active queue size) so the UI can validate capacity changes. No entry-level
 * mutation capability is exposed here.
 *
 * @mixin BranchDayRecord
 */
final class QueueConfigurationResource extends JsonResource
{
    public function __construct(BranchDayRecord $resource, private readonly int $activeCount = 0)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'branch_day_id' => $this->ulid,
            'business_date' => $this->business_date->toDateString(),
            'day_status' => $this->status->value,
            'queue_is_open' => $this->queue_is_open,
            'effective_queue_open' => $this->effectiveQueueOpen(),
            'queue_capacity' => $this->queue_capacity,
            'queue_default_assignment_mode' => $this->queue_default_assignment_mode->value,
            'active_count' => $this->activeCount,
        ];
    }
}
