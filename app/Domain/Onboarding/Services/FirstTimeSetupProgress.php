<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;

/**
 * Computes first-time setup progress (Scope §3.2 steps 1–7).
 *
 * Used by GET first-time-setup and by /me so the SPA can resume the wizard at
 * the right step. The server-side enforcement of step completion lives in
 * CompleteFirstTimeSetup (one transactional submit); this service is read-only
 * and reports which step still needs attention.
 */
final class FirstTimeSetupProgress
{
    public const STEP_TIER = 'service_fee_tier';

    public const STEP_PROFILE = 'merchant_profile';

    public const STEP_BRANCH = 'branch';

    public const STEP_STAFF = 'staff';

    public const STEP_REVIEW = 'review';

    public const STEP_DONE = 'done';

    public function required(Merchant $merchant): bool
    {
        return $merchant->status->isPendingSetup();
    }

    public function currentStep(Merchant $merchant): string
    {
        if (! $merchant->status->isPendingSetup()) {
            return self::STEP_DONE;
        }

        if ($merchant->service_fee_tier === null) {
            return self::STEP_TIER;
        }

        if (! $this->profileComplete($merchant)) {
            return self::STEP_PROFILE;
        }

        if ($merchant->branches()->count() === 0) {
            return self::STEP_BRANCH;
        }

        if (! $this->hasInitialStaff($merchant)) {
            return self::STEP_STAFF;
        }

        return self::STEP_REVIEW;
    }

    private function profileComplete(Merchant $merchant): bool
    {
        $profile = $merchant->profile;

        return $profile !== null
            && $profile->business_category !== null
            && $profile->contact_phone !== null;
    }

    private function hasInitialStaff(Merchant $merchant): bool
    {
        return $merchant->merchantUsers()
            ->whereIn('role', [MerchantUserRole::BranchManager->value, MerchantUserRole::Hr->value])
            ->whereIn('status', [MerchantUserStatus::Invited->value, MerchantUserStatus::Active->value])
            ->exists();
    }
}
