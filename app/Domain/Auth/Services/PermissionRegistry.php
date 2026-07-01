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
        // Branch operational dashboard read (Plan §19 matrix `branch.dashboard.view`;
        // Phase 15B canonical activation). Backs the Branch Manager's read-only
        // personnel availability/eligibility visibility. Contributes to — does NOT
        // close — REM-PERM-001 (Phase 19 owns the full matrix closure).
        'branch.dashboard.view' => ['branch', 'View the branch operational dashboard (read-only).', false],
        // Catalogue (Plan §19.2/§19.3; Phase 15A canonical keys — reconciled from the
        // legacy `services.manage` baseline). Branch Manager owns the catalogue.
        'service.view' => ['catalogue', 'View services (scoped).', false],
        'service.create' => ['catalogue', 'Create services.', true],
        'service.update' => ['catalogue', 'Update services.', true],
        'service.archive' => ['catalogue', 'Archive a service.', true],
        // Queue operations (Plan §19, §25.2, §37; Phase 16B canonical keys —
        // reconciled from the legacy Phase 8 `queue.operate`/`queue.transfer_entries`/
        // `queue.configure` baseline). Front Office owns operational queue work
        // (branch scope). Because the canonical catalogue defines no separate
        // call/cancel/no-show/start/complete key, those lifecycle actions are
        // enforced through `queue.assign` (no invented keys). Branch Manager gets
        // NONE of these — queue configuration uses branch.profile.manage +
        // day.open_close, and read-only visibility uses branch.dashboard.view.
        'queue.view' => ['queue', 'View the branch queue (branch-scoped).', false],
        'queue.create' => ['queue', 'Create queue entries (walk-ins, appointment conversion).', true],
        'queue.assign' => ['queue', 'Assign/call/start/complete/cancel/no-show queue entries.', true],
        'queue.transfer' => ['queue', 'Transfer queue entries between personnel.', true],
        'queue.reorder' => ['queue', 'Reorder waiting queue entries.', true],
        'preferred_personnel.select' => ['queue', 'Record a preferred-personnel request on a queue entry.', true],
        // Appointments (Plan §19.2/§19.3, §36; Phase 16A canonical keys — reconciled
        // from the legacy Phase 8 `appointments.manage` baseline). Front Office owns
        // appointment operations (branch scope); no-show is authorised via
        // appointment.cancel (no separate key). Branch Manager gets NONE of these —
        // read-only appointment visibility uses branch.dashboard.view.
        'appointment.view' => ['scheduling', 'View appointments (branch-scoped).', false],
        'appointment.create' => ['scheduling', 'Create appointments.', true],
        'appointment.reschedule' => ['scheduling', 'Reschedule appointments.', true],
        'appointment.cancel' => ['scheduling', 'Cancel appointments and mark no-shows.', true],
        'appointment.check_in' => ['scheduling', 'Check in appointment clients.', true],
        'appointment.assign' => ['scheduling', 'Assign personnel to appointments.', true],
        'appointment.transfer' => ['scheduling', 'Transfer appointments between personnel.', true],
        'day.open_close' => ['branch', 'Open and close the branch business day.', true],
        'cashup.submit' => ['branch', 'Submit a branch cash-up.', true],
        // Staff.
        'staff.invite' => ['staff', 'Invite operational staff (same branch).', true],
        'staff.edit' => ['staff', 'Edit staff profiles.', true],
        'staff.suspend' => ['staff', 'Suspend/deactivate operational staff.', true],
        // Personnel-service eligibility (Plan §19.2/§19.3; Phase 15A canonical key —
        // reconciled from the legacy `eligibility.manage` baseline). HR-owned.
        'personnel.eligibility.manage' => ['staff', 'Manage personnel service eligibility.', true],
        // Personnel availability (Plan §19.2/§19.3; Phase 15B canonical key —
        // reconciled from the legacy Phase 8 `availability.manage` baseline). HR-owned.
        'personnel.availability.manage' => ['staff', 'Manage personnel availability.', true],
        'commissions.manage' => ['staff', 'Set staff commissions.', true],
        // Clients (Plan §19.2/§19.3; Phase 15A canonical keys — reconciled from the
        // legacy `clients.create/edit/view` baseline). Front Office owns client records
        // and client search; contact is masked at read and never exported.
        'client.view' => ['clients', 'View client records (scoped, masked).', false],
        'client.create' => ['clients', 'Create client records.', true],
        'client.update' => ['clients', 'Update client records.', true],
        'front_office.search' => ['front_office', 'Search clients (branch-scoped, masked).', false],
        // Personnel own-scope appointment read (Plan §19.3, §36; Phase 16A). Personnel
        // see ONLY appointments assigned to their own staff profile; no mutation, no
        // branch-wide search, no contact export.
        'personnel.my_appointments.view' => ['personnel', 'View own assigned appointments (own scope).', false],
        // Personnel own-scope queue read (Plan §19, §37; Phase 16B). Personnel see
        // ONLY queue entries assigned to their own staff profile; no branch-wide
        // queue, no mutation, no contact export.
        'personnel.my_queue.view' => ['personnel', 'View own assigned queue entries (own scope).', false],
        // Service sessions (Plan §19.2/§19.3, §25.2; Phase 16C canonical keys —
        // reconciled from the legacy `sessions.manage` baseline). Front Office owns
        // service-session operations (branch scope): view + the start/complete/cancel
        // lifecycle. The queue orchestration routes additionally require these on top
        // of queue.assign. Branch Manager gets NONE of these (no session mutation
        // authority; the legacy sessions.manage grant is removed, not retained).
        'service_session.view' => ['operations', 'View service sessions (branch-scoped).', false],
        'service_session.start' => ['operations', 'Start a service session (from a queue entry).', true],
        'service_session.complete' => ['operations', 'Complete a service session (non-payable commission preview).', true],
        'service_session.cancel' => ['operations', 'Cancel a service session.', true],
        // Personnel own-scope session read (Plan §19.3, §25.2; Phase 16C). Personnel
        // see ONLY sessions assigned to their own staff profile; no branch-wide
        // sessions, no mutation, no contact export, no earned/payable claim.
        'personnel.my_sessions.view' => ['personnel', 'View own assigned service sessions (own scope).', false],
        // Invoices (Plan §19.3, §40; Phase 17 canonical keys — reconciled from the
        // legacy placeholder `invoices.create/view/void_unpaid/void_paid/adjust_paid`
        // baseline, which mis-granted invoice creation to Branch Manager + Merchant
        // Admin in violation of Plan §10.2). Front Office owns invoice.view +
        // invoice.create (branch scope); Finance owns invoice.view + the void/adjust
        // workflow (M/B scope, MFA, void requires step-up). No other role holds an
        // invoice key — Merchant Admin/Branch Manager/Personnel/Audit read invoices
        // through reports/dashboard/audit permissions, not a direct invoice key.
        'invoice.view' => ['finance', 'View invoices (scoped, masked client).', false],
        'invoice.create' => ['finance', 'Create and finalize merchant-client invoices.', true],
        'invoice.void.request_or_execute_as_policy' => ['finance', 'Request/execute/reject an invoice void (Finance).', true],
        'invoice.adjustment.manage' => ['finance', 'Adjust an invoice additively (Finance).', true],
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
            // No invoice key (Plan §10.2/§19.3): Merchant Admin is the account owner,
            // not an operational invoicer; invoice visibility is via reports.view.
            'receipts.view',
            'periods.lock', 'commissions.view', 'platform_fees.view',
            'reports.view', 'audit.view_full',
        ],
        self::ROLE_BRANCH_MANAGER => [
            // Queue: Branch Manager configures (open/close, capacity, default mode)
            // via branch.profile.manage + day.open_close and reads via
            // branch.dashboard.view — NO operational queue.* key (Phase 16B; the
            // legacy queue.operate/queue.transfer_entries/queue.configure grants are
            // removed, not retained).
            'branch.profile.manage', 'branch.calendar.manage', 'branch.dashboard.view',
            'service.view', 'service.create', 'service.update', 'service.archive',
            'day.open_close', 'cashup.submit',
            // No service_session.* — Branch Manager has NO session mutation authority
            // (Phase 16C; the legacy sessions.manage grant is removed, not retained).
            // No invoice key (Plan §10.2/§19.3): Branch Manager must NOT receive invoice
            // creation; the legacy placeholder grant of invoices.create is removed, not
            // retained. Branch invoice visibility is via branch.dashboard.view/reports.
            'receipts.view', 'commissions.view', 'platform_fees.view',
            'reports.view', 'audit.view_full',
        ],
        self::ROLE_HR => [
            'staff.invite', 'staff.edit', 'staff.suspend',
            'personnel.eligibility.manage', 'personnel.availability.manage', 'commissions.manage',
            'commissions.view', 'reports.view', 'audit.view_full',
            'exports.staff_roster',
        ],
        self::ROLE_FINANCE => [
            'invoice.view', 'invoice.void.request_or_execute_as_policy', 'invoice.adjustment.manage',
            'payments.record', 'payments.validate', 'payments.reject',
            'receipts.view', 'refunds.request', 'disputes.manage',
            'cashup.review_approve', 'platform_fees.dispute',
            'reports.view', 'audit.view_full',
        ],
        self::ROLE_FRONT_OFFICE => [
            // Queue operations (Phase 16B): Front Office owns the operational queue
            // (replaces the legacy queue.operate). Call/start/complete/cancel/no-show
            // are enforced through queue.assign (no separate keys).
            'queue.view', 'queue.create', 'queue.assign', 'queue.transfer', 'queue.reorder',
            'preferred_personnel.select',
            'appointment.view', 'appointment.create', 'appointment.reschedule',
            'appointment.cancel', 'appointment.check_in', 'appointment.assign',
            'appointment.transfer',
            'client.view', 'client.create', 'client.update', 'front_office.search',
            // Service sessions (Phase 16C): Front Office owns the operational session
            // lifecycle (replaces the legacy sessions.manage).
            'service_session.view', 'service_session.start',
            'service_session.complete', 'service_session.cancel',
            // Invoices (Phase 17): Front Office owns invoice viewing + creation/
            // finalization (canonical invoice.view/invoice.create; replaces the legacy
            // invoices.view/invoices.create). No void/adjust — that is Finance only.
            'invoice.view', 'invoice.create',
            'payments.record', 'receipts.view', 'reports.view',
        ],
        self::ROLE_PERSONNEL => [
            'personnel.my_appointments.view', 'personnel.my_queue.view',
            'personnel.my_sessions.view',
            // No invoice key (Plan §19.3): Personnel are strict own-scope and receive
            // no broad invoice browsing; the legacy invoices.view grant is removed.
            'receipts.view',
            'commissions.view', 'reports.view',
        ],
        self::ROLE_AUDIT => [
            // No invoice key (Plan §19.3): Audit reads invoices through audit.view_full/
            // reports, not a direct invoice key; the legacy invoices.view grant is removed.
            'receipts.view',
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
            // invoice.adjustment.manage is a Finance DEFAULT grant (Plan §19.3), not a
            // grantable override; the legacy grantable invoices.adjust_paid is removed.
            'payments.edit_reference', 'payments.override_duplicate',
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
