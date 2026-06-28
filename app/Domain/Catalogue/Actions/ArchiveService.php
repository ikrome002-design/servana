<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Exceptions\CatalogueStateException;
use App\Domain\Catalogue\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Archive a service (Plan §39; Phase 15A). `active → archived` only; archiving an
 * already-archived service is an invalid transition (422), never a silent no-op.
 * Archived services are excluded from active-selection queries. Archive + audit in
 * one transaction.
 */
final class ArchiveService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Service $service, User $actor): Service
    {
        if ($service->status === ServiceStatus::Archived) {
            throw CatalogueStateException::alreadyArchived('service');
        }

        return DB::transaction(function () use ($service, $actor): Service {
            $service->status = ServiceStatus::Archived;
            $service->updated_by = $actor->id;
            $service->save();

            $this->audit->record(
                AuditEvent::ServiceArchived,
                $actor,
                $service->merchant_id,
                $service->branch_id,
                $service,
                ['name' => $service->name],
            );

            return $service;
        });
    }
}
