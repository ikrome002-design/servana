<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a service (Plan §39; Phase 15A). Editable: name/description/price/
 * currency/duration/category (same-branch category enforced upstream). The legacy
 * preferred-personnel fee is never editable here. Update + audit in one
 * transaction.
 */
final class UpdateService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function handle(Service $service, User $actor, array $data, ?ServiceCategory $category = null): Service
    {
        return DB::transaction(function () use ($service, $actor, $data, $category): Service {
            if ($category !== null) {
                $service->category_id = $category->id;
            }

            if (array_key_exists('name', $data)) {
                $service->name = (string) $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $service->description = $data['description'] === null ? null : (string) $data['description'];
            }
            if (array_key_exists('price_minor', $data)) {
                $service->price_minor = (int) $data['price_minor'];
            }
            if (array_key_exists('duration_minutes', $data)) {
                $service->duration_minutes = (int) $data['duration_minutes'];
            }
            if (array_key_exists('currency', $data)) {
                $service->currency = strtoupper((string) $data['currency']);
            }

            $service->updated_by = $actor->id;
            $service->save();

            $this->audit->record(
                AuditEvent::ServiceUpdated,
                $actor,
                $service->merchant_id,
                $service->branch_id,
                $service,
                ['name' => $service->name, 'price_minor' => $service->price_minor, 'currency' => $service->currency],
            );

            return $service;
        });
    }
}
