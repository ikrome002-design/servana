<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Support\AuditValueMasker;
use App\Http\Resources\Concerns\HasCapabilities;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only, field-masked flagged-event payload (Plan §13.2, §70, §74; Phase 19).
 *
 * Exposes the flag ULID, status, review metadata, and a MASKED summary of the linked
 * (immutable) audit row — action, severity, masked actor + context via
 * {@see AuditValueMasker}. Never exposes internal ids, hash-chain columns, storage paths,
 * signed URLs, tokens, SQLSTATE, or unmasked PII. Assignee/resolver appear as masked
 * emails only.
 *
 * @mixin AuditFlaggedEvent
 */
final class AuditFlaggedEventResource extends JsonResource
{
    use HasCapabilities;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $masker = app(AuditValueMasker::class);

        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'review_notes' => $this->review_notes,
            'assigned_to' => $this->maskedUser($this->whenLoaded('assignee')),
            'resolved_by' => $this->maskedUser($this->whenLoaded('resolver')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'audit_event' => $this->whenLoaded('auditLog', function () use ($masker): ?array {
                /** @var AuditLog|null $log */
                $log = $this->auditLog;

                if ($log === null) {
                    return null;
                }

                return [
                    'id' => $log->ulid,
                    'action' => $log->action,
                    'severity' => $log->severity->value,
                    'actor' => $log->actor_label !== null ? AuditValueMasker::maskEmail($log->actor_label) : null,
                    'subject_type' => $log->auditable_type !== null ? class_basename($log->auditable_type) : null,
                    'context' => $masker->mask($log->context ?? []),
                    'occurred_at' => $log->created_at?->toIso8601String(),
                ];
            }),
            'can' => $this->capabilities($request, [
                'update_status' => 'updateStatus',
                'resolve_metadata' => 'resolveMetadata',
            ]),
        ];
    }

    private function maskedUser(mixed $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return AuditValueMasker::maskEmail($user->email);
    }
}
