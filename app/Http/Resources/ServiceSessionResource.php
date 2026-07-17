<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Compensation\Services\CommissionPreviewService;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Service-session payload (Plan §25.2; guardrail §6.4; Phase 16C). Exposes the
 * service-session ULID, source queue-entry ULID, status, timestamps, a MASKED client
 * summary, the service, the assigned personnel ULID + display name, the
 * preferred-personnel execution flag, safe notes, and — for a completed session — the
 * typed NON-PAYABLE commission preview (always `earned: false`, `payable: false`).
 * Internal bigint ids, full phone/email, the blind index, audit internals, SQLSTATE,
 * constraint names, and any earned/payable commission claim are NEVER serialized. The
 * `can` map is state-aware (policy permission AND current legal transition) — UX only.
 *
 * @mixin ServiceSession
 */
final class ServiceSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'queue_entry_id' => $this->whenLoaded('queueEntry', function (): ?string {
                /** @var QueueEntry|null $entry */
                $entry = $this->queueEntry;

                return $entry === null ? null : $entry->ulid;
            }),
            'started_at' => $this->started_at === null ? null : $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at === null ? null : $this->completed_at->toIso8601String(),
            'cancelled_at' => $this->cancelled_at === null ? null : $this->cancelled_at->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,
            'preferred_personnel_honored' => $this->preferred_personnel_honored,
            'service' => $this->whenLoaded('service', function (): array {
                /** @var Service $service */
                $service = $this->service;

                return ['id' => $service->ulid, 'name' => $service->name, 'duration_minutes' => $service->duration_minutes];
            }),
            'client' => $this->whenLoaded('client', function (): array {
                /** @var Client $client */
                $client = $this->client;

                return [
                    'id' => $client->ulid,
                    'full_name' => $client->full_name,
                    'phone_masked' => $client->maskedPhone(),
                    'phone_last_four' => $client->phone_last_four,
                ];
            }),
            'personnel' => $this->whenLoaded('personnel', function (): ?array {
                /** @var StaffProfile|null $personnel */
                $personnel = $this->personnel;

                return $personnel === null ? null : ['id' => $personnel->ulid, 'display_name' => $personnel->display_name];
            }),
            'commission_preview' => $this->commissionPreview(),
            'can' => $this->capabilities($request),
        ];
    }

    /**
     * Typed NON-PAYABLE preview for a completed session — never earned or payable,
     * never a ledger row. The frontend renders it under "Preview — not earned or
     * payable". Null for non-completed sessions.
     *
     * @return array<string, mixed>|null
     */
    private function commissionPreview(): ?array
    {
        if ($this->status !== ServiceSessionStatus::Completed) {
            return null;
        }

        /** @var ServiceSession $session */
        $session = $this->resource;

        return app(CommissionPreviewService::class)->previewForCompletion($session)->toArray();
    }

    /**
     * State-aware capability map (policy permission AND current legal transition).
     *
     * @return array<string, bool>
     */
    private function capabilities(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;

        $can = fn (string $ability): bool => $user !== null && $user->can($ability, $this->resource);

        return [
            'view' => $can('view'),
            // Completion is driven by the queue orchestration route; surfaced here for parity.
            'complete' => $can('complete') && $status === ServiceSessionStatus::InProgress,
            // Cancellation is exposed only where it does not strand a queue entry (Gate C).
            'cancel' => $can('cancel') && $status === ServiceSessionStatus::Pending,
            'update_notes' => $can('update') && ! $status->isTerminal(),
        ];
    }
}
