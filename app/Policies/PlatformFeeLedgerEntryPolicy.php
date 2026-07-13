<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Percentage platform-fee ledger read authority (Plan §51, §19.3; Phase 20E). Merchant scope, masked,
 * under `platform_fee.view`. Server-side SCOPE (merchant-wide for Merchant Admin/Finance vs
 * branch-attributable for Branch Manager/Audit) is applied by the controller query, not the frontend.
 * Tenant isolation is enforced by the BelongsToMerchant query scope + tenant-safe route binding (a
 * foreign-merchant ULID resolves to 404). Read-only — there is NO ledger mutation policy method (the
 * original earned fact is immutable; corrections are additive and owned by void/refund/dispute flows).
 * Defence-in-depth alongside the route `EnsurePermission`.
 */
final class PlatformFeeLedgerEntryPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('platform_fee.view');
    }

    public function view(User $user, PlatformFeeLedgerEntry $entry): bool
    {
        return $this->context->can('platform_fee.view');
    }
}
