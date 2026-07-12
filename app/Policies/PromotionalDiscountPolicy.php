<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Promotional-discount authority (Plan §53, §19.3; Phase 20C). Management is Super-Admin ONLY, platform
 * scope, under `platform.promotion.manage` (MFA + fresh step-up on mutating routes; enforced by route
 * middleware). No merchant role receives this authority. There is no merchant-facing promotion policy.
 */
final class PromotionalDiscountPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('platform.promotion.manage');
    }

    public function view(User $user, PromotionalDiscount $discount): bool
    {
        return $this->context->can('platform.promotion.manage');
    }

    public function manage(User $user): bool
    {
        return $this->context->can('platform.promotion.manage');
    }
}
