<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Audit\Models\AuditExport;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Audit export authority (Plan §13.5, §19.2/§19.3, §80; Phase 19; ADR-010).
 *
 * The Audit role owns the single `audit.export` capability (request + download + revoke;
 * fresh step-up on the mutating routes). Every per-row check enforces same-merchant
 * ownership (foreign-tenant ULIDs 404) AND assigned-branch scope — the export is
 * branch-owned, so a same-tenant wrong-branch row is denied. Audit never mutates a
 * source record; this only governs the export request lifecycle.
 */
final class AuditExportPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('audit.export');
    }

    public function view(User $user, AuditExport $export): bool
    {
        return $this->context->can('audit.export') && $this->scoped($export);
    }

    public function create(User $user): bool
    {
        return $this->context->can('audit.export');
    }

    public function download(User $user, AuditExport $export): bool
    {
        return $this->context->can('audit.export') && $this->scoped($export);
    }

    public function revoke(User $user, AuditExport $export): bool
    {
        return $this->context->can('audit.export') && $this->scoped($export);
    }

    /** Same merchant + the export's (non-null) branch is one the caller may access. */
    private function scoped(AuditExport $export): bool
    {
        return $export->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($export->branch_id);
    }
}
