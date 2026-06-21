<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a branch profile (Scope §3.3). Status transitions go through dedicated
 * actions (ArchiveBranch) — this updates profile fields only. The change is
 * audited with old/new values for the fields that actually changed (Plan §70).
 */
final class UpdateBranch
{
    /** Profile fields tracked for the audit old/new diff. */
    private const TRACKED = ['name', 'code', 'address', 'town', 'phone', 'email', 'business_category'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(MerchantBranch $branch, User $actor, array $data): MerchantBranch
    {
        return DB::transaction(function () use ($branch, $actor, $data): MerchantBranch {
            $before = $branch->only(self::TRACKED);

            $branch->fill([
                'name' => $data['name'] ?? $branch->name,
                'code' => $data['code'] ?? $branch->code,
                'address' => $data['address'] ?? $branch->address,
                'town' => $data['town'] ?? $branch->town,
                'phone' => $data['phone'] ?? $branch->phone,
                'email' => $data['email'] ?? $branch->email,
                'business_category' => $data['business_category'] ?? $branch->business_category,
                'updated_by' => $actor->id,
            ]);
            $branch->save();

            $after = $branch->only(self::TRACKED);
            $oldValues = array_filter($before, fn ($v, $k): bool => $after[$k] !== $v, ARRAY_FILTER_USE_BOTH);
            $newValues = array_intersect_key($after, $oldValues);

            $this->audit->record(
                AuditEvent::BranchProfileUpdated,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $branch,
                ['old_values' => $oldValues, 'new_values' => $newValues],
            );

            return $branch->refresh();
        });
    }
}
