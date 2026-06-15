<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Branches\Models\MerchantBranch;
use App\Models\User;

/**
 * Update a branch profile (Scope §3.3). Status transitions go through dedicated
 * actions (ArchiveBranch) — this updates profile fields only.
 */
final class UpdateBranch
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(MerchantBranch $branch, User $actor, array $data): MerchantBranch
    {
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

        return $branch->refresh();
    }
}
