<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Phase 20H personnel-payout-run authority (Plan §62, §10.2, §19.3). Defence-in-depth alongside the
 * route `EnsurePermission` middleware. Authority is split by role and never overlaps: HR owns the
 * DRAFT workflow (create/update/submit/cancel — branch scope); Finance owns
 * verify/approve-standard/reject/mark-paid (merchant scope; MFA + fresh step-up on
 * verify/approve/mark-paid, enforced at the route); the Merchant Administrator owns ONLY high-value
 * approval + the compensation-summary read (never create/verify/standard-approve/mark-paid). Every
 * method checks the request-cached permission set; the controller enforces branch/tenant scope and the
 * domain action enforces the financial state machine. **Servana moves no money.**
 */
final class PersonnelPayoutRunPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    // --- HR (branch-scoped draft workflow) --------------------------------

    public function viewAsHr(User $user): bool
    {
        return $this->context->can('payout_run.create');
    }

    public function create(User $user): bool
    {
        return $this->context->can('payout_run.create');
    }

    public function update(User $user, PersonnelPayoutRun $run): bool
    {
        return $this->context->can('payout_run.update_draft');
    }

    public function submit(User $user, PersonnelPayoutRun $run): bool
    {
        return $this->context->can('payout_run.submit');
    }

    public function cancel(User $user, PersonnelPayoutRun $run): bool
    {
        return $this->context->can('payout_run.cancel_draft');
    }

    // --- Finance (merchant-scoped verify/approve/reject/mark-paid) ---------

    public function viewAsFinance(User $user): bool
    {
        return $this->context->can('payout_run.verify');
    }

    public function verify(User $user, PersonnelPayoutRun $run): bool
    {
        return $this->context->can('payout_run.verify');
    }

    public function approveStandard(User $user, PersonnelPayoutRun $run): bool
    {
        return $this->context->can('payout_run.approve_standard');
    }

    public function reject(User $user, PersonnelPayoutRun $run): bool
    {
        return $this->context->can('payout_run.reject');
    }

    public function markPaid(User $user, PersonnelPayoutRun $run): bool
    {
        return $this->context->can('payout_run.mark_paid');
    }

    // --- Merchant Administrator (high-value approval only) ----------------

    public function viewAsMerchantAdmin(User $user): bool
    {
        return $this->context->can('merchant.payout.approve_high_value');
    }

    public function approveHighValue(User $user, PersonnelPayoutRun $run): bool
    {
        return $this->context->can('merchant.payout.approve_high_value');
    }
}
