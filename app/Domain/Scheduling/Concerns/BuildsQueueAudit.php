<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Concerns;

use App\Domain\Scheduling\Models\QueueEntry;

/**
 * Builds the SAFE audit context for a queue-entry event (Plan §70; Phase 16B).
 *
 * Only safe ids and operational facts — merchant/branch/queue-entry/client/service
 * ULIDs, assignment mode, status, position, and assigned personnel ULID — are
 * included. Never full phone/email, blind index, tokens, session ids, headers, full
 * bodies, or sequential database ids. Per-action callers merge extra safe keys
 * (prev/new state, prev/new position, prev/new personnel ULIDs, sanitised reason).
 */
trait BuildsQueueAudit
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function queueAuditContext(QueueEntry $entry, array $extra = []): array
    {
        $client = $entry->client;
        $service = $entry->service;

        $base = [
            'queue_entry_id' => $entry->ulid,
            'client_id' => $client?->ulid,
            'service_id' => $service?->ulid,
            'assignment_mode' => $entry->assignment_mode->value,
            'status' => $entry->status->value,
            'position' => $entry->position,
            'assigned_personnel_id' => $entry->assignedPersonnel?->ulid,
        ];

        return array_merge($base, $extra);
    }
}
