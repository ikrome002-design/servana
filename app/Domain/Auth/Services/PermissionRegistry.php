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
 *   - `platform_fee.* | … | ✓ (audit)` → audit is read-only everywhere, so the
 *     single ✓ is the masked READ key `platform_fee.view`, not `…dispute`/`…dispute.review`
 *     (Phase 20E canonical, reconciled from the legacy plural `platform_fees.*`).
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
        self::ROLE_HR => ['name' => 'Human Resource', 'scope' => 'merchant', 'read_only' => false, 'description' => 'Same-branch staff lifecycle, eligibility, availability, compensation configuration.'],
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
        // REM-SCR-002A (Phase 23) — canonical §19.3 merchant-profile keys, activated as a
        // corrective remediation for an omitted Phase 15A/20A deliverable. Plan §27.3 lists
        // "merchant profile" among the Merchant Administrator launch screens, and Plan §19.3:1444
        // defines `merchant.profile.view  M|-|A|n/a|Y|-|info|-` and :1445
        // `merchant.profile.update  M|-|R|n/a|Y|-|high|-`. Both were left `planned` after their
        // owning phase completed, so the surface could not be built: no key meant no
        // EnsurePermission. Granted to the Merchant Administrator only.
        //
        //
        // The LEGACY `merchant.profile.manage` is RETIRED here, not carried alongside: the matrix
        // invariant (PermissionLegacyKeyReconciliationTest) is that a legacy key's
        // `canonical_successor` must still be PLANNED, because an active legacy key plus an active
        // successor would be two names for one authority. This follows the established precedent
        // applied four times already — Phase 20A (platform.settings.manage,
        // platform.billing.configure, platform.fee_rules.manage), Phase 20B (merchant.tier.update,
        // platform.merchants.govern), Phase 20E and Phase 20F all activated the canonical successor
        // and deleted the legacy row outright, taking the catalogue 17 → 8 legacy keys.
        // Its two consumers move to the canonical WRITE key: the `merchant_logo` file purpose
        // (FilePurposeRegistry) and the dead `MerchantPolicy::manageProfile` (removed — it had no
        // caller anywhere). A read key never grants a write.
        'merchant.profile.view' => ['merchant', 'View the merchant business profile.', false],
        'merchant.profile.update' => ['merchant', 'Update the merchant business profile.', true],
        // Merchant subscription self-service (Plan §22, §48, §49; Phase 20B canonical keys —
        // reconciled from the retired legacy `merchant.tier.update`, which mis-modelled billing as a
        // one-shot tier flag). The Merchant Administrator views the subscription/dashboard + invoices,
        // schedules/cancels a no-proration next-cycle plan change, and generates/downloads invoice
        // PDFs. Plan-change is a merchant mutation blocked in billing read-only; invoice reads +
        // existing-PDF download stay available in read-only (allow_read); new PDF generation is blocked.
        'merchant.subscription.view' => ['merchant', 'View the merchant subscription and billing status.', false],
        'merchant.subscription.plan_change' => ['merchant', 'Schedule or cancel a no-proration next-cycle plan change.', true],
        'merchant.subscription.invoice.view' => ['merchant', 'View subscription invoices (scoped).', false],
        'merchant.subscription.invoice.download' => ['merchant', 'Generate and download a subscription invoice PDF.', true],
        'branches.create' => ['merchant', 'Create and archive branches (structural lifecycle).', true],
        'branches.manage_users_lifecycle' => ['merchant', 'Invite/suspend branch_manager + hr; branch-user lifecycle.', true],
        // Financial period reopen — exceptional approval (Plan §46; ADR-0007 Decision 3;
        // Phase 18B canonical key — reconciled from the legacy Merchant-Admin `periods.lock`
        // placeholder, which mis-granted routine locking to the Merchant Administrator in
        // violation of ADR-0007 "Finance owns period_lock.create"). The Merchant Administrator
        // holds ONLY the exception-approval authority; it is `merchant.period_reopen
        // .approve_exception ⟂ period_lock.reopen` and the same user may not request and approve.
        'merchant.period_reopen.approve_exception' => ['merchant', 'Approve an exceptional financial-period reopen (Merchant Administrator; approver != requester).', true],
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
        // Branch cash-up (Plan §45; ADR-0007; Phase 18B canonical key — reconciled from the
        // legacy `cashup.submit`). Branch Manager is the maker; `branch.cash_up.submit ⟂
        // cash_up.approve` (maker/checker separation).
        'branch.cash_up.submit' => ['branch', 'Create/update and submit a branch cash-up (Branch Manager maker).', true],
        // Staff.
        // Phase 23 — canonical §19.3 READ key, activated as a security remediation. It was left
        // `planned` (owning_phase "Phase 20F") after 20F completed, so `GET /api/v1/staff` shipped
        // with NO permission middleware and NO controller authorize() call: every authenticated
        // merchant member could enumerate the branch roster INCLUDING personnel phone numbers
        // (Plan §9.1 personnel-contact extraction; RK-05). Plan §19.3 grants it to HR ONLY — the
        // Branch Manager's read-only personnel-schedule picker is served by the narrow
        // `branch.personnel-options.index` endpoint under its existing `branch.dashboard.view`,
        // never by widening this key.
        'staff.view' => ['staff', 'View the HR staff roster and staff detail (branch-scoped read).', false],
        'staff.invite' => ['staff', 'Invite operational staff (same branch).', true],
        'staff.edit' => ['staff', 'Edit staff profiles.', true],
        'staff.suspend' => ['staff', 'Suspend/deactivate operational staff.', true],
        // Personnel-service eligibility (Plan §19.2/§19.3; Phase 15A canonical key —
        // reconciled from the legacy `eligibility.manage` baseline). HR-owned.
        'personnel.eligibility.manage' => ['staff', 'Manage personnel service eligibility.', true],
        // Personnel availability (Plan §19.2/§19.3; Phase 15B canonical key —
        // reconciled from the legacy Phase 8 `availability.manage` baseline). HR-owned.
        'personnel.availability.manage' => ['staff', 'Manage personnel availability.', true],
        // Phase 20F — HR compensation-plan CONFIGURATION (canonical §19.2 keys; Plan §59, §80).
        // RECONCILED from the legacy `commissions.manage` (→ compensation.plan.update_draft) and
        // `commissions.view` (→ compensation.history.view), both RETIRED here — no alias, no
        // compatibility grant (the Phase 19 `audit.view_full` retirement precedent). Branch-scoped
        // and HR-only: Plan §10.2 says the Merchant Administrator "never configures
        // services/pricing/commissions/personnel assignment", so MA/Branch Manager receive NO
        // compensation-configuration authority and no broad replacement read. Configuration only —
        // these keys create no earned salary/commission, no payout, no ledger (20G/20H own those).
        'compensation.plan.view' => ['compensation', 'View branch-scoped compensation plans (HR).', false],
        'compensation.plan.create' => ['compensation', 'Create a draft compensation plan (HR).', true],
        'compensation.plan.update_draft' => ['compensation', 'Update a DRAFT compensation plan in place (HR); effective terms are superseded, never edited.', true],
        'compensation.plan.submit' => ['compensation', 'Submit a draft compensation plan for approval (HR maker).', true],
        'compensation.plan.approve' => ['compensation', 'Approve a pending compensation plan (HR checker; fresh step-up; never the submitter).', true],
        'compensation.plan.reject' => ['compensation', 'Reject a pending compensation plan (HR checker).', true],
        'compensation.plan.cancel' => ['compensation', 'Cancel a draft or scheduled compensation plan before it takes effect (HR).', true],
        'compensation.history.view' => ['compensation', 'View append-only compensation change history (HR; branch-scoped).', false],
        // Phase 20G — Finance compensation FINANCIAL surface (canonical §19.2 keys; Plan §60/§61, §80).
        // Merchant-scoped: `compensation.liability.view` is the masked salary/commission liability read
        // (Finance MFA); `compensation.adjustment.create` is a Finance MANUAL additive adjustment (MFA +
        // fresh step-up + high-severity audit; append-only, never a ledger edit). These are distinct
        // from the HR configuration keys above (Plan §10.2 keeps configuration HR-only).
        'compensation.liability.view' => ['compensation', 'View masked salary/commission liabilities (Finance; merchant-scoped).', false],
        'compensation.adjustment.create' => ['compensation', 'Create a manual additive compensation adjustment (Finance; MFA + fresh step-up).', true],
        // Phase 20H — payout runs + earnings (canonical §19.2 keys; Plan §62/§63, §80). HR owns the
        // draft/submit/cancel payout workflow (branch scope, no money movement); Finance verifies,
        // approves ordinary runs, rejects, and marks-paid (merchant scope; MFA + fresh step-up on
        // verify/approve/mark_paid; Idempotency-Key on the financial-mutation routes; mark_paid is
        // CRITICAL). The Merchant Administrator holds ONLY the compensation-summary read + high-value
        // approval (never create/verify/standard-approve/mark-paid — Plan §10.2). Personnel are strict
        // own-scope: earnings/compensation/payout reads, own-statement access, own earnings-query create.
        // earnings_query.respond is Finance (D-H12-1). Servana MOVES NO MONEY — mark_paid records an
        // external settlement outcome only, with no Wallet/provider call and no Gate-W dependency.
        'payout_run.create' => ['compensation', 'Create a draft personnel payout run (HR; branch-scoped).', true],
        'payout_run.update_draft' => ['compensation', 'Update/re-snapshot a draft personnel payout run (HR).', true],
        'payout_run.submit' => ['compensation', 'Submit (freeze + claim ledgers) a draft personnel payout run (HR maker).', true],
        'payout_run.cancel_draft' => ['compensation', 'Cancel a draft personnel payout run (HR).', true],
        'payout_run.verify' => ['compensation', 'Verify a submitted payout run and route by value (Finance; MFA + fresh step-up).', true],
        'payout_run.approve_standard' => ['compensation', 'Approve an ordinary-value payout run (Finance; MFA + fresh step-up).', true],
        'payout_run.reject' => ['compensation', 'Reject a pre-paid payout run and release claimed ledgers (Finance; reason required).', true],
        'payout_run.mark_paid' => ['compensation', 'Mark an approved payout run paid after an EXTERNAL settlement (Finance; MFA + fresh step-up + Idempotency-Key; no money movement).', true],
        'earnings_query.respond' => ['compensation', 'Resolve/reject a personnel earnings query; a monetary correction is an additive adjustment only (Finance).', true],
        'merchant.compensation_summary.view' => ['merchant', 'View the merchant compensation summary (Merchant Administrator; masked, currency-grouped).', false],
        'merchant.payout.approve_high_value' => ['merchant', 'Approve a high-value payout run above the snapshotted threshold (Merchant Administrator; MFA + fresh step-up).', true],
        'personnel.my_compensation.view' => ['personnel', 'View own compensation terms (own scope).', false],
        'personnel.my_earnings.view' => ['personnel', 'View own earnings overview and salary/commission tabs (own scope).', false],
        'personnel.my_statements.download' => ['personnel', 'Generate and download own paid-period earnings statements (own scope).', false],
        'personnel.my_payouts.view' => ['personnel', 'View own payout history (own scope).', false],
        'personnel.my_earnings_query.create' => ['personnel', 'Raise an earnings query against an own compensation fact (own scope).', true],
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
        // Personnel bulk SMS to PERSONALLY SERVED clients (Plan §19.3, §64; ADR-010; Phase 21S).
        // `my_served_clients.view` is the masked, paginated, rate-limited READ of clients this
        // staff profile personally served — the entry point of the SMS flow. It is a READ
        // (`allow_read` in billing read-only) and carries NO entitlement, so a merchant in grace can
        // still see their served clients. `my_sms.send` is the SENDING capability: entitlement
        // `sms`, blocked in billing read-only, warn-severity audit. NEITHER implies any export —
        // Plan §19.4 makes "Personnel can never gain contact export" non-overridable, and no
        // contact-export key exists anywhere in this catalogue.
        'personnel.my_served_clients.view' => ['personnel', 'View own personally served clients (own scope; masked contact; no export).', false],
        'personnel.my_sms.send' => ['personnel', 'Send SMS to own personally served clients (own scope; no contact export, ADR-010).', true],
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
        // Merchant-client payments (Plan §19.3, §41; Phase 18A canonical keys —
        // reconciled from the legacy placeholder `payments.record/validate/reject/
        // edit_reference/override_duplicate` baseline). Front Office is the default
        // MAKER (customer_payment.record); Finance holds view + the duplicate override
        // (MFA + step-up) + the maker-exception capability. The Phase-18B checker keys
        // The Phase-18B checker key `customer_payment.validate` is activated here
        // (Finance default); reject/reference_correct land with their Slice-4 routes.
        // REM-PERM-001 (full §19.3 matrix closure) stays open for Phase 19. Maker/
        // checker separation is structural: no role holds both a recording key
        // (customer_payment.record / .record_exception) and .validate, and the
        // per-transaction PaymentMakerCheckerGuard blocks a checker == the group maker.
        'customer_payment.record' => ['finance', 'Record a merchant-client payment recording group (Front Office maker).', true],
        'customer_payment.view' => ['finance', 'View merchant-client payment recording groups (scoped, masked).', false],
        'customer_payment.duplicate_override' => ['finance', 'Override a suspected duplicate payment reference (Finance; MFA + step-up).', true],
        'customer_payment.record_exception' => ['finance', 'Record a merchant-client payment as a Finance maker exception.', true],
        'customer_payment.validate' => ['finance', 'Validate a whole pending payment recording group (Finance checker; maker != checker).', true],
        'customer_payment.reject' => ['finance', 'Reject or request correction of a whole pending payment recording group (Finance checker).', true],
        'customer_payment.reference_correct' => ['finance', 'Correct a component payment reference on a correctable group and resubmit (Finance).', true],
        // Receipts.
        'receipt.view' => ['finance', 'View receipts (scoped).', false],
        'receipt.reissue' => ['finance', 'Reissue a receipt.', true],
        // Refunds / disputes / cash-up.
        'refund.create' => ['finance', 'Request an external refund against a validated payment component (Finance maker).', true],
        'refund.approve' => ['finance', 'Approve a refund (Finance checker; approver != requester; fresh step-up).', true],
        'refund.finalize' => ['finance', 'Finalize an approved refund and reduce the recognised balance (fresh step-up).', true],
        'finance_dispute.manage' => ['finance', 'Open, review, resolve, or reject a finance dispute (Finance).', true],
        // Cash-up review (Plan §45; ADR-0007; Phase 18B canonical keys — reconciled from the
        // legacy Finance `cashup.review_approve`). Finance is the checker; the approver/rejecter
        // must be a DIFFERENT principal from the Branch Manager who submitted (actor guard).
        'cash_up.view' => ['finance', 'View branch cash-ups (scoped).', false],
        'cash_up.approve' => ['finance', 'Approve a submitted cash-up (Finance checker; approver != submitter).', true],
        'cash_up.reject' => ['finance', 'Reject a submitted cash-up (Finance checker).', true],
        'cash_up.request_correction' => ['finance', 'Return a submitted cash-up for correction (Finance checker).', true],
        // Financial period locks (Plan §46; ADR-0007 Decision 2/3; Phase 18B canonical keys —
        // reconciled from the legacy Finance-grantable `periods.reopen`). Finance owns lock
        // creation + reopen execution (reopen requires fresh MFA + mandatory reason).
        'period_lock.create' => ['finance', 'Create a financial period lock (Finance).', true],
        'period_lock.reopen' => ['finance', 'Execute a controlled reopen of a locked financial period (Finance; fresh MFA).', true],
        // Platform fees. (The legacy `commissions.view` that lived here is RETIRED — Phase 20F
        // activated its HR-only canonical successor `compensation.history.view` above. Finance's
        // compensation READ is `compensation.liability.view` (Phase 20G), the Merchant
        // Administrator's is `merchant.compensation_summary.view` (Phase 20H), Personnel's is
        // `earnings.*` (Phase 20H), and Audit already reads the domain through the masked
        // `audit.compensation.view`. None of those is granted here — no broad replacement.)
        // Phase 20E — percentage platform-fee merchant surface (canonical §19.2 keys; RECONCILED from
        // the legacy plural `platform_fees.view` / `platform_fees.dispute`, whose singular canonical
        // successors match the Phase 20E `platform_fee_*` domain naming and are wired to real policies
        // + audit events here). Merchant-scoped (no `platform.` prefix). Read is masked + server-side
        // role-scoped (merchant-wide vs branch-attributable); dispute create is a tenant mutation;
        // dispute review/resolve/reject is Finance-only (fresh step-up on resolve/reject; a money change
        // creates an additive platform_fee_adjustment — never a ledger edit).
        'platform_fee.view' => ['finance', 'View Citrus percentage platform fees (scoped, masked).', false],
        'platform_fee.dispute' => ['finance', 'Raise a dispute against a Citrus percentage platform fee.', true],
        'platform_fee.dispute.review' => ['finance', 'Review, resolve, or reject a platform-fee dispute (Finance; fresh step-up on resolve/reject).', true],
        // Reports & exports.
        'reports.view' => ['reports', 'View reports (scoped).', false],
        // Finance exports (Plan §65, §67; Phase 18B canonical keys — reconciled from the legacy
        // grantable `exports.finance`). Finance requests a scoped, masked export (fresh step-up)
        // and downloads it via an authorized signed link. `finance_export.*` is `PL n/a`.
        'finance_export.create' => ['reports', 'Request a scoped, masked finance export (Finance; fresh step-up).', true],
        'finance_export.download' => ['reports', 'Download a ready finance export via an authorized signed link (Finance).', false],
        'exports.staff_roster' => ['reports', 'Export the staff roster only.', false],
        // Finance audit surface (Plan §19.2/§19.3; Phase 19). The Finance role's own,
        // branch-scoped, masked read of the finance-domain audit trail (distinct
        // authority from the Audit role's `audit.finance.view`).
        'finance.audit.view' => ['finance', 'View the branch-scoped finance-domain audit trail (Finance; masked).', false],
        // Audit (Plan §19.2/§19.3; Phase 19 canonical closure — REPLACES the legacy
        // catch-all `audit.view_full`, which is RETIRED entirely: it granted raw
        // audit-log reads to Merchant Admin/Branch Manager/HR/Finance/Audit alike,
        // in conflict with the branch-scoped, domain-segmented canonical matrix
        // (Plan controls; source-of-truth correction recorded in docs/proof/phase-19.md).
        // Reads are field-masked and branch-scoped; the flagged-event review workflow
        // mutates review metadata only and never a source record.
        'audit.branch_events.view' => ['audit', 'View branch-scoped general audit events + the flagged-event queue (masked).', false],
        'audit.finance.view' => ['audit', 'View branch-scoped finance-domain audit events (Audit; masked).', false],
        'audit.compensation.view' => ['audit', 'View branch-scoped compensation-domain audit events (Audit; masked).', false],
        // Audit export (Plan §13.5, §19.2/§19.3, §80; Phase 19; ADR-010). Audit's in-domain
        // export capability — requests + downloads a reason-gated, branch-scoped, masked,
        // signed/expiring, download-counted audit CSV. Fresh step-up required (SU Y). It
        // creates an audit_exports request row + a private file but never mutates a source
        // record, so it is Audit's in-domain write (like the flagged-event review keys).
        'audit.export' => ['audit', 'Request + download a reason-gated, masked, signed audit export (Audit; fresh step-up).', true],
        'audit.flagged_event.create' => ['audit', 'Flag a branch-scoped audit event for review.', true],
        'audit.flagged_event.update_status' => ['audit', 'Start review / reopen a flagged audit event.', true],
        'audit.flagged_event.resolve_metadata' => ['audit', 'Resolve or dismiss a flagged audit event (records a review outcome).', true],
        // Platform (super_admin only).
        // Platform merchant governance (Plan §22, §24.1; Phase 20B canonical keys — reconciled from
        // the retired legacy catch-all `platform.merchants.govern`, whose single blunt authority is
        // truthfully split into a read surface + three operational-status mutations). These mutate
        // `merchants.status` ONLY (never `merchants.billing_status`) and never create subscription or
        // payment rows. Suspend/reactivate/deactivate require a mandatory reason + fresh step-up.
        'platform.registration_monitor.view' => ['platform', 'View the merchant registration-monitoring surface.', false],
        'platform.merchant.view' => ['platform', 'View a merchant governance record (operational + billing status).', false],
        'platform.merchant.suspend' => ['platform', 'Suspend a merchant operationally (merchants.status; never billing recovery).', true],
        'platform.merchant.reactivate' => ['platform', 'Reactivate an operationally-suspended merchant (never clears billing suspension).', true],
        'platform.merchant.deactivate' => ['platform', 'Deactivate a merchant operationally (terminal governance state).', true],
        'platform.audit.view' => ['platform', 'View the platform audit trail.', false],
        // Phase 20A — platform billing catalogue governance (canonical §19.2 keys; the legacy
        // platform.settings.manage / platform.billing.configure / platform.fee_rules.manage keys
        // are retired here — their authority is subsumed by these canonical keys, no consumers).
        'platform.settings.view' => ['platform', 'View general platform settings.', false],
        'platform.settings.update' => ['platform', 'Update general platform settings.', true],
        'platform.billing_settings.view' => ['platform', 'View platform billing settings.', false],
        'platform.billing_settings.update' => ['platform', 'Update platform billing settings (mode/trial/grace/currency).', true],
        'platform.plan.view' => ['platform', 'View subscription plans, prices and entitlements.', false],
        'platform.plan.manage' => ['platform', 'Create/update/retire plans and manage entitlements.', true],
        'platform.plan_price.manage' => ['platform', 'Create/schedule/cancel effective-dated plan prices.', true],
        'platform.preferred_personnel_fee.manage' => ['platform', 'Manage preferred-personnel fee rules (create/approve/supersede/cancel).', true],
        // Phase 20A — branch-scoped read of the effective preferred-personnel fee rule (read-only).
        'preferred_personnel_fee.view_branch_rule' => ['catalogue', 'View the effective preferred-personnel fee rule for a branch (read-only).', false],
        // Phase 20C — platform-governed promotions & free-period offers (Plan §53). Super-Admin only,
        // platform scope, MFA + fresh step-up + high-severity audit. No merchant role receives these.
        'platform.promotion.manage' => ['platform', 'Manage promotional discounts (create/approve/pause/resume/cancel).', true],
        'platform.free_period_offer.manage' => ['platform', 'Manage free-period (trial-length) offers (create/approve/pause/resume/cancel).', true],
        // Phase 20E — percentage platform-fee CONFIGURATION governance (Plan §51, §52). Super-Admin only,
        // platform scope, MFA + fresh BillingConfiguration step-up + high-severity audit. Approved
        // monetary terms are immutable — a change is a supersede (new version), never an in-place edit.
        // No merchant role receives this key; it never validates payments, fabricates ledger rows, or
        // settles liabilities.
        'platform.platform_fee.configure' => ['platform', 'Manage percentage platform-fee configurations (create/update-draft/approve/supersede/cancel).', true],
    ];

    /**
     * Default grants per role (the ✓ / scoped cells of §10.3).
     *
     * @var array<string, list<string>>
     */
    private const DEFAULT_GRANTS = [
        self::ROLE_MERCHANT_ADMIN => [
            // REM-SCR-002A — canonical merchant-profile authority (Plan §19.3 "default_roles:
            // merchant_admin"), replacing the retired legacy `merchant.profile.manage`. No other
            // role receives either key.
            'merchant.profile.view', 'merchant.profile.update',
            // Phase 20B — subscription self-service (replaces the retired `merchant.tier.update`).
            'merchant.subscription.view', 'merchant.subscription.plan_change',
            'merchant.subscription.invoice.view', 'merchant.subscription.invoice.download',
            'branches.create', 'branches.manage_users_lifecycle',
            // No invoice key (Plan §10.2/§19.3): Merchant Admin is the account owner,
            // not an operational invoicer; invoice visibility is via reports.view.
            'receipt.view',
            // Financial period authority (ADR-0007 Decision 3): the Merchant Administrator
            // holds ONLY exceptional-reopen approval — NOT routine locking/reopen (Finance).
            'merchant.period_reopen.approve_exception',
            // Phase 20E — merchant-wide masked platform-fee read + dispute creation (§5 role boundary).
            // Phase 20F: the legacy `commissions.view` grant is RETIRED, not retained (Plan §10.2 —
            // the Merchant Administrator never configures commissions). Its compensation
            // visibility arrives as `merchant.compensation_summary.view` in Phase 20H.
            'platform_fee.view', 'platform_fee.dispute',
            // Phase 20H — the Merchant Administrator's compensation surface: the masked, currency-grouped
            // summary read + high-value payout approval ONLY (Plan §10.2/§62 — no create/verify/standard-
            // approve/mark-paid; those stay HR/Finance). High-value approval is MFA + fresh step-up.
            'merchant.compensation_summary.view', 'merchant.payout.approve_high_value',
            // Phase 19: NO direct raw audit-log key (canonical §19.3 — the Merchant
            // Administrator's oversight is via reports/dashboards, not the raw trail;
            // the legacy `audit.view_full` grant is RETIRED, not retained).
            'reports.view',
        ],
        self::ROLE_BRANCH_MANAGER => [
            // Queue: Branch Manager configures (open/close, capacity, default mode)
            // via branch.profile.manage + day.open_close and reads via
            // branch.dashboard.view — NO operational queue.* key (Phase 16B; the
            // legacy queue.operate/queue.transfer_entries/queue.configure grants are
            // removed, not retained).
            'branch.profile.manage', 'branch.calendar.manage', 'branch.dashboard.view',
            'service.view', 'service.create', 'service.update', 'service.archive',
            'day.open_close', 'branch.cash_up.submit',
            // No service_session.* — Branch Manager has NO session mutation authority
            // (Phase 16C; the legacy sessions.manage grant is removed, not retained).
            // No invoice key (Plan §10.2/§19.3): Branch Manager must NOT receive invoice
            // creation; the legacy placeholder grant of invoices.create is removed, not
            // retained. Branch invoice visibility is via branch.dashboard.view/reports.
            // Phase 20E — branch-attributable masked platform-fee read only (server-side branch scope).
            // Phase 20F: the legacy `commissions.view` grant is RETIRED, not retained — the Branch
            // Manager receives NO compensation-configuration authority and no replacement read.
            'receipt.view', 'platform_fee.view',
            // Phase 19: NO direct raw audit-log key (canonical §19.3 — Branch Manager
            // oversight is via branch.dashboard.view/reports; legacy `audit.view_full` retired).
            'reports.view',
            // Phase 20A — read-only view of the effective preferred-personnel fee rule for the
            // branch (no management authority; rules are Super-Admin-governed).
            'preferred_personnel_fee.view_branch_rule',
        ],
        self::ROLE_HR => [
            // Phase 23 — `staff.view` is the roster/detail READ authority (Plan §19.3 "HR and Staff
            // (default_roles: hr)"). No other role receives it: Branch Manager reads personnel
            // options through the narrow branch.personnel-options endpoint.
            'staff.view',
            'staff.invite', 'staff.edit', 'staff.suspend',
            'personnel.eligibility.manage', 'personnel.availability.manage',
            // Phase 20F — HR owns compensation CONFIGURATION end to end (Plan §59; branch-scoped).
            // These are the canonical successors of the retired `commissions.manage`
            // (→ compensation.plan.update_draft) and `commissions.view` (→ compensation.history.view).
            // approve is maker/checker-incompatible with submit and needs a fresh step-up; both are
            // enforced at the route + action, not by this grant.
            'compensation.plan.view', 'compensation.plan.create', 'compensation.plan.update_draft',
            'compensation.plan.submit', 'compensation.plan.approve', 'compensation.plan.reject',
            'compensation.plan.cancel', 'compensation.history.view',
            // Phase 20H — HR owns the payout DRAFT workflow (branch-scoped): create/update/submit/cancel.
            // HR never verifies, approves, or marks paid (Plan §10.2/§62 — those are Finance/Merchant
            // Admin). Submit freezes the run and claims the ledgers; it carries no money movement.
            'payout_run.create', 'payout_run.update_draft', 'payout_run.submit', 'payout_run.cancel_draft',
            // Phase 19: NO direct raw audit-log key (canonical §19.3 — HR oversight is via
            // staff.history/reports; legacy `audit.view_full` retired).
            'reports.view',
            'exports.staff_roster',
        ],
        self::ROLE_FINANCE => [
            'invoice.view', 'invoice.void.request_or_execute_as_policy', 'invoice.adjustment.manage',
            // Merchant-client payments (Phase 18A): Finance is NEVER the default maker
            // (no customer_payment.record). Finance holds read, the duplicate override
            // (MFA + step-up on the route), and the maker-exception capability. The
            // Phase-18B checker keys (customer_payment.validate/reject/reference_correct)
            // are not granted here — they land with the Phase 18B validation workflow.
            'customer_payment.view', 'customer_payment.duplicate_override', 'customer_payment.record_exception',
            'customer_payment.validate', 'customer_payment.reject', 'customer_payment.reference_correct',
            'receipt.view', 'receipt.reissue', 'refund.create', 'finance_dispute.manage',
            // Cash-up review (checker), period locking + reopen execution, and finance
            // exports are Finance defaults (ADR-0007; Plan §45/§46/§65). branch.cash_up
            // .submit is NOT held here (maker/checker separation).
            'cash_up.view', 'cash_up.approve', 'cash_up.reject', 'cash_up.request_correction',
            'period_lock.create', 'period_lock.reopen',
            'finance_export.create', 'finance_export.download',
            // Phase 20E — Finance holds the settled/reconciliation read, dispute creation, and the
            // dispute review/resolve/reject authority (fresh step-up on resolve/reject; §5 role boundary).
            'platform_fee.view', 'platform_fee.dispute', 'platform_fee.dispute.review',
            // Phase 19: canonical Finance audit surface (branch-scoped, masked, finance
            // domain only) — REPLACES the legacy catch-all `audit.view_full`.
            'reports.view', 'finance.audit.view',
            // Phase 20G — Finance compensation FINANCIAL surface (merchant-scoped): the masked
            // salary/commission liability read, and the manual additive adjustment (MFA + fresh
            // step-up + high-severity audit). Configuration stays HR-only (Plan §10.2).
            'compensation.liability.view', 'compensation.adjustment.create',
            // Phase 20H — Finance owns payout verification/approval/rejection/mark-paid and earnings-query
            // resolution (merchant-scoped; MFA group-level; fresh step-up on verify/approve/mark_paid;
            // Idempotency-Key on the financial-mutation routes). mark_paid records an EXTERNAL settlement
            // only — no money movement, no Wallet/provider call. A query correction is an additive
            // compensation adjustment, never a ledger edit.
            'payout_run.verify', 'payout_run.approve_standard', 'payout_run.reject', 'payout_run.mark_paid',
            'earnings_query.respond',
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
            // Merchant-client payments (Phase 18A): Front Office is the default MAKER
            // only (customer_payment.record). The recording POST returns the created
            // group resource directly, so no separate view grant is needed; the
            // pending-group list/detail is a Finance surface (customer_payment.view).
            'customer_payment.record',
            'receipt.view', 'reports.view',
        ],
        self::ROLE_PERSONNEL => [
            'personnel.my_appointments.view', 'personnel.my_queue.view',
            'personnel.my_sessions.view',
            // No invoice key (Plan §19.3): Personnel are strict own-scope and receive
            // no broad invoice browsing; the legacy invoices.view grant is removed.
            'receipt.view',
            // Phase 20F: the legacy `commissions.view` grant is RETIRED, not retained. Personnel
            // never see compensation CONFIGURATION; their own-earnings visibility arrives as
            // `earnings.*` in Phase 20H.
            'reports.view',
            // Phase 20H — Personnel own-scope earnings surface (Plan §63): own compensation terms,
            // earnings overview + salary/commission tabs, payout history, own paid-period statements,
            // and raising an earnings query against an own fact. Strict own-scope (no other staff, no
            // export, no mutation of any money fact).
            'personnel.my_compensation.view', 'personnel.my_earnings.view',
            'personnel.my_statements.download', 'personnel.my_payouts.view',
            'personnel.my_earnings_query.create',
            // Phase 21S — own-scope served-client read + SMS send (Plan §64). Granted to
            // PERSONNEL ONLY: no other role receives either key, and neither is grantable as an
            // override (both are non_overridable in the matrix). Contact export does not exist.
            'personnel.my_served_clients.view', 'personnel.my_sms.send',
        ],
        self::ROLE_AUDIT => [
            // No invoice key (Plan §19.3): Audit reads finance activity through the
            // finance-domain audit view, not a direct invoice key.
            'receipt.view',
            // Phase 20E — masked branch-scoped platform-fee read only (Audit is read-only everywhere).
            // Phase 20F: the legacy `commissions.view` grant is RETIRED, not retained — Audit reads
            // the compensation domain through the masked `audit.compensation.view` below, which
            // Phase 20F now populates with real events.
            'platform_fee.view', 'reports.view',
            // Phase 19 — canonical, domain-segmented, branch-scoped, masked Audit reads
            // (REPLACE the retired catch-all `audit.view_full`).
            'audit.branch_events.view', 'audit.finance.view', 'audit.compensation.view',
            // Audit export (Phase 19; ADR-010) — Audit's in-domain export capability (request +
            // download; SU Y). Creates an export request + private file, never a source record.
            'audit.export',
            // The flagged-event review workflow is Audit's in-domain write set (reconciled
            // from the single legacy `audit.flag`). It mutates review metadata only; Audit
            // remains read-only over every source record.
            'audit.flagged_event.create', 'audit.flagged_event.update_status', 'audit.flagged_event.resolve_metadata',
        ],
        self::ROLE_SUPER_ADMIN => [
            // Phase 20B — merchant governance (replaces the retired `platform.merchants.govern`).
            'platform.registration_monitor.view', 'platform.merchant.view',
            'platform.merchant.suspend', 'platform.merchant.reactivate', 'platform.merchant.deactivate',
            'platform.audit.view',
            // Phase 20A — platform billing catalogue governance (replaces the retired
            // platform.settings.manage / platform.billing.configure / platform.fee_rules.manage).
            'platform.settings.view', 'platform.settings.update',
            'platform.billing_settings.view', 'platform.billing_settings.update',
            'platform.plan.view', 'platform.plan.manage',
            'platform.plan_price.manage',
            'platform.preferred_personnel_fee.manage',
            // Phase 20C — promotions & free-period offers (Plan §53).
            'platform.promotion.manage', 'platform.free_period_offer.manage',
            // Phase 20E — percentage platform-fee configuration governance (Plan §51/§52).
            'platform.platform_fee.configure',
        ],
    ];

    /**
     * Grantable overrides per role (the `◐` cells of §10.3): not granted by
     * default, but a per-membership grant override MAY add them.
     *
     * @var array<string, list<string>>
     */
    private const GRANTABLE = [
        self::ROLE_FINANCE => [
            // invoice.adjustment.manage is a Finance DEFAULT grant (Plan §19.3), not a
            // grantable override; the legacy grantable invoices.adjust_paid is removed.
            // The legacy grantable payments.edit_reference/override_duplicate are removed:
            // customer_payment.duplicate_override is now a Finance DEFAULT (Phase 18A),
            // and the reference-correction key is a Phase-18B concern.
            // receipt.reissue is now a Finance DEFAULT (Phase 18B), not a grantable override.
            // refund.create ⟂ refund.approve ⟂ refund.finalize: approve + finalize are
            // grantable overrides on a DISTINCT Finance membership from the requester;
            // the per-transaction actor guard also enforces requester != approver !=
            // finalizer. REM-PERM-001 owns registry-level incompatibility closure (Ph19).
            'refund.approve', 'refund.finalize',
            // platform_fee.view is now a Finance DEFAULT (Phase 20E settled/reconciliation read), so it
            // is no longer a grantable-only override here.
            // Phase 20F: the grantable legacy `commissions.view` is RETIRED, not retained. Finance's
            // canonical compensation read is `compensation.liability.view` — a Phase 20G key now ACTIVE
            // as a Finance DEFAULT (above), together with `compensation.adjustment.create`.
        ],
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
