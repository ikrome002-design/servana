<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Services\AuditExportStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Expire a ready Audit export past its `expires_at` (Plan §13.5, §80; Phase 19;
 * ADR-010). `ready → expired` (terminal); the file is no longer downloadable. Emits
 * `audit_export.expired`. Invoked by the file-expiry sweep / scheduler, not a route.
 */
final class ExpireAuditExport
{
    public function __construct(
        private readonly AuditExportStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(AuditExport $export): AuditExport
    {
        return DB::transaction(function () use ($export): AuditExport {
            /** @var AuditExport $locked */
            $locked = AuditExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, AuditExportStatus::Expired);

            $locked->forceFill([
                'status' => AuditExportStatus::Expired->value,
                'expired_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::AuditExportExpired, null, $locked->merchant_id, $locked->branch_id, $locked, [
                'export_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
