<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Concerns;

use App\Domain\Scheduling\Models\ServiceSession;

/**
 * Builds the SAFE audit context for a service-session event (Plan §70; Phase 16C).
 *
 * Only safe ids and operational facts — merchant/branch/service-session/source
 * queue-entry/client/service/personnel ULIDs, status, and the preferred-personnel
 * honoured/overridden flag — are included. Never full phone/email, blind index,
 * tokens, session ids, headers, full bodies, raw unsanitised notes, or sequential
 * database ids. Per-action callers merge extra safe keys (prev/new state, sanitised
 * reason).
 */
trait BuildsServiceSessionAudit
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function serviceSessionAuditContext(ServiceSession $session, array $extra = []): array
    {
        $base = [
            'service_session_id' => $session->ulid,
            'queue_entry_id' => $session->queueEntry?->ulid,
            'client_id' => $session->client?->ulid,
            'service_id' => $session->service?->ulid,
            'personnel_id' => $session->personnel?->ulid,
            'status' => $session->status->value,
            'preferred_personnel_honored' => $session->preferred_personnel_honored,
        ];

        return array_merge($base, $extra);
    }
}
