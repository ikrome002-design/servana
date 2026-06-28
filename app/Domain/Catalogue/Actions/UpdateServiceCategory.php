<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a service category (Plan §39; Phase 15A). Only `name`/`sort_order` are
 * editable; tenancy columns are immutable. Update + audit in one transaction.
 */
final class UpdateServiceCategory
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function handle(ServiceCategory $category, User $actor, array $data): ServiceCategory
    {
        return DB::transaction(function () use ($category, $actor, $data): ServiceCategory {
            if (array_key_exists('name', $data)) {
                $category->name = (string) $data['name'];
            }
            if (array_key_exists('sort_order', $data)) {
                $category->sort_order = (int) $data['sort_order'];
            }
            $category->updated_by = $actor->id;
            $category->save();

            $this->audit->record(
                AuditEvent::ServiceCategoryUpdated,
                $actor,
                $category->merchant_id,
                $category->branch_id,
                $category,
                ['name' => $category->name],
            );

            return $category;
        });
    }
}
