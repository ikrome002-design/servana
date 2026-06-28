<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Personnel-service eligibility authority (Plan §19.3, §39). HR owns it within its
 * own branch scope (`personnel.eligibility.manage`); Branch Manager never mutates
 * eligibility. Reads use the same key (HR + the catalogue's read-only summary).
 * Cross-merchant/branch rows are 404'd upstream; same-merchant + branch-scope is
 * re-checked here.
 */
final class ServicePersonnelEligibilityPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        // The catalogue's read-only eligibility summary uses `service.view`; HR
        // management uses `personnel.eligibility.manage`. Either may list.
        return $this->context->can('personnel.eligibility.manage')
            || $this->context->can('service.view');
    }

    public function manage(User $user): bool
    {
        return $this->context->can('personnel.eligibility.manage');
    }

    public function mutate(User $user, ServicePersonnelEligibility $eligibility): bool
    {
        return $this->context->can('personnel.eligibility.manage')
            && $eligibility->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($eligibility->branch_id);
    }
}
