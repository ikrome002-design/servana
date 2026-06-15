<?php

declare(strict_types=1);

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Models\MerchantBranch;

/**
 * Branch platform-fee debt gate (Plan §10.2, §27 Phase 7 "branch-debt gate stub").
 *
 * Deleting/closing a branch user requires that branch's platform-fee debt = 0
 * (`BillingEngine::branchDebt()` in the Plan). The Citrus Billing Engine is
 * Phase 20 and the operational state that produces debt (invoices/sessions) is
 * Phase 16–18, so this returns 0 now. The INTERFACE is fixed here so closure and
 * staff-removal flows call a stable seam; later phases swap the implementation
 * without touching call sites. It is NOT a silent skip — it returns an explicit
 * zero and is exercised by tests.
 */
final class BranchDebtGate
{
    /** Outstanding platform-fee debt for a branch, in integer minor units (KES). */
    public function outstandingDebtMinor(MerchantBranch $branch): int
    {
        // Phase 20 (Citrus Billing Engine) implements the real lookup.
        return 0;
    }

    public function hasOutstandingDebt(MerchantBranch $branch): bool
    {
        return $this->outstandingDebtMinor($branch) > 0;
    }
}
