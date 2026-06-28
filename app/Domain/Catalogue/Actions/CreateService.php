<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a service in a branch (Plan §39; Phase 15A). Branch Manager authority is
 * enforced at the route/controller; the category MUST belong to the same branch
 * (validated upstream). Money is integer minor units; currency uppercase ISO. The
 * legacy preferred-personnel fee is never set here (non-editable seam). Create +
 * audit in one transaction.
 */
final class CreateService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function handle(MerchantBranch $branch, ServiceCategory $category, User $actor, array $data): Service
    {
        return DB::transaction(function () use ($branch, $category, $actor, $data): Service {
            $service = Service::query()->create([
                'merchant_id' => $branch->merchant_id,
                'branch_id' => $branch->id,
                'category_id' => $category->id,
                'name' => (string) $data['name'],
                'description' => isset($data['description']) ? (string) $data['description'] : null,
                'price_minor' => (int) $data['price_minor'],
                'currency' => strtoupper((string) ($data['currency'] ?? 'KES')),
                'duration_minutes' => (int) $data['duration_minutes'],
                'status' => ServiceStatus::Active,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                AuditEvent::ServiceCreated,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $service,
                ['name' => $service->name, 'price_minor' => $service->price_minor, 'currency' => $service->currency],
            );

            return $service;
        });
    }
}
