<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Merchant-client payment recording authority (Plan §10.2/§19.3, §41; Phase 18A).
 * Front Office is the default MAKER (`customer_payment.record`). Finance holds
 * `customer_payment.view` (read the pending groups), `customer_payment.duplicate_override`
 * (release a suspected duplicate; MFA + step-up enforced on the route), and
 * `customer_payment.record_exception` (maker-exception recording). Finance NEVER
 * holds `customer_payment.record`. No other role holds any payment key. Every
 * per-row check additionally enforces same-merchant + branch access (foreign-tenant
 * ULIDs already 404 by scoped binding; same-tenant out-of-branch is 403).
 */
final class PaymentRecordingGroupPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    /** Front Office class-level record (invoice branch verified in the controller). */
    public function record(User $user): bool
    {
        return $this->context->can('customer_payment.record');
    }

    /** Finance maker-exception class-level record (invoice branch verified in the controller). */
    public function recordException(User $user): bool
    {
        return $this->context->can('customer_payment.record_exception');
    }

    public function viewAny(User $user): bool
    {
        return $this->context->can('customer_payment.view');
    }

    public function view(User $user, PaymentRecordingGroup $group): bool
    {
        return $this->context->can('customer_payment.view') && $this->ownsGroupBranch($group);
    }

    /** Finance duplicate override (route also enforces MFA + fresh step-up). */
    public function override(User $user, PaymentReferenceCheck $check): bool
    {
        return $this->context->can('customer_payment.duplicate_override')
            && $check->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($check->branch_id);
    }

    private function ownsGroupBranch(PaymentRecordingGroup $group): bool
    {
        return $group->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($group->branch_id);
    }
}
