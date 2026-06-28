<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Branches\Models\BranchCalendarException;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientConsent;
use App\Domain\Hr\Models\StaffHistory;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\MerchantProfile;
use App\Domain\Merchants\Models\MerchantStatusHistory;
use App\Domain\Merchants\Models\MerchantUser;

/**
 * Central tenant/branch ownership registry (Plan §2.1, §8.2, §13.1; ADR-002; R5).
 *
 * Single source of truth for what each EXISTING table is and what structural
 * tenant protection it must carry. `TenantColumnCoverageTest` and the model-trait
 * / route-binding coverage tests read this map; nothing may be silently ignored.
 *
 *   - BRANCH_OWNED: must have non-null merchant_id + branch_id, FKs + indexes, and
 *     a DB consistency constraint that merchant_id matches the parent branch
 *     (composite FK → merchant_branches(id, merchant_id)). Model uses
 *     BelongsToMerchant + BelongsToBranch.
 *   - TENANT_OWNED: must have non-null merchant_id, FK + index. Model uses
 *     BelongsToMerchant. (staff_profiles also carries primary_branch_id and uses
 *     BelongsToBranch for branch-scoped visibility — allowed, not mandated.)
 *   - EXEMPT: platform/global, user-owned, the tenant root (merchants), framework,
 *     and cross-cutting tables (audit_logs platform chain, idempotency_keys
 *     platform/webhook scopes) that legitimately have no/ nullable merchant scope.
 *     Each carries a written reason here; an undocumented table fails coverage.
 */
final class TenantOwnership
{
    /** @var list<string> branch-owned tables (merchant_id + branch_id required). */
    public const BRANCH_OWNED = [
        'branch_user_assignments',
        'branch_operating_hours',
        'branch_calendar_exceptions',
        'branch_day_records',
        'branch_cash_ups',
        'staff_invitations',
        // Phase 15A — Catalogue & Clients (Plan §13.7).
        'service_categories',
        'services',
        'service_personnel_eligibility',
        'clients',
        'client_consents',
    ];

    /** @var list<string> tenant-owned tables (merchant_id required, no branch_id). */
    public const TENANT_OWNED = [
        'merchant_profiles',
        'merchant_status_histories',
        'merchant_users',
        'merchant_branches',
        'staff_profiles',
        'staff_history',
        'merchant_user_permission_overrides',
    ];

    /**
     * Tables deliberately NOT merchant-scoped, each with a rationale.
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        // Tenant root — IS the merchant; cannot carry its own merchant_id.
        'merchants' => 'tenant root (the merchant itself)',
        // User-owned identity (Plan §13.5) — not tenant-scoped; membership lives in merchant_users.
        'users' => 'user-owned identity (not tenant-scoped)',
        'magic_login_tokens' => 'user-owned auth token (bound to email, not a merchant)',
        'mfa_credentials' => 'identity-owned MFA credential (R3; user_id, no merchant)',
        'mfa_recovery_codes' => 'identity-owned MFA recovery code (R3; user_id, no merchant)',
        // Platform-global catalogue/governance.
        'permissions' => 'platform-global permission catalogue',
        'roles' => 'platform-global role catalogue',
        'role_permission_assignments' => 'platform-global role→permission map',
        // Cross-cutting infrastructure with nullable/forensic merchant scope.
        'audit_logs' => 'cross-cutting: per-merchant AND platform chain (merchant_id nullable by design, R2)',
        'idempotency_keys' => 'cross-cutting: platform/webhook scopes have null merchant/branch forensic columns (R4)',
        'uploaded_files' => 'cross-cutting: nullable merchant/branch/owner scope (platform-generated files may have no merchant); isolation enforced by FileAccessService + scoped route binding (10F)',
        'file_scan_events' => 'inherits scope via uploaded_file_id; never directly route-bound (10F)',
        // Framework / Laravel infrastructure tables.
        'migrations' => 'framework: migration ledger',
        'password_reset_tokens' => 'framework: unused (passwordless), Laravel default',
        'personal_access_tokens' => 'framework: Sanctum default (no API tokens issued — session only)',
        'sessions' => 'framework: session store (keyed by user_id)',
        'cache' => 'framework: cache store',
        'cache_locks' => 'framework: cache lock store',
        'jobs' => 'framework: queue jobs',
        'job_batches' => 'framework: queue batches',
        'failed_jobs' => 'framework: failed queue jobs',
    ];

    /**
     * Domain model classes scanned by ModelTenancyTraitCoverageTest, mapped to the
     * trait set their table classification requires.
     *
     * @var array<class-string, 'branch'|'tenant'>
     */
    public const MODELS = [
        MerchantBranch::class => 'tenant',
        // BranchScope-exempt: the branch-assignment authority that resolves
        // TenantContext::branchIds; BranchScope here would be circular. Requires
        // BelongsToMerchant (merchant isolation) only.
        BranchUserAssignment::class => 'tenant',
        BranchOperatingHour::class => 'branch',
        BranchCalendarException::class => 'branch',
        BranchDayRecord::class => 'branch',
        BranchCashUp::class => 'branch',
        StaffInvitation::class => 'branch',
        StaffProfile::class => 'tenant',
        StaffHistory::class => 'tenant',
        MerchantProfile::class => 'tenant',
        MerchantStatusHistory::class => 'tenant',
        MerchantUser::class => 'tenant',
        MerchantUserPermissionOverride::class => 'tenant',
        // Phase 15A — branch-owned catalogue & clients (BelongsToMerchant + BelongsToBranch).
        ServiceCategory::class => 'branch',
        Service::class => 'branch',
        ServicePersonnelEligibility::class => 'branch',
        Client::class => 'branch',
        ClientConsent::class => 'branch',
    ];

    /** Tables whose merchant_id consistency is enforced by a composite FK to a parent. */
    public const COMPOSITE_CONSISTENCY = [
        'branch_user_assignments' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'branch_operating_hours' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'branch_calendar_exceptions' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'branch_day_records' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'branch_cash_ups' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'staff_history' => ['parent' => 'staff_profiles', 'fk' => 'staff_profile_id'],
        'merchant_user_permission_overrides' => ['parent' => 'merchant_users', 'fk' => 'merchant_user_id'],
        // Phase 15A — branch consistency via composite FK to merchant_branches.
        'service_categories' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'services' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'service_personnel_eligibility' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'clients' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'client_consents' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
    ];
}
