<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Phase 20G compensation-adjustment authority (Plan §60/§61, §19.3). Merchant scope, masked. Reading
 * adjustments is part of the liability view (`compensation.liability.view`); creating a manual additive
 * adjustment requires `compensation.adjustment.create` (Finance; MFA + fresh step-up enforced at the
 * route boundary; high-severity audit). There is NO update or delete — the table is append-only.
 * Defence in depth alongside the route `EnsurePermission`.
 */
final class CompensationAdjustmentPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('compensation.liability.view');
    }

    public function view(User $user, CompensationAdjustment $adjustment): bool
    {
        return $this->context->can('compensation.liability.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('compensation.adjustment.create');
    }
}
