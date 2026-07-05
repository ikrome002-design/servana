<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Audit-log read authorization (Scope §4.8, Plan §19.2/§19.3, §70, ADR-008).
 *
 * Audit logs are READ-ONLY for everyone — create/update/delete are always denied
 * (the rows are immutable at the database too). Read scope (Phase 19 canonical
 * closure — the legacy catch-all `audit.view_full` is RETIRED):
 *   - Merchant rows with a branch: visible to a caller holding ANY canonical
 *     merchant audit-read key (`audit.branch_events.view` / `audit.finance.view`
 *     / `audit.compensation.view` / `finance.audit.view`) in the SAME merchant,
 *     confined to its actively-assigned branch(es). The domain SEGMENT (branch /
 *     finance / compensation) is enforced by the route + controller query; the
 *     policy enforces tenant + branch scope.
 *   - Merchant-level rows (branch_id null): NOT exposed to any merchant-tier
 *     audit reader (Phase 19 decision Q2). They are governance-scoped only.
 *   - Platform rows (merchant_id null): visible only to platform staff with
 *     `platform.audit.view`. Platform staff never gain merchant operational reads.
 */
final class AuditLogPolicy
{
    /** Canonical merchant-tier audit read keys (Plan §19.2 Audit + Finance audit). */
    private const MERCHANT_READ_KEYS = [
        'audit.branch_events.view',
        'audit.finance.view',
        'audit.compensation.view',
        'finance.audit.view',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        if ($this->context->can('platform.audit.view')) {
            return true;
        }

        foreach (self::MERCHANT_READ_KEYS as $key) {
            if ($this->context->can($key)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, AuditLog $log): bool
    {
        // Platform / governance chain.
        if ($log->merchant_id === null) {
            return $this->context->isPlatformStaff() && $this->context->can('platform.audit.view');
        }

        // Merchant chain — same merchant + a canonical merchant audit-read key.
        if ($log->merchant_id !== $this->context->merchantId() || ! $this->hasMerchantReadKey()) {
            return false;
        }

        // Merchant-level rows (branch_id null) are never exposed to a merchant-tier
        // reader (Phase 19 Q2); branch rows are confined to assigned branch(es).
        return $log->branch_id !== null && $this->context->canAccessBranch($log->branch_id);
    }

    private function hasMerchantReadKey(): bool
    {
        foreach (self::MERCHANT_READ_KEYS as $key) {
            if ($this->context->can($key)) {
                return true;
            }
        }

        return false;
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
