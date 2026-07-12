<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Free-period-offer authority (Plan §53, §19.3; Phase 20C). Management is Super-Admin ONLY, platform
 * scope, under `platform.free_period_offer.manage` (MFA + fresh step-up on mutating routes; enforced by
 * route middleware). No merchant role receives this authority.
 */
final class FreePeriodOfferPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('platform.free_period_offer.manage');
    }

    public function view(User $user, FreePeriodOffer $offer): bool
    {
        return $this->context->can('platform.free_period_offer.manage');
    }

    public function manage(User $user): bool
    {
        return $this->context->can('platform.free_period_offer.manage');
    }
}
