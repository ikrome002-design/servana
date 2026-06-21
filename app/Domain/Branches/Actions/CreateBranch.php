<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a branch under a merchant (Scope §3.3). Admin-only authority is enforced
 * at the route/controller; this action assumes an authorized actor and merchant.
 * The create + audit row are written in one transaction (Plan §70).
 *
 * @phpstan-type BranchInput array{name: string, code: string, address?: ?string, town?: ?string, phone?: ?string, email?: ?string, business_category?: ?string}
 */
final class CreateBranch
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Merchant $merchant, User $actor, array $data): MerchantBranch
    {
        return DB::transaction(function () use ($merchant, $actor, $data): MerchantBranch {
            $branch = MerchantBranch::query()->create([
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

            $this->audit->record(
                AuditEvent::BranchCreated,
                $actor,
                $merchant->id,
                $branch->id,
                $branch,
                ['name' => $branch->name, 'code' => $branch->code],
            );

            return $branch;
        });
    }
}
