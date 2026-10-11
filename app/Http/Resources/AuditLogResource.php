<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Support\AuditValueMasker;
use App\Http\Resources\Concerns\HasCapabilities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only, field-masked audit-log representation (Plan §70, §74; ADR-008).
 *
 * All potentially sensitive values are masked SERVER-SIDE here: the actor's email
 * (actor_label) and every value inside `context` pass through {@see AuditValueMasker}.
 * Internal sequential ids, raw ip, and the hash chain columns are never exposed;
 * external references are ULIDs only. There is no path to request unmasked data
 * (exceptional, reason-gated unmasking is Phase 19).
 *
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    use HasCapabilities;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $masker = app(AuditValueMasker::class);

        return [
            'id' => $this->ulid,
            'action' => $this->action,
            'severity' => $this->severity->value,
            'actor' => $this->actor_label !== null
                ? AuditValueMasker::maskEmail($this->actor_label)
                : null,
            // branch_id is nullable (platform-scoped logs have no branch), so a loaded
            // relation can still be null.
            'branch' => $this->whenLoaded('branch', fn (): ?string => $this->branch === null ? null : $this->branch->ulid),
            'subject_type' => $this->auditable_type !== null ? class_basename($this->auditable_type) : null,
            'context' => $masker->mask($this->context ?? []),
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            // Phase UI-15 — the MUTABLE review status of the latest flag, kept apart from the
            // immutable `severity` above. Present only where the merchant read eager-loads flags.
            'review' => $this->whenLoaded('flags', fn (): ?array => $this->reviewSummary()),
            'can' => $this->capabilities($request, [
                'view' => 'view',
            ]),
        ];
    }

    /**
     * Latest flag (by creation) plus how many review records this row has. Status only — notes,
     * assignee and resolver stay on the flagged-event resource.
     *
     * @return array{flag: string, status: string, flag_count: int}|null
     */
    private function reviewSummary(): ?array
    {
        /** @var Collection<int, AuditFlaggedEvent> $flags */
        $flags = $this->flags;
        $latest = $flags->sortByDesc('id')->first();

        if ($latest === null) {
            return null;
        }

        return [
            'flag' => $latest->ulid,
            'status' => $latest->status->value,
            'flag_count' => $flags->count(),
        ];
    }
}
