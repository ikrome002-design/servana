<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Record an authorized Audit export download (Plan §13.5, §80; Phase 19; ADR-010).
 *
 * Called from the authorized file STREAM (not link issuance): atomically increments
 * `download_count`, sets `first_downloaded_at` only once, updates `last_downloaded_at`,
 * and emits `audit_export.downloaded`. Row-locked so concurrent streams count exactly once each.
 */
final class RecordAuditExportDownload
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(AuditExport $export, User $actor): AuditExport
    {
        return DB::transaction(function () use ($export, $actor): AuditExport {
            /** @var AuditExport $locked */
            $locked = AuditExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();

            $now = now();
            $locked->download_count = $locked->download_count + 1;
            if ($locked->first_downloaded_at === null) {
                $locked->first_downloaded_at = $now;
            }
            $locked->last_downloaded_at = $now;
            $locked->save();

            $this->audit->record(AuditEvent::AuditExportDownloaded, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'export_id' => $locked->ulid,
                'download_count' => $locked->download_count,
            ]);

            return $locked;
        });
    }
}
