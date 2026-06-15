<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

/**
 * Canonical permission registry — the single source for the Plan §10.3 matrix.
 *
 * This class IS the spec the seeder writes to the database and the matrix test
 * verifies against. Interpretation of the §10.3 table (documented so reviewers
 * can audit every cell):
 *
 *   - Any non-empty, non-`◐` cell  → a DEFAULT GRANT for that role. Scope
 *     qualifiers in the table (`branch`, `own`, `staff`, `masked`, `day`,
 *     `view`) describe DATA scoping that branch scope + policies enforce — they
 *     do not change whether the role holds the key.
 *   - `◐` cell  → GRANTABLE via a per-membership override (not a default grant).
 *   - `**never**` (personnel exports) → the key is absent for that role; there
 *     is NO contact/client export key anywhere (guardrail §6.8).
 *
 * Two §10.3 cells are ambiguous in the source table; both are resolved by the
 * §10.2 hard rules (which outrank an ambiguous matrix glyph):
 *   - `platform_fees.* | … | ✓ (audit)` → audit is read-only everywhere, so the
 *     single ✓ is the READ key `platform_fees.view`, not `…dispute`.
 *   - `audit.flag` is audit's one in-domain write (explicitly granted to audit);
 *     it is NOT a merchant-resource write, so the read-only audit role keeps it.
 */
final class PermissionRegistry
{
    public const ROLE_MERCHANT_ADMIN = 'merchant_admin';

    public const ROLE_BRANCH_MANAGER = 'branch_manager';

    public const ROLE_HR = 'hr';

    public const ROLE_FINANCE = 'finance';

    public const ROLE_FRONT_OFFICE = 'front_office';

    public const ROLE_PERSONNEL = 'personnel';

    public const ROLE_AUDIT = 'audit';

    public const ROLE_SUPER_ADMIN = 'super_admin';

    /**
     * The role catalogue (Plan §10.1). `read_only` marks the audit role, whose
     * resolved set is filtered to never gain a mutating merchant capability via
     * override (its in-domain `audit.flag` default is preserved).
     *
     * @var array<string, array{name: string, scope: string, read_only: bool, description: string}>
     */
    private const ROLES = [
        self::ROLE_MERCHANT_ADMIN => ['name' => 'Merchant Administrator', 'scope' => 'merchant', 'read_only' => false, 'description' => 'Account owner; merchant profile, tier, branch + branch-user lifecycle.'],
        self::ROLE_BRANCH_MANAGER => ['name' => 'Branch Manager', 'scope' => 'merchant', 'read_only' => false, 'description' => 'Own-branch operations: profile, services, queue, appointments, day.'],
        self::ROLE_HR => ['name' => 'Human Resource', 'scope' => 'merchant', 'read_only' => false, 'description' => 'Same-branch staff lifecycle, eligibility, availability, commissions.'],
        self::ROLE_FINANCE => ['name' => 'Finance', 'scope' => 'merchant', 'read_only' => false, 'description' => 'Payment validation, receipts, refunds, disputes, cash-up review.'],
        self::ROLE_FRONT_OFFICE => ['name' => 'Front Office', 'scope' => 'merchant', 'read_only' => false, 'description' => 'Records payments and sessions; never validates payments or issues receipts.'],
        self::ROLE_PERSONNEL => ['name' => 'Personnel', 'scope' => 'merchant', 'read_only' => false, 'description' => 'Own-scope reads only; no export of any kind.'],
        self::ROLE_AUDIT => ['name' => 'Audit', 'scope' => 'merchant', 'read_only' => true, 'description' => 'Read-only everywhere; may flag audit entries.'],
        self::ROLE_SUPER_ADMIN => ['name' => 'Super Administrator', 'scope' => 'platform', 'read_only' => false, 'description' => 'Platform governance only; no merchant operational access.'],
    ];

    /**
     * Permission catalogue: key => [category, description, mutating] (positional).
     *
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    private const PERMISSIONS = [
        // Merchant.
        'merchant.profile.manage' => ['merchant', 'Edit merchant profile.', true],
        'merchant.tier.update' => ['merchant', 'Change the merchant service-fee tier.', true],
        'branches.create' => ['merchant', 'Create and archive branches (structural lifecycle).', true],
        'branches.manage_users_lifecycle' => ['merchant', 'Invite/suspend branch_manager + hr; branch-user lifecycle.', true],
        'periods.lock' => ['merchant', 'Lock a financial period.', true],
        // Branch operations.
        'branch.profile.manage' => ['branch', 'Edit own-branch profile and operating hours.', true],
        'branch.calendar.manage' => ['branch', 'Manage own-branch calendar exceptions.', true],
        'services.manage' => ['branch', 'Configure services and pricing.', true],
        'queue.configure' => ['branch', 'Configure the branch queue.', true],
        'queue.operate' => ['branch', 'Operate the queue (call/serve/skip).', true],
        'queue.transfer_entries' => ['branch', 'Transfer queue entries from unavailable personnel.', true],
        'appointments.manage' => ['branch', 'Create and manage appointments.', true],
        'day.open_close' => ['branch', 'Open and close the branch business day.', true],
        'cashup.submit' => ['branch', 'Submit a branch cash-up.', true],
        // Staff.
        'staff.invite' => ['staff', 'Invite operational staff (same branch).', true],
        'staff.edit' => ['staff', 'Edit staff profiles.', true],
        'staff.suspend' => ['staff', 'Suspend/deactivate operational staff.', true],
        'eligibility.manage' => ['staff', 'Manage service eligibility.', true],
        'availability.manage' => ['staff', 'Manage staff availability.', true],
        'commissions.manage' => ['staff', 'Set staff commissions.', true],
        // Clients.
        'clients.create' => ['clients', 'Create client records.', true],
        'clients.edit' => ['clients', 'Edit client records.', true],
        'clients.view' => ['clients', 'View client records (scoped).', false],
        // Sessions & invoices.
        'sessions.manage' => ['operations', 'Manage service sessions.', true],
        'invoices.create' => ['finance', 'Create invoices.', true],
        'invoices.view' => ['finance', 'View invoices (scoped).', false],
        'invoices.void_unpaid' => ['finance', 'Void an unpaid invoice.', true],
        'invoices.void_paid' => ['finance', 'Approve voiding a paid invoice.', true],
        'invoices.adjust_paid' => ['finance', 'Request adjustment of a paid invoice.', true],
        // Payments.
        'payments.record' => ['finance', 'Record an offline payment.', true],
        'payments.validate' => ['finance', 'Validate a recorded payment.', true],
        'payments.reject' => ['finance', 'Reject a recorded payment.', true],
        'payments.edit_reference' => ['finance', 'Edit a payment reference.', true],
        'payments.override_duplicate' => ['finance', 'Override a duplicate-reference block.', true],
        // Receipts.
        'receipts.view' => ['finance', 'View receipts (scoped).', false],
        'receipts.reissue' => ['finance', 'Reissue a receipt.', true],
        // Refunds / disputes / cash-up.
        'refunds.request' => ['finance', 'Request a refund.', true],
        'refunds.approve' => ['finance', 'Approve a refund.', true],
        'disputes.manage' => ['finance', 'Manage payment disputes.', true],
        'cashup.review_approve' => ['finance', 'Review and approve cash-ups.', true],
        'periods.reopen' => ['finance', 'Reopen a locked period (delegated).', true],
        // Commissions & platform fees.
        'commissions.view' => ['finance', 'View commissions (scoped).', false],
        'platform_fees.view' => ['finance', 'View Citrus platform fees (scoped).', false],
        'platform_fees.dispute' => ['finance', 'Dispute a Citrus platform fee.', true],
        // Reports & exports.
        'reports.view' => ['reports', 'View reports (scoped).', false],
        'exports.finance' => ['reports', 'Export finance data.', false],
        'exports.staff_roster' => ['reports', 'Export the staff roster only.', false],
        // Audit.
        'audit.view_full' => ['audit', 'View the audit trail (scoped/masked).', false],
        'audit.flag' => ['audit', 'Flag an audit entry for review.', true],
        // Platform (super_admin only).
        'platform.settings.manage' => ['platform', 'Manage platform settings.', true],
        'platform.merchants.govern' => ['platform', 'Govern merchant accounts.', true],
        'platform.billing.configure' => ['platform', 'Configure platform billing.', true],
        'platform.fee_rules.manage' => ['platform', 'Manage platform fee rules.', true],
        'platform.audit.view' => ['platform', 'View the platform audit trail.', false],
    ];

    /**
     * Default grants per role (the ✓ / scoped cells of §10.3).
     *
     * @var array<string, list<string>>
     */
    private const DEFAULT_GRANTS = [
        self::ROLE_MERCHANT_ADMIN => [
            'merchant.profile.manage', 'merchant.tier.update',
            'branches.create', 'branches.manage_users_lifecycle',
            'invoices.view', 'invoices.void_paid', 'receipts.view',
            'periods.lock', 'commissions.view', 'platform_fees.view',
            'reports.view', 'audit.view_full',
        ],
        self::ROLE_BRANCH_MANAGER => [
            'branch.profile.manage', 'branch.calendar.manage', 'services.manage',
            'queue.configure', 'queue.operate', 'queue.transfer_entries',
            'appointments.manage', 'day.open_close', 'cashup.submit',
            'clients.view', 'sessions.manage', 'invoices.create', 'invoices.view',
            'receipts.view', 'commissions.view', 'platform_fees.view',
            'reports.view', 'audit.view_full',
        ],
        self::ROLE_HR => [
            'staff.invite', 'staff.edit', 'staff.suspend',
            'eligibility.manage', 'availability.manage', 'commissions.manage',
            'commissions.view', 'reports.view', 'audit.view_full',
            'exports.staff_roster',
        ],
        self::ROLE_FINANCE => [
            'clients.view', 'invoices.view', 'invoices.void_unpaid',
            'payments.record', 'payments.validate', 'payments.reject',
            'receipts.view', 'refunds.request', 'disputes.manage',
            'cashup.review_approve', 'platform_fees.dispute',
            'reports.view', 'audit.view_full',
        ],
        self::ROLE_FRONT_OFFICE => [
            'queue.operate', 'appointments.manage',
            'clients.create', 'clients.edit', 'clients.view',
            'sessions.manage', 'invoices.create', 'invoices.view',
            'payments.record', 'receipts.view', 'reports.view',
        ],
        self::ROLE_PERSONNEL => [
            'clients.view', 'invoices.view', 'receipts.view',
            'commissions.view', 'reports.view',
        ],
        self::ROLE_AUDIT => [
            'clients.view', 'invoices.view', 'receipts.view',
            'commissions.view', 'platform_fees.view', 'reports.view',
            'audit.view_full', 'audit.flag',
        ],
        self::ROLE_SUPER_ADMIN => [
            'platform.settings.manage', 'platform.merchants.govern',
            'platform.billing.configure', 'platform.fee_rules.manage',
            'platform.audit.view',
        ],
    ];

    /**
     * Grantable overrides per role (the `◐` cells of §10.3): not granted by
     * default, but a per-membership grant override MAY add them.
     *
     * @var array<string, list<string>>
     */
    private const GRANTABLE = [
        self::ROLE_MERCHANT_ADMIN => ['exports.finance'],
        self::ROLE_FINANCE => [
            'invoices.adjust_paid', 'payments.edit_reference', 'payments.override_duplicate',
            'receipts.reissue', 'refunds.approve', 'periods.reopen',
            'commissions.view', 'platform_fees.view', 'exports.finance',
        ],
        self::ROLE_AUDIT => ['exports.finance'],
    ];

    /** @return array<string, array{name: string, scope: string, read_only: bool, description: string}> */
    public function roles(): array
    {
        return self::ROLES;
    }

    /** @return array<string, array{category: string, description: string, mutating: bool}> */
    public function permissions(): array
    {
        $normalized = [];
        foreach (self::PERMISSIONS as $key => [$category, $description, $mutating]) {
            $normalized[$key] = [
                'category' => $category,
                'description' => $description,
                'mutating' => $mutating,
            ];
        }

        return $normalized;
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /** @return list<string> */
    public function roleKeys(): array
    {
        return array_keys(self::ROLES);
    }

    public function isMutating(string $key): bool
    {
        return self::PERMISSIONS[$key][2] ?? true;
    }

    public function isReadOnlyRole(string $roleKey): bool
    {
        return self::ROLES[$roleKey]['read_only'] ?? false;
    }

    /**
     * Default grants for a role (§10.3 ✓ / scoped cells).
     *
     * @return list<string>
     */
    public function defaultGrantsFor(string $roleKey): array
    {
        return self::DEFAULT_GRANTS[$roleKey] ?? [];
    }

    /**
     * Override-grantable keys for a role (§10.3 ◐ cells).
     *
     * @return list<string>
     */
    public function grantableFor(string $roleKey): array
    {
        return self::GRANTABLE[$roleKey] ?? [];
    }

    /** Whether a grant override of $key is permitted for $roleKey (§10.3 ◐). */
    public function isGrantableFor(string $roleKey, string $key): bool
    {
        return in_array($key, $this->grantableFor($roleKey), true);
    }
}
