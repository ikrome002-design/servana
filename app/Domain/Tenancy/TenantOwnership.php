<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Billing\Models\BillingEscalationEvent;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformFeeAdjustment;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use App\Domain\Branches\Models\BranchCalendarException;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\CashUpLine;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientConsent;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CommissionRuleService;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\CompensationPlanHistory;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\Hr\Models\StaffHistory;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use App\Domain\Merchants\Models\MerchantProfile;
use App\Domain\Merchants\Models\MerchantStatusHistory;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Models\SmsBillingEntry;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Receipts\Models\ReceiptNumberSequence;
use App\Domain\Refunds\Models\Refund;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Scheduling\Models\WalkIn;

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
        // Phase 15B — Personnel availability (Plan §13.7, §80).
        'personnel_availability',
        // Phase 16A — Appointments (Plan §13.7, §36, §80).
        'appointments',
        // Phase 16B — Walk-ins & queues (Plan §13.7, §37, §80).
        'walk_ins',
        'queue_entries',
        // Phase 16C — Service sessions (Plan §13.7, §25.2, §80).
        'service_sessions',
        // Phase 17 — Invoicing (Plan §13.8, §40, §80).
        'invoices',
        'invoice_items',
        // Phase 18A — Merchant-client payment recording (Plan §13.8, §13.15, §41, §80).
        'payment_recording_groups',
        'payment_records',
        'payment_allocations',
        'payment_reference_checks',
        // Phase 18B — Validation, receipts, refunds, disputes, cash-up, commission seam.
        'payment_validation_events',
        'receipts',
        'refunds',
        'finance_disputes',
        'cash_up_lines',
        'commission_handoff_events',
        // Phase 19 — Audit flagged-event review record over a branch-scoped audit row.
        'audit_flagged_events',
        // Phase 19 — branch-scoped Audit export request (ADR-010).
        'audit_exports',
        // Phase 20F — HR compensation configuration (Plan §59, §80; Scope §12.9 "one active
        // compensation plan per personnel per branch"). Configuration only — no earned fact.
        'commission_rules',
        'personnel_compensation_plans',
        'compensation_plan_history',
        // Phase 20G — branch-owned salary/commission ledgers + adjustments + selected-services
        // membership substrate (Plan §60/§61/§13.12; §9.1). Append-only financial facts +
        // configuration substrate. Earned only at Finance validation; salary accrued by scheduler.
        'commission_ledger',
        'salary_ledger',
        'compensation_adjustments',
        'commission_rule_services',
        // Phase 20H — branch-owned payout runs/items + personnel own-scope earnings queries
        // (Plan §62/§63/§13.12). Payout workflow + earnings surfaces over the 20G ledgers.
        'personnel_payout_runs',
        'personnel_payout_items',
        'earnings_queries',
        // Phase 21S — Personnel bulk SMS to personally served clients (Plan §13.13, §64; ADR-010).
        // The campaign is branch-owned + personnel own-scope; the recipient snapshot carries
        // merchant_id + branch_id (repo convention for child tables, cf. cash_up_lines) so a
        // recipient can never reference a client, session or campaign across a merchant boundary;
        // the billing entry is branch-owned per the §13.13 canonical DDL.
        'personnel_sms_campaigns',
        'personnel_sms_recipients',
        'sms_billing_entries',
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
        // Phase 17 — merchant-wide invoice numbering counter (no branch_id).
        'invoice_number_sequences',
        // Phase 18B — merchant-wide receipt numbering; merchant-owned finance controls
        // (financial_period_locks + finance_exports carry an OPTIONAL nullable branch_id
        // scope, so they are BelongsToMerchant tenant-owned, not branch-owned).
        'receipt_number_sequences',
        'financial_period_locks',
        'finance_exports',
        // Phase 20B — merchant-owned subscription lifecycle + billing (no branch_id;
        // subscriptions/billing are merchant-level). merchants.billing_status is the
        // request-authorization authority projected from merchant_subscriptions (§22).
        'merchant_subscriptions',
        'scheduled_plan_changes',
        'subscription_invoices',
        'subscription_invoice_items',
        'billing_escalation_events',
        // Phase 20E — merchant-owned percentage platform-fee ledger, adjustments, and disputes
        // (merchant_id required; branch_id OPTIONAL nullable, like financial_period_locks →
        // BelongsToMerchant tenant-owned, not branch-owned). platform_fee_configurations is
        // platform-scoped (EXEMPT).
        'platform_fee_ledger_entries',
        'platform_fee_adjustments',
        'platform_fee_disputes',
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
        // Phase 20A — platform-scoped billing configuration (Plan §13.9/§13.10/§47).
        // Super-Admin governed; no merchant/branch scope exists for platform config.
        'platform_billing_settings' => 'platform-scoped billing configuration (effective-dated; no merchant scope)',
        'subscription_plans' => 'platform-global plan catalogue (non-price metadata; no merchant scope)',
        'subscription_plan_prices' => 'platform-global sole plan-price source (ADR-011; no merchant scope)',
        'plan_entitlements' => 'platform-global per-plan entitlements (§20 substrate; no merchant scope)',
        'preferred_personnel_fee_rules' => 'platform-governed preferred-personnel fee rules (§13.10; no merchant scope)',
        // Phase 20C — platform-governed promotions & free-period offers (Plan §53).
        // Super-Admin governed configuration; a target row may point at a merchant, but the
        // offer itself is platform config and carries no merchant ownership.
        'promotional_discounts' => 'platform-scoped promotional discount configuration (§53; no merchant scope)',
        'promotional_discount_targets' => 'platform-scoped promotion target rows (a target may reference a merchant; the offer is platform config; no merchant ownership)',
        'free_period_offers' => 'platform-scoped free-period (trial-length) offer configuration (§53; no merchant scope)',
        'free_period_offer_targets' => 'platform-scoped free-period target rows (a target may reference a merchant; the offer is platform config; no merchant ownership)',
        // Phase 20E — platform-scoped percentage platform-fee configuration (Plan §13.10/§51).
        // Super-Admin governed; effective-dated; no merchant/branch scope exists for platform config.
        'platform_fee_configurations' => 'platform-scoped percentage platform-fee configuration (§13.10; effective-dated; no merchant scope)',
        // Cross-cutting infrastructure with nullable/forensic merchant scope.
        'audit_logs' => 'cross-cutting: per-merchant AND platform chain (merchant_id nullable by design, R2)',
        'idempotency_keys' => 'cross-cutting: platform/webhook scopes have null merchant/branch forensic columns (R4)',
        'uploaded_files' => 'cross-cutting: nullable merchant/branch/owner scope (platform-generated files may have no merchant); isolation enforced by FileAccessService + scoped route binding (10F)',
        'file_scan_events' => 'inherits scope via uploaded_file_id; never directly route-bound (10F)',
        // Phase 21R-A — Citrus R&E integration evidence (Plan §13.17, §58A; ADR-013).
        // Written from platform-side integration code; NO merchant-facing route exists for any of
        // the three, so there is nothing for a merchant-scoped query to isolate.
        'referral_snapshots' => 'integration evidence keyed 1:1 to a merchant, but written INSIDE the public unauthenticated self-registration transaction where no TenantContext can exist, and read only by platform-side R&E jobs; merchant_id is NOT NULL + unique + indexed and asserted directly by Phase21RASchemaTest; no merchant-facing route or Resource exposes it (21R-A)',
        're_outbound_events' => 'cross-cutting outbox: merchant_id is nullable by design (§13.17 reserves null for product-level events, none at launch — asserted in tests); platform-side emission/delivery only, never route-bound (21R-A)',
        're_event_deliveries' => 'inherits scope via re_outbound_event_id; append-only delivery attempts; never route-bound (21R-A)',
        // Phase 21S — provider attempt history for one SMS recipient (Plan §13.13, §24.5).
        'sms_delivery_attempts' => 'inherits scope via recipient_id (personnel_sms_recipients is branch-owned with composite consistency FKs); append-only provider attempt evidence; no API surface exposes an attempt, so it is never route-bound and there is nothing for a merchant-scoped query to isolate (21S)',
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
        // Phase 15B — branch-owned personnel availability (BelongsToMerchant + BelongsToBranch).
        PersonnelAvailability::class => 'branch',
        // Phase 16A — branch-owned appointments (BelongsToMerchant + BelongsToBranch).
        Appointment::class => 'branch',
        // Phase 16B — branch-owned walk-ins & queue entries (BelongsToMerchant + BelongsToBranch).
        WalkIn::class => 'branch',
        QueueEntry::class => 'branch',
        // Phase 16C — branch-owned service sessions (BelongsToMerchant + BelongsToBranch).
        ServiceSession::class => 'branch',
        // Phase 17 — branch-owned invoices + items; merchant-wide numbering counter.
        Invoice::class => 'branch',
        InvoiceItem::class => 'branch',
        InvoiceNumberSequence::class => 'tenant',
        // Phase 18A — branch-owned payment recording groups + components + evidence.
        PaymentRecordingGroup::class => 'branch',
        PaymentRecord::class => 'branch',
        PaymentAllocation::class => 'branch',
        PaymentReferenceCheck::class => 'branch',
        // Phase 18B — branch-owned validation/receipt/refund/dispute/cash-up/commission.
        PaymentValidationEvent::class => 'branch',
        Receipt::class => 'branch',
        Refund::class => 'branch',
        FinanceDispute::class => 'branch',
        CashUpLine::class => 'branch',
        CommissionHandoffEvent::class => 'branch',
        // Phase 18B — merchant-owned numbering + finance controls (nullable branch scope).
        ReceiptNumberSequence::class => 'tenant',
        FinancialPeriodLock::class => 'tenant',
        FinanceExport::class => 'tenant',
        // Phase 19 — branch-owned audit flagged-event review record.
        AuditFlaggedEvent::class => 'branch',
        // Phase 19 — branch-owned Audit export request (ADR-010).
        AuditExport::class => 'branch',
        // Phase 20B — merchant-owned subscription lifecycle + billing (BelongsToMerchant only).
        MerchantSubscription::class => 'tenant',
        ScheduledPlanChange::class => 'tenant',
        SubscriptionInvoice::class => 'tenant',
        SubscriptionInvoiceItem::class => 'tenant',
        BillingEscalationEvent::class => 'tenant',
        // Phase 20E — merchant-owned percentage platform-fee ledger/adjustments/disputes
        // (BelongsToMerchant only; branch_id is an optional nullable scope).
        PlatformFeeLedgerEntry::class => 'tenant',
        PlatformFeeAdjustment::class => 'tenant',
        PlatformFeeDispute::class => 'tenant',
        // Phase 20F — branch-owned HR compensation configuration (BelongsToMerchant +
        // BelongsToBranch). The plan's subject is staff_profile_id; the commission rule is a
        // sibling reference; history is append-only.
        CommissionRule::class => 'branch',
        PersonnelCompensationPlan::class => 'branch',
        CompensationPlanHistory::class => 'branch',
        // Phase 20G — branch-owned ledgers/adjustments/membership (BelongsToMerchant + BelongsToBranch).
        CommissionLedgerEntry::class => 'branch',
        SalaryLedgerEntry::class => 'branch',
        CompensationAdjustment::class => 'branch',
        CommissionRuleService::class => 'branch',
        // Phase 20H — branch-owned payout runs/items + personnel own-scope earnings queries
        // (BelongsToMerchant + BelongsToBranch).
        PersonnelPayoutRun::class => 'branch',
        PersonnelPayoutItem::class => 'branch',
        EarningsQuery::class => 'branch',
        // Phase 21S — branch-owned Personnel SMS campaign, its immutable recipient snapshots and
        // the billable-SMS queue (BelongsToMerchant + BelongsToBranch). SmsDeliveryAttempt is
        // deliberately absent: it inherits scope via recipient_id and is EXEMPT above.
        PersonnelSmsCampaign::class => 'branch',
        PersonnelSmsRecipient::class => 'branch',
        SmsBillingEntry::class => 'branch',
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
        // Phase 15B — branch consistency via composite FK to merchant_branches.
        'personnel_availability' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 16A — branch consistency via composite FK to merchant_branches.
        'appointments' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 16B — branch consistency via composite FK to merchant_branches.
        'walk_ins' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'queue_entries' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 16C — branch consistency via composite FK to merchant_branches.
        'service_sessions' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 17 — branch consistency via composite FK to merchant_branches.
        'invoices' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'invoice_items' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 18A — branch consistency via composite FK to merchant_branches.
        'payment_recording_groups' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'payment_records' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'payment_allocations' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'payment_reference_checks' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 18B — branch consistency via composite FK to merchant_branches.
        'payment_validation_events' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'receipts' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'refunds' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'finance_disputes' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'cash_up_lines' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'commission_handoff_events' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'audit_flagged_events' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'audit_exports' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 20F — branch consistency via composite FK to merchant_branches. Each table
        // additionally carries composite FKs to its own parents (staff_profiles for the
        // subject, commission_rules for the sibling reference, personnel_compensation_plans
        // for history) so no reference can ever cross a merchant boundary.
        'commission_rules' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'personnel_compensation_plans' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'compensation_plan_history' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 20G — branch consistency via composite FK to merchant_branches. Each ledger/adjustment
        // additionally carries composite FKs to its own parents (staff_profiles, personnel_compensation_plans,
        // commission_rules, invoices, invoice_items, service_sessions, payment_records, payment_validation_events,
        // self) so no reference can cross a merchant boundary.
        'commission_ledger' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'salary_ledger' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'compensation_adjustments' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'commission_rule_services' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 20H — branch consistency via composite FK to merchant_branches. Payout items also
        // carry composite FKs to their run + staff_profile; earnings queries to their staff_profile;
        // and the run/staff refs so no reference can cross a merchant boundary.
        'personnel_payout_runs' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'personnel_payout_items' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'earnings_queries' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        // Phase 21S — branch consistency via composite FK to merchant_branches. The campaign also
        // carries a composite FK to staff_profiles (the own-scope subject); the recipient snapshot
        // carries composite FKs to its campaign, its client and its evidencing service session; the
        // billing entry carries one to its campaign — so no SMS reference can cross a merchant
        // boundary at the database level.
        'personnel_sms_campaigns' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'personnel_sms_recipients' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
        'sms_billing_entries' => ['parent' => 'merchant_branches', 'fk' => 'branch_id'],
    ];
}
