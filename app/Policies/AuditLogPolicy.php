<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Audit-log read authorization (Scope §4.8, Plan §70, ADR-008).
 *
 * Audit logs are READ-ONLY for everyone — create/update/delete are always denied
 * (the rows are immutable at the database too). Read scope:
 *   - Merchant rows: visible to a user with `audit.view_full` in the SAME merchant.
 *     A branch-scoped viewer (e.g. the Audit role) sees ONLY rows for a branch it
 *     is assigned to; merchant-level rows (branch_id null) are visible only to a
 *     non-branch-scoped viewer (Merchant Admin).
 *   - Platform rows (merchant_id null): visible only to platform staff with
 *     `platform.audit.view`. Platform staff never gain merchant operational reads.
 */
final class AuditLogPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('audit.view_full') || $this->context->can('platform.audit.view');
    }

    public function view(User $user, AuditLog $log): bool
    {
        // Platform / governance chain.
        if ($log->merchant_id === null) {
            return $this->context->isPlatformStaff() && $this->context->can('platform.audit.view');
        }

        // Merchant chain — same merchant + the audit-read capability.
        if ($log->merchant_id !== $this->context->merchantId() || ! $this->context->can('audit.view_full')) {
            return false;
        }

        // A branch-scoped viewer is confined to its assigned branch(es); it never
        // sees merchant-level (branch_id null) or other-branch rows (Scope §4.8).
        if ($this->context->isBranchScoped()) {
            return $log->branch_id !== null && $this->context->canAccessBranch($log->branch_id);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return false; // audit rows are append-only, written only by AuditRecorder
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false; // immutable (DB trigger blocks UPDATE)
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false; // immutable (DB trigger blocks DELETE)
    }
}
