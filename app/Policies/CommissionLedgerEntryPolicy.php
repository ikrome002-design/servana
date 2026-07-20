<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Phase 20G commission-liability READ authority (Plan §61, §19.3). Merchant scope, masked, under
 * `compensation.liability.view` (Finance). Read-only — the commission ledger is append-only and its
 * monetary facts are immutable; corrections are additive and owned by the refund/adjustment flows, not
 * a ledger-edit policy. Tenant isolation is enforced by the BelongsToMerchant query scope. Defence in
 * depth alongside the route `EnsurePermission`.
 */
final class CommissionLedgerEntryPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('compensation.liability.view');
    }

    public function view(User $user, CommissionLedgerEntry $entry): bool
    {
        return $this->context->can('compensation.liability.view');
    }
}
