<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Merchant subscription-invoice authority (Plan §49; §19.3; Phase 20B). Merchant Administrator,
 * merchant scope. `merchant.subscription.invoice.view` reads the invoice list/detail;
 * `merchant.subscription.invoice.download` generates (new PDF; the route additionally enforces the
 * billing-mutable gate) and downloads an existing PDF (allowed even in billing read-only states).
 * Tenant isolation is enforced by the BelongsToMerchant query scope + tenant-safe route binding.
 * Defence-in-depth alongside the route `EnsurePermission`.
 */
final class SubscriptionInvoicePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->context->can('merchant.subscription.invoice.view');
    }

    public function download(User $user): bool
    {
        return $this->context->can('merchant.subscription.invoice.download');
    }
}
