<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Actions;

use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use App\Domain\Merchants\Models\MerchantStatusHistory;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Merchant Administrator self-registration (Scope §3.1/§3.2, Plan §27 Phase 6).
 *
 * Creates, in a single transaction:
 *   - the registering user (active, no password — Magic Link only),
 *   - the merchant tenant (pending_setup),
 *   - a shell merchant profile (1:1 always exists),
 *   - the owner membership (merchant_admin / active),
 *   - a status-history row (→ pending_setup).
 *
 * The registering user IS the Merchant Owner / Merchant Administrator (they are
 * the same account — never split, Scope §3.2). There is no Super Admin approval,
 * no KYC, and no platform creation path.
 *
 * Returns null when the email already belongs to a user: the caller responds
 * uniformly either way so registration cannot be used to enumerate accounts
 * (Plan §9.1 enumeration rule applied to onboarding).
 */
final class RegisterMerchant
{
    public function handle(string $ownerName, string $email, string $businessName): ?Merchant
    {
        $email = Str::lower(trim($email));

        // One identity per email. An existing email never creates a second
        // merchant or a duplicate owner — and the response does not reveal it.
        if (User::query()->where('email', $email)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($ownerName, $email, $businessName): Merchant {
            $user = new User;
            $user->name = $ownerName;
            $user->email = $email;
            $user->status = User::STATUS_ACTIVE;
            $user->save();

            $merchant = new Merchant;
            $merchant->name = $businessName;
            $merchant->slug = $this->uniqueSlug($businessName);
            $merchant->status = MerchantStatus::PendingSetup;
            $merchant->created_by = $user->id;
            $merchant->save();

            MerchantProfile::query()->create([
                'merchant_id' => $merchant->id,
                'contact_email' => $email,
            ]);

            MerchantUser::query()->create([
                'merchant_id' => $merchant->id,
                'user_id' => $user->id,
                'role' => MerchantUserRole::MerchantAdmin,
                'status' => MerchantUserStatus::Active,
                'activated_at' => now(),
            ]);

            MerchantStatusHistory::query()->create([
                'merchant_id' => $merchant->id,
                'from_status' => null,
                'to_status' => MerchantStatus::PendingSetup->value,
                'reason' => 'merchant_self_registration',
                'changed_by' => $user->id,
            ]);

            return $merchant;
        });
    }

    /** Lowercased, unique slug derived from the business name. */
    private function uniqueSlug(string $businessName): string
    {
        $base = Str::slug($businessName);

        if ($base === '') {
            $base = 'merchant';
        }

        $slug = $base;

        // Append a short random suffix on collision (cheap, avoids a race-prone
        // counter). The unique index is the real backstop.
        while (Merchant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
