<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a service category in a branch (Plan §39; Phase 15A). Branch Manager
 * authority is enforced at the route/controller; the create + audit row are one
 * transaction (Plan §70). Branch-scoped active-name uniqueness is a DB partial
 * unique index.
 */
final class CreateServiceCategory
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function handle(MerchantBranch $branch, User $actor, array $data): ServiceCategory
    {
        return DB::transaction(function () use ($branch, $actor, $data): ServiceCategory {
            $category = ServiceCategory::query()->create([
                'merchant_id' => $branch->merchant_id,
                'branch_id' => $branch->id,
                'name' => (string) $data['name'],
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                AuditEvent::ServiceCategoryCreated,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $category,
                ['name' => $category->name],
            );

            return $category;
        });
    }
}
