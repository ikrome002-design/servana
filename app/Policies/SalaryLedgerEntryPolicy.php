<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Phase 20G salary-liability READ authority (Plan §60, §19.3). Merchant scope, masked, under
 * `compensation.liability.view` (Finance). Read-only — the salary ledger is append-only and its
 * accrued facts are immutable; corrections are additive. Tenant isolation is enforced by the
 * BelongsToMerchant query scope. Defence in depth alongside the route `EnsurePermission`.
 */
final class SalaryLedgerEntryPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('compensation.liability.view');
    }

    public function view(User $user, SalaryLedgerEntry $entry): bool
    {
        return $this->context->can('compensation.liability.view');
    }
}
