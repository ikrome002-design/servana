<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Personnel SMS campaign authority (Plan §10.2, §19.3/§19.4, §64; ADR-010; Phase 21S).
 *
 * STRICTLY OWN SCOPE. A campaign is visible and operable ONLY to the staff profile that authored
 * it, inside the acting merchant and the acting branch scope. There is no branch-wide, HR, Finance,
 * Merchant-Admin or Audit view of another personnel member's campaigns and no key that could grant
 * one — `personnel.my_sms.send` is `non_overridable` in the matrix, and Plan §19.4 makes "Personnel
 * can never gain contact export" a hard rule.
 *
 * The own-scope subject is DERIVED from the authenticated membership every time
 * ({@see ownStaffProfile()}); it is never read from the request, so no client can act as another
 * staff profile.
 *
 * `create` deliberately has no model argument: composing a campaign is authorised by the permission
 * plus the entitlement/billing middleware, and the recipients themselves are authorised
 * individually by the served-client selector.
 */
final class PersonnelSmsCampaignPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('personnel.my_sms.send');
    }

    public function view(User $user, PersonnelSmsCampaign $campaign): bool
    {
        return $this->context->can('personnel.my_sms.send') && $this->ownsCampaign($campaign);
    }

    public function create(User $user): bool
    {
        return $this->context->can('personnel.my_sms.send');
    }

    public function confirm(User $user, PersonnelSmsCampaign $campaign): bool
    {
        return $this->context->can('personnel.my_sms.send') && $this->ownsCampaign($campaign);
    }

    public function cancel(User $user, PersonnelSmsCampaign $campaign): bool
    {
        return $this->context->can('personnel.my_sms.send') && $this->ownsCampaign($campaign);
    }

    /**
     * Own scope: same merchant, an accessible branch, and — the decisive check — the campaign's
     * staff profile IS the acting user's staff profile.
     */
    private function ownsCampaign(PersonnelSmsCampaign $campaign): bool
    {
        $profile = $this->ownStaffProfile();

        if ($profile === null) {
            return false;
        }

        return $campaign->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($campaign->branch_id)
            && $campaign->staff_profile_id === $profile->id;
    }

    /** The acting user's own staff profile, derived from the membership — never from the request. */
    private function ownStaffProfile(): ?StaffProfile
    {
        $merchantUser = $this->context->merchantUser();

        if ($merchantUser === null) {
            return null;
        }

        return StaffProfile::query()->where('merchant_user_id', $merchantUser->id)->first();
    }
}
