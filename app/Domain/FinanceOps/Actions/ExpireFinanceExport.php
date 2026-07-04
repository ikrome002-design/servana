<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\FinanceOps\Services\FinanceExportStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Expire a finance export past its retention window (Plan §65, §67; Phase 18B).
 * `ready → expired` (terminal); no longer downloadable. Emits `finance_export.expired`.
 * Typically driven by the file-domain expiry sweep; $actor is null for the system sweep.
 */
final class ExpireFinanceExport
{
    public function __construct(
        private readonly FinanceExportStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(FinanceExport $export, ?User $actor = null): FinanceExport
    {
        return DB::transaction(function () use ($export, $actor): FinanceExport {
            /** @var FinanceExport $locked */
            $locked = FinanceExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, FinanceExportStatus::Expired);

            $locked->forceFill(['status' => FinanceExportStatus::Expired->value])->save();

            $this->audit->record(AuditEvent::FinanceExportExpired, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'export_id' => $locked->ulid,
                'export_type' => $locked->export_type->value,
            ]);

            return $locked;
        });
    }
}
