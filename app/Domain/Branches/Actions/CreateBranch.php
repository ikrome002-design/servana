<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;

/**
 * Create a branch under a merchant (Scope §3.3). Admin-only authority is enforced
 * at the route/controller; this action assumes an authorized actor and merchant.
 *
 * @phpstan-type BranchInput array{name: string, code: string, address?: ?string, town?: ?string, phone?: ?string, email?: ?string, business_category?: ?string}
 */
final class CreateBranch
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Merchant $merchant, User $actor, array $data): MerchantBranch
    {
        return MerchantBranch::query()->create([
            'merchant_id' => $merchant->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'address' => $data['address'] ?? null,
            'town' => $data['town'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'business_category' => $data['business_category'] ?? null,
            'created_by' => $actor->id,
        ]);
    }
}
