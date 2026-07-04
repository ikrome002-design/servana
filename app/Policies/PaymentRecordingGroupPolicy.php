<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Payments\Models\PaymentRecord;
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

    /**
     * Finance checker validation of a whole pending group (Plan §42; Phase 18B). The
     * per-row maker != checker separation is enforced in the action
     * (PaymentMakerCheckerGuard); here we enforce the permission + branch access.
     */
    public function validate(User $user, PaymentRecordingGroup $group): bool
    {
        return $this->context->can('customer_payment.validate') && $this->ownsGroupBranch($group);
    }

    /** Finance checker rejects a whole pending group (Plan §42; Phase 18B). */
    public function reject(User $user, PaymentRecordingGroup $group): bool
    {
        return $this->context->can('customer_payment.reject') && $this->ownsGroupBranch($group);
    }

    /** Finance checker returns a whole pending group for correction (Plan §42; Phase 18B). */
    public function requestCorrection(User $user, PaymentRecordingGroup $group): bool
    {
        return $this->context->can('customer_payment.reject') && $this->ownsGroupBranch($group);
    }

    /** Resubmit a corrected group to pending_validation (Plan §42; Phase 18B). */
    public function resubmit(User $user, PaymentRecordingGroup $group): bool
    {
        return $this->context->can('customer_payment.reference_correct') && $this->ownsGroupBranch($group);
    }

    /** Correct a component's reference on a correctable group (Plan §42; Phase 18B). */
    public function correctReference(User $user, PaymentRecord $record): bool
    {
        return $this->context->can('customer_payment.reference_correct')
            && $record->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($record->branch_id);
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
