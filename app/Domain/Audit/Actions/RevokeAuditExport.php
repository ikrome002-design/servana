<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Services\AuditExportStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke an Audit export (Plan §13.5, §80; Phase 19; ADR-010). `ready → revoked`
 * (terminal); the file is no longer downloadable. Emits `audit_export.revoked`.
 * Invalid source state → `422 invalid_state_transition`.
 */
final class RevokeAuditExport
{
    public function __construct(
        private readonly AuditExportStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(AuditExport $export, User $actor): AuditExport
    {
        return DB::transaction(function () use ($export, $actor): AuditExport {
            /** @var AuditExport $locked */
            $locked = AuditExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, AuditExportStatus::Revoked);

            $locked->forceFill([
                'status' => AuditExportStatus::Revoked->value,
                'revoked_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::AuditExportRevoked, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'export_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
