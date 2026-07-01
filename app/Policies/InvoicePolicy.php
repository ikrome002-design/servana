<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Invoice authority (Plan §10.2/§19.3, §40; Phase 17). Front Office owns
 * `invoice.view` + `invoice.create` (create/draft/finalize) within its resolved
 * merchant + assigned branch. Finance owns `invoice.view` plus the
 * `invoice.void.request_or_execute_as_policy` and `invoice.adjustment.manage`
 * workflows. Branch Manager, Merchant Admin, HR, Personnel, Audit, and Super Admin
 * hold NO invoice key and are denied here. Every per-invoice check additionally
 * enforces same-merchant + branch access (foreign-tenant ULIDs already 404 by scoped
 * binding; same-tenant out-of-branch is 403).
 */
final class InvoicePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('invoice.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->context->can('invoice.view') && $this->ownsBranch($invoice);
    }

    /** Class-level create (branch verified against the resolved client in the controller). */
    public function create(User $user): bool
    {
        return $this->context->can('invoice.create');
    }

    /** Draft edit + finalization (Front Office). */
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->context->can('invoice.create') && $this->ownsBranch($invoice);
    }

    public function finalize(User $user, Invoice $invoice): bool
    {
        return $this->context->can('invoice.create') && $this->ownsBranch($invoice);
    }

    /** Finance void workflow (request / execute / reject). */
    public function void(User $user, Invoice $invoice): bool
    {
        return $this->context->can('invoice.void.request_or_execute_as_policy') && $this->ownsBranch($invoice);
    }

    /** Finance additive adjustment. */
    public function adjust(User $user, Invoice $invoice): bool
    {
        return $this->context->can('invoice.adjustment.manage') && $this->ownsBranch($invoice);
    }

    public function ownsBranch(Invoice $invoice): bool
    {
        return $invoice->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($invoice->branch_id);
    }
}
