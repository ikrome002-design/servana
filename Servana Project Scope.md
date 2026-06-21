# Servana by Citrus — Upgraded Platform Project Scope

**Product:** Servana by Citrus
**Owner / Operator:** Citrus Labs Limited
**Platform Type:** Multi-tenant SaaS web platform
**Primary Market:** Service-based SMEs in Kenya and the broader African market — barbershops, salons, massage parlours, spas, grooming studios, beauty parlours, nail bars, wellness studios, and similar appointment/walk-in service businesses.
**Billing Architecture:** Subscription-first, configurable, M-Pesa-integrated
**Currency:** KES (Kenyan Shilling) by default, configurable
**Scope Version:** Upgraded scope (subscription-first generation), superseding the previous platform-fee-ledger-first scope.

This upgraded scope supersedes the previous **Servana Project Scope** while preserving every operational principle, control, and safeguard that remains valid. It folds in the full set of new and upgraded features: a configurable subscription-first billing engine, four entitlement-based subscription plans, promotional and free-period engines, separated billing/operational lifecycles, a shared overdue-escalation engine, automated M-Pesa subscription payment and account recovery, merchant self-registration as the only creation path, a complete personnel compensation model (commission-only, salary-plus-commission, salary-only) with payout runs and earnings queries, strengthened role separation, standardized deactivation/deletion semantics, launch-active preferred-personnel fee rules, personnel own-scope enforcement with in-platform bulk SMS, branch-scoped audit with field-level masking, and a modern, best-practice user experience for every account type.

---

# 0. Document Control and Upgrade Summary

## 0.1 What This Upgrade Changes

This scope is an evolution of the previous Servana scope. The headline changes are:

1. **Billing moves from ledger-first to subscription-first, with percentage billing as a launch-supported mode.** Validated merchant-client invoices no longer accrue an active Citrus platform fee by default. Merchants now pay Servana through configurable subscription plans. Percentage-on-invoice billing and fixed-plus-percentage billing are **launch-supported, active, Super-Administrator-selectable billing modes** — fully specified and implementable at launch, never future-only or placeholder. Fixed-amount may remain the default launch configuration, but the Super Administrator shall be able to activate any of the three modes without a code deployment, and no value is ever hardcoded (see §6 and §6A).
2. **All platform fees and plan prices are configurable**, set by the Super Administrator and stored in `platform_billing_settings`. Nothing about price, fee level, grace window, or trial length is hardcoded.
3. **Four entitlement-based subscription plans** are introduced — Starter, Growth, Pro Branch, and Multi-Branch — each enforced server-side through entitlements rather than hidden restrictions.
4. **Billing status and operational status are permanently separated** into two distinct fields, so suspension and recovery logic never breaks.
5. **Automated M-Pesa subscription payment and account recovery** replaces any notion of a Super Administrator manually recording offline subscription payments.
6. **Merchant Administrator self-registration is the only merchant creation path.** The Super Administrator governs merchants only after they exist.
7. **A full personnel compensation model** is introduced (commission-only, salary-plus-commission, salary-only) with salary and commission ledgers, payout runs, earnings statements, and earnings queries.
8. **Role boundaries are corrected and tightened.** The Merchant Administrator is the account owner, not an operational superuser. Service catalogue belongs to the Branch Manager; staff, eligibility, and compensation belong to HR; default customer-payment recording and invoice creation belong to Front Office; payment validation, cash-up approval, refunds, disputes, and period locks belong to Finance.
9. **"Deactivate" and "delete" are standardized** as the same lifecycle instruction (soft removal with historical preservation), unless the scope explicitly says "hard delete from database."
10. **Preferred Personnel Fee Rules are launch-active** Super Administrator settings (fixed amount and percentage models).
11. **Personnel own-scope is hardened** across every personnel surface, with in-platform bulk SMS to personally served clients and permanent removal of any contact-export capability.
12. **Audit gains branch-only scope, field-level masking, salary and commission coverage**, and a narrow flagged-event metadata exception to its otherwise read-only posture.
13. **Modern, best-practice UX** is mandated for every account type, including a dedicated landing page and a guided get-started page per user.

## 0.2 Settled Policy Decisions (Implement as Rules — No Placeholders)

These decisions are final for this launch generation. They must be implemented as settled rules, not reopened or left as alternatives.

1. **Trial-to-paid churn.** When the configured free period ends and the merchant has not subscribed, the merchant enters `read_only_grace` for the configured window (default 14 days). During grace the merchant can view clients, past invoices, receipts, and reports, and can pay their subscription, but cannot create walk-ins, invoices, payments, or any new operational records. After the grace window with no subscription, the merchant becomes `suspended_billing`. Merchant billing statuses include `trialing → read_only_grace → suspended_billing`. The §16 / §6 suspended-merchant read-only allowlist is reused for `read_only_grace`.
2. **Overdue subscription invoices reuse the shared escalation ladder.** Subscription invoices and any optional platform-fee or add-on invoices share one escalation engine (`grace_days → reminders → suspension_after_days`). Overdue reminders fire at day 3, day 7, and day 14. No separate dunning path is built.
3. **Plan changes are not prorated.** Plan upgrades, downgrades, billing-period changes, and per-extra-branch charges take effect at the start of the next billing cycle. The current cycle's charge is unchanged. This is a deliberate launch decision with a documented v2 extension point. A test asserts that a mid-cycle plan change does not alter the current cycle's issued invoice.
4. **All fee levels, plan prices, grace-day counts, trial length, and the read-only window are configurable** settings in `platform_billing_settings`, never hardcoded.

## 0.3 Terminology Note: Deactivate = Delete

Throughout this scope, "deactivate user," "delete user," and "remove user" denote the **same functional instruction** — lifecycle disabling and soft removal from active access — unless the text explicitly says "hard delete from database." Users referenced by operational, financial, audit, compensation, or billing records are never hard-deleted. Historical records, audit history, invoices, receipts, service sessions, payments, commissions, salary records, and payout records are always preserved. See §14 for the full rule.

---

# 1. Platform Purpose

Servana by Citrus exists to help service-based SMEs digitize, control, audit, and grow their day-to-day operations from one secure, modern web dashboard, while paying for the platform through a clear, configurable subscription rather than opaque per-transaction fees.

The SaaS web app enables merchants to manage:

1. Merchant self-registration and guided onboarding.
2. Subscription plan selection and self-service billing.
3. Merchant branches and branch operations.
4. Staff and personnel access through role-based, branch-scoped control.
5. Front-office client-facing operations.
6. Client records with branch-level duplicate prevention and contact protection.
7. Walk-ins and walk-in queues.
8. Appointments and appointment lifecycles.
9. Queue management with next-available and preferred-personnel assignment.
10. Service delivery (service session) tracking.
11. Client choice of next-available or preferred personnel at a configurable extra fee.
12. Invoice generation with the merchant logo.
13. Offline merchant-client payment recording and validation.
14. Automatic receipt generation after validation.
15. Personnel compensation — commission, salary, or salary-plus-commission — with personal earnings visibility.
16. Staff lifecycle management, eligibility, availability, and compensation setup.
17. Cash-up accountability and reconciliation.
18. Tamper-evident, branch-scoped audit of operational and financial activity.
19. Subscription billing, M-Pesa subscription payment, and automated account recovery.
20. Configurable plan entitlements, promotional billing controls, and free-period offers.
21. Real-time merchant financial and operational visibility.
22. Strict tenant, branch, and own-scope data protection.

Servana is not merely a booking app, a POS, a payroll system, an invoicing tool, or a payment processor. It is an **operating-control platform** for service businesses, with subscription-first platform billing, three launch-active configurable fee modes (fixed, percentage, and fixed-plus-percentage), and strong separation between merchant ownership, branch management, HR, finance, front office, personnel, audit, and Super Administrator governance.

**Core positioning:**

> **Servana by Citrus is a multi-tenant, subscription-first service-operations SaaS platform that lets service-based SMEs run clients, staff, services, queues, invoices, payments, receipts, compensation, cash-up, and audit from one secure, modern web dashboard — and recover their own account billing through automated M-Pesa payment without contacting support.**

---

# 2. Core Product Principles

## 2.1 Multi-Tenant SaaS Principle

Each merchant operates as an isolated tenant. A merchant's data must never be accessible, inferable, editable, exportable, or enumerable by another merchant. Tenant isolation is enforced server-side on every tenant-owned resource through tenant scopes, policies, opaque identifiers (UUID/ULID), and access tests — never through frontend hiding alone.

## 2.2 Offline Merchant-Client Payment Principle (With a Critical Billing Exception)

Merchant-client **service** payments remain offline / off-platform at launch. Servana records the payment method, amount, reference, status, and validation details for client-to-merchant service payments, but does not process those payments inside the platform.

Supported offline merchant-client payment methods:

* Cash
* M-Pesa
* Bank transfer
* Card terminal
* Voucher
* Split payment
* Other merchant-defined offline method

**Critical exception — merchant subscription payments to Servana are not offline.** Merchant subscription invoices owed to Servana / Citrus are paid through integrated **M-Pesa** and are automatically validated and reconciled by the platform. This exception applies only to the merchant's subscription billing relationship with Citrus, never to merchant-client service payments. See §10 for the full M-Pesa subscription payment and recovery design.

## 2.3 Subscription-First Billing Principle

Servana's launch billing model is subscription-first. Merchants pay for the platform through configurable subscription plans and billing periods. The active billing mode is a Super-Administrator-controlled setting, never hardcoded, and can be:

```text
fixed_amount
percentage_on_merchant_client_invoice
fixed_amount_plus_percentage_on_merchant_client_invoice
```

The percentage and fixed-plus-percentage modes are launch-active and Super-Administrator-selectable, but they are not forced into any merchant's launch onboarding. The service-fee-tier structure (`customer_centric`, `shared`, `business_centric`) applies only when a percentage component is active. See §6, §6A, and §7.

## 2.4 Separation of Billing Status and Operational Status Principle

The platform keeps merchant **operational status** and merchant **billing status** as two separate fields, and never collapses them into one column.

```text
merchants.status          → operational / business lifecycle
merchants.billing_status  → subscription, trial, grace, overdue, billing suspension lifecycle
```

A merchant may be operationally active while billing-trialing; operationally active while in read-only billing grace; billing-suspended while still able to reach subscription-payment recovery; or manually suspended for fraud, security, legal, or compliance reasons even while billing is fully paid. This separation prevents suspension and recovery logic from breaking. See §9.

## 2.5 Merchant Access Principle (Magic Link)

All merchant account users log in by **Magic Link sent to email**, after the system verifies that the email is active under that user's respective Merchant Administrator account. A user must not log in merely because their email exists. Every login verifies:

1. The user email exists.
2. The user belongs to the correct merchant tenant.
3. The user account is active.
4. The user's role is active.
5. The user has not been suspended.
6. The user is assigned to the correct branch, where branch access applies.
7. The Magic Link is valid, unused, and unexpired.

## 2.6 Server-Side Enforcement Principle

Every authorization decision — role, permission, plan entitlement, billing status, tenant isolation, branch scope, own-scope, period lock, and field-level masking — is enforced server-side. Frontend hiding is permitted only for user experience and is never the sole enforcement layer. See §19 for the required enforcement layers.

## 2.7 Audit-Ready Principle

Sensitive operational, financial, billing, compensation, and governance actions are recorded in append-only, tamper-evident audit logs with before-and-after values for state changes, severity, event status, and chained-hash integrity. Audit reads are branch-scoped and field-masked by default. See §4.9 and §16.

## 2.8 Merchant Creation Principle

A new merchant tenant is created only through Merchant Administrator self-registration. No Super Administrator, staff user, or operational role can create a merchant tenant or the first Merchant Administrator. Super Administrator governance begins only after the merchant exists. See §11.

## 2.9 Personnel Own-Scope Principle

Merchant Personnel users see only their own records — their own queue entries, appointments, sessions, commissions, salary, payouts, served clients, client messages, and earnings statements — enforced server-side across every personnel surface. See §4.7 and §12.

## 2.10 Modern Experience Principle

Every account type has its own landing page and its own guided get-started page. The interface is clean, fast, responsive, accessible, real-time where it matters, and workflow-driven, following modern best-practice UX methodology. See §3 and §22.

---
# 3. Per-User Landing and Get-Started Experience

Every Servana account user — Super Administrator, Merchant Administrator, Branch Manager, HR, Finance, Front Office, Personnel, and Audit — has **two dedicated entry surfaces**: a role-specific **landing page** (the live operational home for that role) and a role-specific **get-started page** (a guided, dismissible onboarding companion). This is a launch requirement, not a future enhancement.

## 3.1 Landing Page (Role Home)

The landing page is the first screen a user sees after Magic Link login. It is the live, real-time home for the role and surfaces exactly what that role needs to act on now.

Landing-page principles:

* **Role-true.** It shows only what the role is permitted to see and do. No role sees another role's surface.
* **Action-first.** The most common next actions for the role are immediately reachable (e.g., Front Office sees "Start walk-in," Finance sees "Pending validations").
* **Real-time.** Counts, queues, statuses, and billing notices update in near-real time without a manual refresh.
* **Status-aware.** Billing notices (trial countdown, read-only grace banner, suspension recovery, pricing changes) render contextually for users who are permitted to see them.
* **Empty-state-friendly.** When there is nothing to act on, the landing page explains what to do next rather than showing a blank screen.

For the Merchant Administrator specifically, any pricing change made by the Super Administrator appears on the Merchant Administrator landing page, billing dashboard, subscription screen, and plan-management screen in real time or near-real time (see §6.5).

## 3.2 Get-Started Page (Guided Onboarding)

The get-started page is a guided, progress-tracked companion that helps a user complete the setup and first actions relevant to their role. It is shown automatically after first login and remains accessible from the account menu until dismissed.

Get-started principles:

* **Checklist-driven.** Each role has a short, ordered checklist with clear completion states (e.g., a check icon when a step is done).
* **Deep-linked.** Each step links directly to the screen where it is completed.
* **Non-blocking after setup.** Mandatory first-time setup gates dashboard access only where the scope explicitly requires it (Merchant Administrator first-time setup, §11.6). Beyond required setup, the get-started page guides without blocking.
* **Resumable.** Progress persists across sessions so a user can complete onboarding over time.
* **Dismissible.** Once a user is comfortable, they can hide the companion; it can be reopened from the account menu.

Indicative get-started checklists by role:

| Role | Get-started checklist (indicative) |
| ---- | ---------------------------------- |
| Super Administrator | Configure billing mode, configure plans and entitlements, configure free-period and grace settings, configure preferred-personnel fee rule, configure M-Pesa integration, review registration monitoring. |
| Merchant Administrator | Verify email, choose subscription plan, confirm merchant profile, create first branch, invite Branch Manager and HR, confirm billing/M-Pesa phone, finish setup. |
| Branch Manager | Confirm branch profile, set operating hours and calendar, build the service catalogue, set service pricing and durations, open the branch day. |
| HR | Invite staff, set service eligibility, set availability, configure personnel compensation models, review missing-compensation warnings. |
| Finance | Review pending validations, learn the validation workflow, review cash-up submissions, review payout runs, review period-lock controls. |
| Front Office | Register a client, start a walk-in, assign personnel, create an invoice, record a payment, confirm receipt issuance. |
| Personnel | Review My Earnings, review compensation terms, acknowledge terms, view served clients, send a permitted SMS. |
| Audit | Review flagged events, learn branch-scoped filtering, review masked client context, review export permissions. |

## 3.3 Consistency and Theming

Landing and get-started pages share the platform's design system: consistent left-side navigation, consistent header, consistent typography, KES currency formatting by default, and Font-Awesome-style iconography (icons, not emojis) for a professional appearance. Each role's surface is themed identically in structure so that staff who hold multiple roles across their career experience a coherent product.

---

# 4. Platform Users and Account Types

Servana defines eight account types plus the client record. Each account type has a clearly bounded purpose, an explicit set of functionalities, explicit exclusions, and server-enforced scope. The role boundaries below incorporate all corrections from this upgrade: the Merchant Administrator is the account owner and not an operational superuser; the Branch Manager owns the service catalogue; HR owns staff, eligibility, and compensation; Front Office owns default customer-payment recording, invoice creation, client creation, and queue/appointment transfer; Finance owns validation, cash-up approval, refunds, disputes, and period locks; Personnel are strictly own-scope; and Audit is branch-scoped, read-only (except flagged-event metadata), and field-masked by default.

## 4.1 Super Administrator Account

### Purpose

The Super Administrator is the Citrus Labs Limited platform-owner role. It governs the SaaS ecosystem across all merchants at the platform level. The Super Administrator does **not** create merchant business accounts and does **not** create the first Merchant Administrator. Governance begins only after a merchant exists.

### Core Functionalities

* Configure platform-wide settings.
* Configure the active billing mode (`fixed_amount`, `percentage_on_merchant_client_invoice`, `fixed_amount_plus_percentage_on_merchant_client_invoice`).
* Configure subscription plans, plan entitlements, plan prices, branch limits, staff limits, and extra-branch charges.
* Configure billing periods (weekly, bi-weekly, monthly, quarterly, annual) and the default billing period.
* Configure the percentage fee rate and the fixed fee amount where those modes are active.
* Configure the free-period (trial) length, read-only grace length, overdue reminder cadence, and suspension-after window.
* Configure promotional discounts (percentage or fixed amount) and their scope and duration.
* Configure free-period offers and their scope.
* Configure the launch-active Preferred Personnel Fee Rule (fixed amount or percentage).
* Configure SMS billing settings.
* Configure and monitor M-Pesa billing integration settings.
* View all merchants that have self-registered.
* View platform-wide reports and dashboards.
* View subscription status, subscription invoices, and M-Pesa payment attempts.
* View and resolve M-Pesa reconciliation exceptions.
* View platform-level audit logs and merchant governance actions.
* Monitor suspicious registrations, duplicate-business warnings, and abusive trial usage.
* Suspend, reactivate, and deactivate existing merchants where allowed.
* Manage internal Citrus Labs Limited platform roles.
* Control platform-level feature flags.
* Govern merchant suspension, deactivation, billing enforcement, abuse response, and platform-policy enforcement.
* Add governance notes to merchant records.

### Explicit Exclusions

The Super Administrator must not:

* Create merchant tenants on behalf of merchants.
* Create the first Merchant Administrator.
* Assign themselves a merchant role.
* Be inserted into `merchant_users`, `branch_user_assignments`, or `staff_profiles`.
* Impersonate a Merchant Administrator at launch.
* Complete a merchant's first-time setup.
* Create a merchant branch on behalf of a merchant.
* Configure branch services or pricing.
* Add operational staff directly.
* Run merchant operations (serving clients, assigning sessions, manipulating queues, editing invoices, validating merchant-client payments, or editing receipts) outside controlled governance workflows.
* **Normally record merchant subscription payments.** Merchants pay via M-Pesa and the platform validates automatically. The Super Administrator only monitors exceptions, failed reconciliations, fraud flags, and billing settings (see §10).
* Require merchant KYC/compliance submission or activation documents as part of Merchant Administrator self-registration.

### Governance Note

Where this scope refers to platform compliance, it means automated platform-policy enforcement, billing compliance, abuse controls, and suspension rules. It does not mean merchant KYC/compliance submission or Super Administrator approval of merchant registration.

### Authentication Rule

Super Administrator access uses the platform's secure authentication. Optional MFA is recommended for this high-privilege role.

---

## 4.2 Merchant Administrator Account / Merchant Owner Account

### Purpose

The Merchant Administrator and Merchant Owner are the same account type; they are not split. The Merchant Administrator is the registering business owner, manager, or authorized operator. They self-register the business account, after which the system creates the merchant tenant and assigns the registering user as **Merchant Owner / Merchant Administrator**.

The Merchant Administrator is the **account owner and top-level account administrator — not an operational superuser.** They manage merchant-level ownership, subscription, branches, account lifecycle, and merchant-wide oversight, but do not automatically receive authority to perform every operational function. Restricted operational functions remain assigned to their respective roles and are enforced through permissions and policies, not only UI hiding.

### Self-Registration Rule

```text
Merchant Administrator self-registers the business account.
The system creates the merchant tenant.
The system assigns the registering user as Merchant Owner / Merchant Administrator.
Super Administrator governs suspension, billing, plan configuration, abuse controls, and platform-level oversight.
Merchant Administrator dashboard access is not held pending Super Administrator manual activation.
```

### Subscription Plan Selection (Replaces Service-Fee-Tier-First Onboarding)

During the account creation process or immediately after account creation — before operational dashboard access — the Merchant Administrator selects a subscription plan:

```text
Starter
Growth
Pro Branch
Multi-Branch
```

The plan selected by one Merchant Administrator applies only to that Merchant Administrator's merchant tenant and all branches inside that tenant, and never affects any other merchant tenant or branch. The Merchant Administrator may request or schedule a plan change while logged in, subject to entitlement validation, billing-cycle rules, the no-proration rule, and payment-status rules (see §7).

The service-fee-tier structure is **not** a required launch onboarding field. It applies only when a percentage billing component is active (see §6.3).

### First-Time Setup Before Dashboard Access

After account creation, the Merchant Administrator completes first-time setup before accessing the operational dashboard:

1. Verify email (Magic Link).
2. Choose subscription plan.
3. Confirm the merchant profile.
4. Create at least one Merchant Branch and complete the branch profile.
5. Invite the initial Branch Manager and HR users where needed; select each invited user's branch (auto-selected where only one branch exists).
6. Confirm business contact details and billing/M-Pesa phone.
7. Finish setup; redirect to the Merchant Administrator dashboard.

After completion: `merchants.status = active`, `setup_completed_at = now()`. Billing status remains `trialing` until the trial expires or a subscription payment activates a paid subscription.

### Core Functionalities

* Self-register the merchant business account.
* Complete and manage the merchant profile (including merchant logo for invoices and receipts).
* Select and change/schedule the subscription plan.
* View pricing changes in real time on the landing page, billing dashboard, subscription screen, and plan-management screen.
* Manage subscription and billing recovery; pay subscription invoices via M-Pesa.
* View subscription invoices, payment attempts, reconciliation details, and download invoices.
* Create Merchant Branch accounts and complete branch profiles.
* Add only the Branch Manager and HR user email addresses during Merchant Administrator-controlled staff setup.
* View all branches and all merchant staff under each branch.
* View, activate, suspend, and deactivate merchant staff account users within the tenant, subject to historical-record protection. **(No platform-fee-debt precondition applies; see §14.)**
* View merchant-level revenue reports in real time across all branches.
* View branch revenue performance for today, this week, last month, and the last three months.
* View each branch's services and service pricing (read).
* View each service's revenue performance within each branch.
* View staff performance cumulatively per branch and per individual staff.
* **View personnel compensation according to each personnel member's configured model** — salary-only, commission-only, or salary-plus-commission — including salary liabilities, commission liabilities, payout status, payout history, compensation approvals, and compensation exceptions (tenant-scoped and branch-aware). See §4.2.1.
* Receive the daily branch day-close report by email in PDF.
* Receive the daily branch cash-up and reconciliation report by email in PDF.
* Lock financial periods where permitted (subject to the central PeriodLockService).
* Deactivate inactive or abusive merchant users while preserving historical records and audit logs.

### 4.2.1 Merchant Administrator Compensation Visibility

Because personnel may be paid by salary, commission, or salary-plus-commission, the Merchant Administrator's visibility covers personnel compensation according to each personnel member's configured compensation model — not commissions only. The Merchant Administrator may view salary-only, commission-only, and salary-plus-commission summaries; salary and commission liabilities; payout status and history; and compensation approvals and exceptions. This visibility is tenant-scoped and branch-aware. The Merchant Administrator must not directly edit compensation unless explicitly granted by permission and workflow (see §12).

### Explicit Exclusions

The Merchant Administrator must not:

* Create or approve account users apart from adding Branch Manager and HR email addresses in the Merchant Administrator staff-setup flow.
* Link Merchant Personnel to any account.
* Configure services or service pricing at the Merchant Administrator level (Branch Manager owns this).
* Configure branch personnel assignments or personnel eligibility (HR owns this).
* Configure personnel compensation directly (HR owns setup; Admin may approve sensitive changes where granted).
* Validate merchant-client payments (Finance owns this).
* Create merchant-client invoices (Front Office owns this).
* Assign personnel to branches (HR owns this within branch scope).
* Act as an operational superuser by default for any operational function reserved to another role.

### Inactivity and Dormancy Rule

Long-term dormant Merchant Administrator accounts are deactivated according to the platform retention policy. Dormancy is assessed on the absence of an active subscription combined with no login/operational activity over the configured window, not on any legacy platform-fee-payment trigger. Subscription non-payment is handled through the trial → read-only grace → billing suspension lifecycle (§9). Historical records, financial records, receipts, invoices, audit logs, and legally necessary accounting records are preserved or archived per the retention policy and never silently destroyed. Merchant Administrator lifecycle actions must not be blocked by any non-existent legacy platform-fee debt rule.

### Authentication Rule

Merchant Administrator logs in via Magic Link sent to email.

---
## 4.3 Merchant Branch Account (Branch Manager)

### Purpose

A Merchant Branch represents a physical or operational business location created by the Merchant Administrator. The Merchant Branch account user (Branch Manager) manages only the specific branch they have been added into. They do not manage other branches and do not create branches.

Examples: Westlands branch, Kilimani salon branch, CBD barbershop branch, spa branch inside a hotel, massage parlour branch.

### Service-Catalogue Ownership

The Branch Manager **owns the service catalogue** for the branch. HR owns which personnel may perform those services. The division is exact:

| Action | Owner |
| ------ | ----- |
| Create a service (e.g., "Haircut") | Branch Manager |
| Set service price | Branch Manager |
| Set service duration | Branch Manager |
| Manage service availability at branch level | Branch Manager |
| Manage the branch operating calendar | Branch Manager |
| Assign a personnel member as eligible to perform a service | HR |
| Remove a personnel member's eligibility for a service | HR |

### Core Functionalities

* View the branch dashboard (landing page) and branch get-started page.
* Manage the specific branch profile.
* Manage branch operating hours and the branch operating calendar.
* **Create, edit, price, set durations for, and archive services within the branch (service catalogue ownership).**
* Configure branch service availability.
* View staff activity and per-individual staff performance for the specific branch only.
* View the branch queue and branch appointments (read where permitted).
* View branch reports, revenue, invoices, receipts, and payment records.
* View branch-level audit logs (read).
* Run the branch day opening and day closing workflow.
* **Submit branch cash-up and reconciliation records** (submission only).
* **Pay the merchant's outstanding subscription invoice from branch context** via M-Pesa, to keep branch operations active (see §10).

### Read-Only HR-Controlled Personnel Visibility

The Branch Manager has real-time read-only visibility into HR-controlled features for the branch only: personnel-to-branch assignment state, availability schedules, temporary unavailability, active/inactive-for-queue states, skill/service eligibility, and reassignment states. These are visible, not editable, by the Branch Manager.

### Explicit Exclusions

The Branch Manager must not:

* Create Merchant Branch accounts or manage other branches.
* Assign merchant users to branches or handle branch-scoped access permissions.
* Perform HR personnel-assignment or eligibility duties.
* Create, activate, suspend, or deactivate merchant staff users.
* Override HR-controlled personnel service eligibility.
* **Create merchant-client invoices** (invoice creation is a Front Office function; the Branch Manager may view branch invoices where permitted).
* **Validate, reject, or correct payments** (Finance only).
* **Approve or reject cash-up** (Finance only; the Branch Manager only submits).
* **Create refunds or manage disputes** (Finance only).
* **Reverse or reissue receipts** (Finance only, or a permission-gated role).
* **Transfer, reassign, or move queue entries or appointments between personnel** (operational transfer is a Front Office function; see §4.3.1).

### 4.3.1 Branch Route Scope Is Not Permission

The branch route group may contain endpoints for payments, refunds, disputes, receipt reversal, cash-up submission, and cash-up approval. **A branch route grants branch scope, not role permission.** Every action still requires policy/permission enforcement. The Branch Manager may only access branch-management functions they are permitted to use:

| Action | Branch Manager |
| ------ | -------------- |
| Manage branch profile / services / day operations | Yes |
| Submit cash-up | Yes |
| Approve / reject cash-up | No — Finance only |
| Validate payments | No — Finance only |
| Refunds / disputes | No — Finance only |
| Reverse / reissue receipts | No — Finance only / permission-gated |
| Create invoices | No — Front Office only |
| Transfer queue / appointments | No — Front Office only |

### Branch Status Rules

| Branch Status | Operational Effect |
| ------------- | ------------------ |
| Active | The branch can accept appointments, walk-ins, queues, invoices, payments, and service sessions. |
| Suspended | The branch cannot accept new walk-ins or appointments. Historical records remain visible. |
| Archived / Closed | The branch is no longer operational and cannot be closed or archived while live operational records exist. |

A branch can be suspended because of billing issues, abuse, platform-policy enforcement, or Merchant Administrator action.

### Minimum Branch Profile Fields

| Field | Requirement |
| ----- | ----------- |
| Branch name | Human-readable, e.g., Kilimani Branch. |
| Branch code | Unique merchant-level code, e.g., KIL-001. |
| Physical address | Required. |
| Town / city / area | Required for reporting and filtering. |
| Phone number | Required for branch-level contact. |
| Email | Optional but recommended. |
| Business category | Inherited from merchant or overridden where necessary. |
| Status | Required. |

### Branch Operating Calendar

| Feature | Requirement |
| ------- | ----------- |
| Weekly operating hours | Normal opening and closing times. |
| Public holiday exceptions | Branch can be marked closed or with modified hours. |
| Special closures | One-off closures for maintenance, events, staff shortage, etc. |
| Break periods | Optional but useful for appointment availability. |
| Same-day emergency closure | Immediately blocks new queues and appointments. |
| Closure reason | Required for temporary closure. |
| Audit trail | Every schedule change logged. |

### Branch Day Opening and Closing Workflow

```text
Open branch day
Confirm active personnel
Confirm service availability
Confirm queue is open or closed
Run walk-ins / appointments / services / invoices / payments
Close branch day
Review invoices
Review payment records
Review pending validations
Review receipts issued
Review cash / offline totals
Submit day-close record
```

Required statuses: not opened, open, paused, closed, reopened with reason. The Merchant Administrator receives this report daily by email in PDF.

### Branch Invoice and Receipt Numbering Rules

Servana uses merchant-wide uniqueness with an optional branch prefix for readability (e.g., `KIL-INV-000124`, `KIL-RCT-000124`). No duplicate invoice or receipt numbers are allowed at the database level. Voided invoices keep their number. Number generation is logged for sensitive failures.

### Branch-Level Dashboard Cards

Today's appointments, today's walk-ins, active queue, clients waiting, clients in service, completed sessions, unpaid invoices, pending payment validations, receipts issued, today's revenue, payment-method breakdown, personnel currently active, queue delays, no-shows, and cancelled sessions.

### Branch Audit Events

Branch created/edited; status changed; operating hours changed; special closure added; service enabled/disabled; personnel assigned/removed by authorized HR workflow; user branch access granted/removed by authorized HR workflow; queue opened/closed/reordered; appointment created/rescheduled/cancelled/no-showed; service session started/completed/cancelled; invoice created/voided; payment recorded/validated/rejected; receipt generated; branch day opened/closed/reopened; cash-up submitted/reviewed.

### Branch Closure and Archival Protection

A branch must not be closed or archived while live operational records exist — active queue entries, in-progress sessions, unpaid invoices, pending validations, unissued receipts for validated payments, pending appointment check-ins, an unclosed branch day, or an unresolved cash-up discrepancy.

### Implementation Note

A branch is implemented as both a branch entity in the data model and a branch access scope for merchant users. This avoids confusing a branch location with a human user while still allowing branch-specific authorization.

### Authentication Rule

Branch Manager logs in via Magic Link after their email exists as an invited/active user under the relevant tenant and assigned branch scope.

---

## 4.4 Merchant Human Resource Account (HR)

### Purpose

The HR account manages staff identity, employment status, branch-scoped assignment, role assignment, personnel service eligibility, availability, staff invitation, staff access lifecycle, and — new in this upgrade — **personnel compensation setup** (commission rules, salary terms, and compensation models). HR operates strictly within the branch in which the HR user is assigned and cannot assign or manage staff in other branches, even under the same Merchant Administrator.

### Ownership Summary

HR owns: staff profiles, employment status, service eligibility, personnel availability/unavailability, compensation setup, commission rules, salary terms, and compensation change history.

### Staff Creation Rule

HR creates staff by: adding the staff email; selecting the account type (Merchant Personnel, Front Office, Finance, or Audit); selecting the staff member's specific role within that type (e.g., Barber, Massage Therapist, Hairdresser, Stylist, Beautician, Cosmetologist); assigning service eligibility where the staff member is Personnel; triggering the invite/activation email; and showing pending activation until the account is activated. The staff member receives a welcome email explaining Magic Link login.

### Core Functionalities

* Add staff users within the same branch scope; invite, resend invite, revoke invite, show pending activation.
* Create and edit staff profiles; update employment data; assign predefined roles within the HR user's branch scope.
* Assign services that Personnel can perform; manage personnel service eligibility.
* Manage availability calendars, shifts, working days, working hours, break/off-duty status, unavailable dates, and emergency unavailable status.
* **Define and manage personnel compensation models** — commission-only, salary-plus-commission, salary-only — including commission rules, salary terms, effective dating, and compensation change history (see §12).
* **Prepare compensation data for payout runs** (Finance verifies and marks paid).
* Maintain staff employment records and role/branch/status history.
* Search and filter staff; export the staff roster only.

### Explicit Exclusions

HR must not:

* Manage or assign staff in other branches.
* Manage Merchant Administrator account activation status.
* Export client data or payment data.
* Self-escalate permissions or assign themselves a higher-risk role.
* **Pay merchant subscription invoices by default** (HR has no default subscription-payment access; only a future explicit permission override could grant it).
* Edit compensation outside their branch scope.

### Mandatory Staff Profile Fields

First name, last name, display name, profile picture (where merchant policy requires), email (required and unique for active staff), phone (required and unique for active staff), role, employment type, employment status, primary branch (limited to the HR user's branch scope), start date, and staff invited by.

### Duplicate Staff Prevention

The platform prevents duplicate active staff accounts across the platform by email and phone.

### Suspension and Deactivation Rule

When a merchant staff user is deactivated (the standardized term covering "delete"/"remove"; see §14): active sessions are invalidated immediately, unused Magic Links are invalidated immediately, login is blocked, historical operational records are preserved, and reassignment checks are triggered for live queues, appointments, and service sessions.

### Production-Ready HR Feature Set

HR dashboard (staff counts, active/suspended/deactivated counts, pending invitations, unavailable personnel, branch filter); staff roster (list, search, filter, paginate, view profile, status badges, branch, role, availability, service eligibility); staff invitation/activation; edit staff; role assignment with permission preview and self-escalation prevention; branch scope enforcement; service eligibility; availability management; deactivation with reason and access revocation; role/branch/status history; HR-visible audit log of staff/access changes; and a permission-preview summary. New: a **compensation management surface** (compensation list, compensation detail, compensation setup modal, payout-run preparation) per §12.

### Authentication Rule

HR users log in via Magic Link after their email exists as an invited/active user under the relevant tenant and permitted branch scope.

---

## 4.5 Merchant Finance Account

### Purpose

The Finance account manages offline merchant-client payment validation, financial control, invoice payment status, receipts, financial reports, reconciliation, disputes, external refund records, cash-up approval, financial period locking, payout-run verification, and finance audit activity. Finance users access only the branch they are assigned into.

### Maker/Checker Posture

Front Office is the **default** recorder of customer payments; Finance **validates, rejects, corrects, and audits** them. Finance may **record** customer payments only as an optional permission override for merchants that want Finance to handle back-office payment capture. This prevents Finance from becoming both maker and checker by default.

| Action | Default owner |
| ------ | ------------- |
| Record customer payment | Front Office |
| Validate / reject payment | Finance |
| Correct payment reference | Finance |
| Override duplicate payment reference | Finance |
| Record payment as exception / back-office capture | Finance only if explicitly granted |

### Core Functionalities

* View invoices within assigned merchant and branch scope.
* Validate offline payments; approve or reject payment records; record and audit payment references.
* Correct payment references and override duplicate references (permissioned and audited).
* View outstanding balances, voided invoices, and externally recorded refunds.
* Review and resolve payment disputes; record external refunds with approval.
* **Approve or reject branch cash-ups** and request corrections where permitted.
* **Lock and reopen financial periods** through the central PeriodLockService (subject to Merchant Administrator policy).
* **Verify and mark personnel payout runs as paid; create compensation adjustments; respond to personnel earnings queries** (see §12).
* View commission and salary liabilities where permitted; export finance reports where permitted.
* **Pay the merchant's subscription invoice via M-Pesa and view detailed subscription payment attempts and reconciliation** (see §10).
* Manage the finance task inbox.

### Granular Finance Permissions

Finance must not launch as one broad permission. Permissions include: view invoices; validate payments; edit payment reference; generate receipts (only after validation rules are satisfied — note receipts are auto-generated, §4.5.1); void finance record (restricted/approval-based); review disputes; export finance reports (permission-controlled and audited); **lock and controlled-reopen financial periods (Finance-owned routine execution; an exceptional reopen requires Merchant Administrator approval only where the configured governance workflow demands it — the Merchant Administrator does not execute routine period locks; see §13.10 and Contradiction 7 in the amendment block)**; view commissions/salary; view subscription billing.

### 4.5.1 Receipts Are Auto-Generated After Validation

Receipts are generated **automatically** as a service action immediately after payment validation. There is no manual receipt-generation button available before validation. The correct rule is: `validated payment → automatic receipt generation`, respecting invoice status, payment validation status, duplicate-receipt prevention, receipt numbering, audit logging, and receipt reversal/reissue permissions.

### Payment Validation Workflow

Required validation statuses: pending validation, validated, rejected, partially validated, disputed, correction requested, voided, refunded externally. Required fields: `invoice_id, payment_record_id, payment_method, payment_amount, payment_reference, recorded_by, validated_by, validation_status, validation_note, rejection_reason, validated_at, branch_id, merchant_id`.

### Payment Reference Rules by Method

| Payment Method | Validation Rule |
| -------------- | --------------- |
| Cash | Amount, collector, branch/day cash-up reference. |
| M-Pesa | Transaction code/reference required. |
| Bank transfer | Bank reference or deposit-slip reference required. |
| Card terminal | Terminal reference required. |
| Voucher | Voucher code and approval status required. |
| Split payment | Each leg must have method, amount, and validation status. |
| Other | Merchant-defined reference requirements. |

### Duplicate Payment Reference Detection

Detect duplicate M-Pesa, bank, card, voucher, or custom references; block or warn within the same merchant; show branch conflict; override requires permission and reason; every override is logged.

### Partial and Split Payment Control

The system calculates the remaining balance automatically; one invoice can have several payment records; mixed methods are supported; each leg can be pending, validated, rejected, or disputed; the receipt shows only validated amounts; the invoice becomes paid only when the validated total equals the invoice total.

### Invoice Adjustment and Void Approval Workflow

Void unpaid invoice (permission + reason); void paid invoice (approval); adjust invoice after payment (approval); reverse preferred-personnel fee (reason + audit log); refund recorded externally (Finance/Admin approval); reopen paid invoice (highly restricted). All such mutations are blocked once the relevant financial period is locked (§4.5.3).

### Daily Branch Cash-Up and Reconciliation (Finance Side)

Finance reviews daily invoices and recorded payments, validates pending payments, compares expected vs recorded totals by method, reviews counted cash and references, flags discrepancies, approves or rejects the branch cash-up, and locks the day after approval. See §15 for the full cash-up definition and split of duties.

### 4.5.3 Finance Cannot Mutate Locked Periods

Once a day or month is locked, the system blocks editing payments, validating late payments into the locked period, voiding invoices, adjusting paid invoices, reissuing/reversing receipts, creating refunds, and changing cash-up figures. Every Finance mutation must call one central service — **PeriodLockService** — rather than scattered controller checks, to prevent inconsistent financial history and post-close tampering.

### Finance Dispute Management

Dispute statuses: open, under review, evidence requested, resolved, rejected, escalated, closed. Fields: `dispute_type, invoice_id, payment_record_id, raised_by, assigned_to, amount_in_dispute, reason, evidence_attachment, resolution_note, resolved_by, resolved_at`.

### External Refund Recording

Refund type (full/partial); amount cannot exceed validated amount; method (cash, M-Pesa, bank, card reversal, voucher, other); reference where applicable; approval workflow before finalization; appropriate invoice/payment status impact; every refund and approval logged.

### Finance Export Governance

Export permission (authorized Finance/Admin only); export reason for sensitive reports; export scope (branch, date range, report type); signed URL with expiry; download count; audit log; sensitive-data masking unless explicitly permitted.

### Finance Audit Dashboard

Payment recorded/edited/validated/rejected/disputed; receipt generated/reissued; invoice voided; refund recorded externally; financial period locked/reopened; finance export generated.

### Finance Notifications and Task Inbox

New payment pending validation (High); duplicate payment reference detected (Critical); paid invoice awaiting receipt (Medium); payment dispute opened (High); refund approval requested (High); invoice void approval requested (High); cash-up submitted for review (High); branch cash-up discrepancy (Critical); commission/salary liability generated (Medium); subscription invoice overdue (Critical); financial period ready for lock (High); payout run submitted for verification (High).

### Final Production-Launch Finance Navigation

Finance Overview; Pending Validations; Invoices; Payment Records; Receipts; Partial & Split Payments; Disputes; External Refunds; Cash-Up & Reconciliation; Financial Periods; Payout Runs; Commission & Salary Liabilities; Subscription Billing; Finance Reports; Exports; Audit Activity; Notifications; Settings.

### Authentication Rule

Finance users log in via Magic Link after their email exists as an invited/active user under the relevant tenant and assigned branch scope.

---
## 4.6 Merchant Front Office Account

### Purpose

The Front Office account handles client-facing operations for a specific branch and is the **default recorder of customer payments**, the **creator of merchant-client invoices**, the **creator of client records**, and the **owner of operational queue/appointment transfer**.

### Core Functionalities

* Register clients and retrieve existing clients within the assigned branch.
* **Create client records with branch-level duplicate prevention** by phone number (see §4.6.1).
* Create walk-in sessions and appointment records.
* Convert appointment arrival into the active queue/service flow without duplicating appointment, queue, or session records.
* Select services; assign next-available personnel; allow the client to select preferred personnel at the configured extra fee.
* Manage queue status and create service sessions.
* **Create merchant-client invoices** (invoice creation is a Front Office function).
* **Record offline payment method and details** (default customer-payment recorder) and submit them for Finance validation.
* **Transfer or reassign queue entries and appointments between eligible personnel** where permitted — the operational-continuity function (see §4.6.2).
* View awaiting-Finance-validation and ready-for-receipt statuses.
* View daily branch activity, current appointments, and walk-ins; notify clients of queue/service status.
* **Pay the merchant's subscription invoice through a simplified payment UX** where the merchant subscription is due (see §10).
* Search speed-sensitive operational records.

### 4.6.1 Client Creation and Duplicate Prevention

The Front Office user may create client records within their assigned branch. The system enforces **branch-level duplicate prevention using the client phone number**, server-side:

* Same branch + same phone number = **blocked** with `HTTP 409 Conflict`.
* Different branch + same phone number = **allowed** (clients are branch-scoped records at launch).
* The duplicate guard runs server-side; a UI warning alone is insufficient.
* The duplicate-blocking event is auditable.

Error message on conflict:

> "Client creation blocked because another client with this phone number already exists in this branch."

### 4.6.2 Queue and Appointment Transfer Ownership

Operational transfer of queue entries and appointments between personnel is a **Front Office** function (operational continuity). The Branch Manager cannot transfer, reassign, or move queue entries/appointments. HR retains service eligibility and personnel-assignment authority. Front Office may transfer only to eligible personnel, and every transfer is audited.

### Actionable Queues

Front Office supports actionable queues: check in, assign, start service, invoice, record payment, awaiting Finance validation, ready for receipt.

### Walk-In Creation Atomicity

Creating a walk-in atomically creates or attaches: client, selected service, branch, queue entry, assignment mode, and optional preferred-personnel fee.

### Valid State Transitions

```text
waiting → assigned → in service → completed → invoiced / paid
```

Controlled cancellation and no-show paths exist. The platform avoids double-booking and duplicate service sessions.

### Receipt Rule

Front Office must not issue a receipt without a linked invoice and a valid (validated) payment state. Receipts are auto-generated after validation (§4.5.1); Front Office does not manually generate receipts before validation.

### Offline Payment UI State

Because payments are offline but recorded online, the UI clearly shows: saved, unsaved, pending validation. (Full offline mode is not launch-critical.)

### Audit Logging

Every Front Office action creates audit logs: client create/edit, check-in, appointment change, queue change, assignment, preferred-personnel choice, queue/appointment transfer, invoice creation, payment recording, and receipt issuance state.

### Production Dashboard Sections

Next client, waiting, in service, completed today, pending commission, preferred requests, today's appointments, walk-ins, active queue, clients waiting, clients in service, paid/unpaid invoices, payments pending validation, receipts issued.

### Authentication Rule

Front Office users log in via Magic Link after their email exists as an invited/active user under the relevant tenant and assigned branch scope.

---

## 4.7 Merchant Personnel Account

### Purpose

Merchant Personnel are the service providers — barbers, hairdressers, stylists, massage therapists, nail technicians, beauticians, facial therapists, grooming specialists, cosmetologists. Personnel are strictly **own-scope** and have a private **My Earnings** experience reflecting their compensation model.

### Own-Scope Enforcement (Hard Rule)

Merchant Personnel users see only their own records. Own-scope is enforced server-side across every personnel surface:

| Area | Personnel can see |
| ---- | ----------------- |
| Queue | Queue entries assigned to them |
| Appointments | Appointments assigned to them |
| Service sessions | Sessions they performed |
| Commissions | Their own commissions only |
| Salary | Their own salary records only |
| Payouts | Their own payout records only |
| Served clients | Only clients they personally served |
| Client messages | Only their own SMS workflows |
| Earnings statements | Their own statements only |

A single `PersonnelOwnScopeTest` suite is created and applied across all personnel-facing APIs and screens, proving that one Personnel user cannot view, search, download, message, or access another Personnel user's records. The simplicity of personnel routes must never lead to broad data exposure.

### Core Functionalities

* View own dashboard (landing page) and personnel get-started page.
* View own assignments, queue, appointments, and service history.
* **View own earnings** — compensation model, salary (where applicable), commission, payouts, and compensation terms (see §12).
* View allowed personally served clients without any export capability.
* View preferred-personnel requests and clients who specifically requested them.
* View estimated wait order, service requested, and request state (active, cancelled, reassigned, completed).
* **Send in-platform bulk SMS to their own served-client list** where enabled (see §4.7.2).
* **Raise earnings queries** (missing commission, reversed commission, salary amount, payout status, adjustment, other).
* Download their own earnings statements.
* Use a mobile-first UI optimized for phones and tablets.

### Personnel Availability States

Available, busy, on break, offline, unavailable, suspended. HR and Merchant Administrator control permanent availability; Personnel may toggle limited operational states where allowed.

### 4.7.1 Contact Export Permanently Removed

The platform does not support Merchant Personnel contact export — permanently, at launch, across database, API, frontend, export infrastructure, permissions, routes, and UI. There is no personnel contact export endpoint, UI, permission, or database flag; no reusable table export button on personnel client lists; and no CSV, Excel, PDF, JSON, or clipboard export of personnel client contacts. Export-shaped requests under personnel scope return `404 Not Found` and create an unauthorized-access audit event. Personnel interact with served clients only through approved in-platform workflows (viewing permitted masked profiles, sending approved in-platform SMS).

### 4.7.2 Bulk SMS to Served Clients

Personnel may send SMS through the platform's integrated bulk SMS to their own served-client list. They may select all clients in their served-client list or selected recipients from it. Before sending, the system displays a cost notice:

> "SMS charges for this message will be billed to your branch together with the Servana subscription invoice. Continue?"

SMS controls: recipient selection stays own-scope; phone numbers are masked where required; sending happens inside Servana without exposing raw contact lists; the send action is audited; SMS cost is logged against the relevant branch and is billable to the merchant branch subscription billing record; promotional SMS respects client consent rules where applicable. Personnel cannot export client phone numbers.

### 4.7.3 Served-Client Visibility and Contact Protection

Personnel may view clients they personally served. They must not see merchant-wide client lists or clients served only by other personnel. Client access is filtered server-side. Contact fields are masked where required; every client contact/profile access by Personnel is audited; export-shaped routes under personnel scope are permanently rejected.

### Authentication Rule

Personnel users log in via Magic Link after their email exists as an invited/active user under the relevant tenant and assigned branch scope. Personnel have no subscription-payment access.

---

## 4.8 Merchant Audit Account

### Purpose

The Merchant Audit account provides **branch-scoped, read-only** operational and financial oversight, with a narrow exception for flagged-event metadata. Audit coverage now extends to compensation, salary, commission, and payout records.

### Branch-Only Scope

A Merchant Audit user assigned to one branch must not view audit records, client records, personnel records, finance records, salaries, commissions, invoices, receipts, queue entries, appointments, sessions, or reports from another branch. Every Audit query includes server-side branch-scope filtering; UI filtering alone is insufficient.

### Read-Only With Flagged-Event Exception

Audit is read-only across business records and must not create, edit, delete, approve, reject, validate, reverse, refund, assign, reassign, or mutate operational or financial records. The **only** exception is audit-module metadata for flagged events:

* Create a flagged audit event.
* Update flagged-event status.
* Add an audit review note.
* Resolve, dismiss, or escalate a flagged audit event.

This exception never permits Audit to mutate the underlying business record being audited.

### 4.8.1 Salary and Commission Audit Coverage

Audit is not limited to commissions. Where HR has configured a personnel compensation model as salary-only or salary-plus-commission, Audit can also audit salary records. Coverage includes compensation model changes, salary amount changes, salary effective dates, salary ledger entries, commission ledger entries, payout records, salary-plus-commission calculations, compensation approvals, and compensation reversals/adjustments. Audit is read-only and cannot edit salary, commission, payout, or compensation records.

### 4.8.2 Client Contact Masking and Field-Level Masking at Read Time

When Audit views client profile context, client contact details are not exposed by default. Audit can view client activity context and service, invoice, receipt, appointment, queue, and session history where permitted, but cannot view full client phone numbers or email addresses by default. Masking is enforced server-side at response time. Sensitive fields that may be masked include client phone number, client email, payment references, M-Pesa phone numbers, salary amounts (where branch audit policy restricts them), internal notes, sensitive dispute evidence, and other PII. Sensitive data is stored accurately but masked when returned to users without full permission. Any exceptional unmasking is permission-gated, reason-required, and audited.

### 4.8.3 Audit Exports

An audit export request is treated as an audit-module action, not a mutation of merchant business records, so it does not violate Audit's read-only restriction. Audit exports are allowed only where the Audit user has export permission and must be branch-scoped, permission-gated, reason-required, masked according to the user's permission level, delivered through expiring signed URLs, download-counted, and fully audited.

### Landing Screen

The Audit landing page shows high-risk events, recent activity, flagged items, payment issues, role changes, contact-access/export attempts, and preferred-personnel overrides — all within branch scope.

### Searchable and Filterable Audit Logs

Filter by date, actor, role, branch, module, action, entity type, severity, and event status (all within the assigned branch).

### Append-Only, Tamper Detection, Severity, Before/After

Audit logs are append-only; merchant users cannot update or delete audit records; each record carries a hash/chained hash for tamper detection. Severity levels: info, low, medium, high, critical. Role changes, payment-validation changes, receipt generation, voids, contact-access/export attempts, branch-access changes, salary/commission changes, and backdated compensation changes are high or critical. Every sensitive change includes mandatory `old_values` and `new_values`.

### Unauthorized Access Attempt Logging

The platform logs attempts to access unauthorized branch, merchant, invoice, payment, queue, client, compensation, or contact-export records.

### Authentication Rule

Audit users log in via Magic Link after their email exists as an invited/active user under the relevant tenant and assigned branch scope. Audit has read-only subscription-billing visibility only where permission exists.

---

## 4.9 Client Record (Branch Records Only at Launch)

### Purpose

The platform supports clients as service recipients held as **branch-scoped client records**. At launch, clients are not login users and have no portal.

### Launch Rules (Hard)

```text
No client login.
No client portal.
No client Magic Link.
No client dashboard.
No client authentication routes.
No client account activation.
Client records belong to a merchant and a branch.
A nullable user_id may exist on the clients table for future client-portal support, but it remains inactive at launch.
```

### Core Functionalities

Client profile; contact details; visit history; services consumed; assigned personnel history; preferred personnel history; invoice and receipt history; appointment history; queue participation; consent records; communication preferences; notes.

### Duplicate-Client Rule

The platform prevents duplicate-client registration within the same branch by phone number (same branch + same phone = blocked, `409 Conflict`; different branch + same phone = allowed). See §4.6.1.

### Future (Inactive) Portal

A future client portal could later allow clients to view appointments, receipts, and service history, join a queue remotely, choose preferred personnel, and update profile details. This is explicitly out of launch scope and the `user_id` support remains inactive.

---
# 5. Preferred Personnel Fee Rules

## 5.1 Definition and Launch Status

Preferred Personnel Fee Rules are **launch-active Super Administrator settings** that define the extra amount charged when a client requests a specific personnel member instead of accepting normal (next-available) assignment. This replaces and clarifies the earlier "Preferred Merchant Personnel Waiting Feature."

At launch the rule supports two models only:

```text
fixed amount
percentage
```

The rule is configured by the Super Administrator, applied automatically during queue or appointment preferred-personnel selection, shown as a separate invoice line, included in receipt totals, and fully audited. The database remains flexible enough to support service-category, branch-category, and personnel-tier fee models later, but those are not launch models.

## 5.2 Purpose

In barbershops, salons, spas, massage parlours, and beauty businesses, clients often prefer a specific barber, stylist, therapist, or beautician. The client chooses between waiting for the next-available personnel or waiting for a specific personnel member at the configured extra fee.

## 5.3 Super Administrator UX

Path:

```text
Platform Admin → Pricing & Fees → Preferred Personnel Fee Rules
```

The Super Administrator sees: rule name, model (fixed/percentage), value, status, effective-from date, created by, and last updated. The Super Administrator can create a rule, edit a scheduled rule, activate, deactivate, view usage, and view the audit trail.

The Super Administrator **cannot**: apply the fee manually to a single invoice; waive the fee secretly without audit; configure merchant commission from this screen; edit merchant service prices; or assign personnel.

## 5.4 Merchant-Side Behaviour

Merchant users do not configure the platform fee rule; they experience the result.

Front Office selection interface:

```text
Assignment mode:
○ Next available
○ Manual assignment
● Preferred personnel
```

Front Office owns operational selection and movement of clients through next-available, manual assignment, and preferred-personnel modes. The Branch Manager is operationally read-only for queue-entry and appointment assignment, reassignment, transfer, and preferred-personnel selection, and shall not appear on this selection interface (see §13.9 and Contradiction 4 in the amendment block).

After choosing preferred personnel, the fee is shown (e.g., "Preferred personnel fee: KES 200") and reflected in the invoice preview:

```text
Service: Haircut                    KES 1,000
Preferred personnel fee              KES 200
Total                               KES 1,200
```

Finance sees the fee in the invoice, payment, receipt, reports, cash-up, and reconciliation. HR decides whether personnel commission includes or excludes the preferred-personnel fee through compensation/commission rules. Personnel see the job as preferred and see related earnings only if HR's commission rule includes the preferred-personnel fee in the commission basis.

## 5.5 Preferred Personnel Workflow

```text
Client arrives or books appointment
Front Office opens client session
Front Office selects service
System displays eligible personnel
Client chooses: next available OR preferred specific personnel

If preferred personnel is selected:
  System displays the extra fee
  System displays estimated wait time
  Client confirms acceptance
  Queue entry is attached to selected personnel
  Invoice includes the preferred personnel fee as a separate line
  Payment is recorded offline (Front Office)
  Finance validates payment
  Receipt is generated automatically after validation
  Audit logs the selected personnel, fee, and creating user
```

## 5.6 Business Rules

Preferred selection is optional; the fee is visible before confirmation; the fee appears as a separate invoice line; the queue locks the client to the chosen personnel; Front Office may override the chosen personnel only with permission and reason; overrides create audit logs. If the preferred personnel becomes unavailable, the system supports waiting longer, reassignment, cancellation, preferred-fee reversal, and invoice adjustment. The system shows whether the preferred-personnel fee affects merchant revenue, the percentage platform fee (only where the percentage billing component is active), and personnel commission.

## 5.7 Financial Treatment

| Item | Rule |
| ---- | ---- |
| Preferred personnel fee | Treated as merchant revenue. |
| Platform percentage fee | Applies only where the percentage billing component is active and unless the Super Administrator exempts it. |
| Personnel commission | Controlled through HR commission/compensation settings (HR decides inclusion in the commission basis). |
| Receipt visibility | Visible as a separate receipt line item. |
| Audit visibility | Visible to Super Admin (platform), Merchant Admin, Finance, and branch-scoped Audit. |

---

# 6. Subscription-First Billing and Pricing Architecture

This section replaces the previous platform-fee-ledger-first model. Servana's launch billing is subscription-first with configurable platform billing rules. The old model — in which validated merchant-client invoices automatically accrue an active Citrus platform fee by default — is no longer the only or default billing model.

## 6.1 Configurable Billing Modes

The active billing mode is controlled by the Super Administrator and stored in configurable platform billing settings. It is applied tenant-safely and is never hardcoded:

```text
fixed_amount
percentage_on_merchant_client_invoice
fixed_amount_plus_percentage_on_merchant_client_invoice
```

Billing settings are editable only by authorized Super Administrator users. The platform supports seeded launch values, but those seeded values remain editable through platform settings.

## 6.2 Fixed Amount Billing Mode

When the Super Administrator selects fixed-amount billing, the merchant is billed according to the merchant's selected subscription plan and billing period.

Supported billing periods:

```text
weekly
bi_weekly
monthly
quarterly
annual
```

Fixed-amount billing is the best fit for subscription plans. The system generates subscription invoices according to the selected plan, billing period, billing cycle, discounts, extra-branch charges, SMS charges, and other approved add-on charges. When fixed-amount billing is the only active mode, the system does not require customer-centric / shared / business-centric service-fee-tier selection.

## 6.3 Percentage Billing Mode

Percentage billing is a **launch-supported active billing mode**, not a future-only or placeholder capability. When the Super Administrator activates percentage billing, Servana shall calculate a platform fee as a percentage applied to merchant-client invoices, fully specified and implementable at launch. Fixed-amount may remain the default launch configuration, but the Super Administrator shall be able to activate percentage or fixed-plus-percentage billing without a code deployment. The service-fee-tier structure applies **only** when a percentage component is active:

```text
customer_centric
shared
business_centric
```

The tier determines how the platform percentage fee affects the merchant-client invoice total and the merchant's fee liability. (For continuity, `shared` corresponds to the previously named "Split" treatment.)

### 6.3.1 Percentage-Fee Lifecycle

The percentage-fee lifecycle shall be:

```text
Merchant-client invoice is finalized
→ percentage-fee basis and configuration are snapshotted
→ provisional platform-fee entry is created (status: provisional)
→ merchant-client payment is validated
→ corresponding platform-fee liability becomes billable (status: billable)
→ billable entries are aggregated into a Servana billing invoice (status: aggregated)
→ merchant pays the Servana billing invoice through M-Pesa
→ M-Pesa reconciliation clears the billing liability (status: settled)
```

Where a merchant-client invoice is voided, refunded, corrected, or partially refunded, the system shall create a traceable fee reversal or adjustment in `platform_fee_adjustments`. The system shall never silently edit or delete an original `platform_fee_ledger_entries` row.

### 6.3.2 Fee-Basis and Configuration Snapshot

Every percentage-derived platform-fee entry shall capture the source invoice, the billing-mode and tier snapshots, the fee basis, the rate snapshot, the gross fee, the client-shifted amount, the merchant liability, the currency, status, effective-configuration reference, and aggregation/reversal references (full field list in §18.2a). Permitted `fee_basis_type` values are explicitly enumerated and shall match the configured platform rule:

```text
merchant_client_invoice_service_subtotal
merchant_client_invoice_total
validated_paid_amount
net_after_discount
invoice_item_subtotal
```

The fee basis shall never be left implicit.

### 6.3.3 Settled Tier Behaviour

| Service Fee Tier | Merchant-client invoice content | Client-shifted amount | Merchant liability to Servana |
| ---------------- | ------------------------------- | --------------------- | ----------------------------- |
| `customer_centric` | Contains only the original merchant service amount; the merchant absorbs the full percentage platform fee. | Zero. | Full calculated platform fee. |
| `shared` | Contains the configured share of the percentage platform fee added to the service amount; the remaining share is absorbed by the merchant. Client-shifted and merchant-absorbed amounts are stored separately, and the complete fee amount stays traceable. | Configured share of the fee. | Full calculated platform fee. |
| `business_centric` | Contains the full percentage platform fee added to the service amount. The full fee remains traceable as the merchant's liability to Servana until the corresponding Servana billing invoice is paid. | Equal to the calculated platform fee. | Full calculated platform fee. |

The tier changes how the merchant-client invoice is priced; it never removes the need to account for the merchant's liability to Servana. The service-fee-tier model is not a required launch onboarding field when the active billing mode is fixed-amount only.

## 6.4 Fixed Amount Plus Percentage Billing Mode

Fixed-amount-plus-percentage is a **launch-supported active billing mode**. When the Super Administrator activates it, the merchant has both a fixed subscription amount (based on plan and billing period) and a percentage-based platform fee calculated against merchant-client invoice values according to the active percentage configuration, following the same lifecycle, snapshot, and tier rules as §6.3. The mode is configuration-driven and supports both subscription invoices and percentage-derived platform-fee invoice lines, activatable without a code deployment.

## 6.5 Real-Time Merchant Administrator Pricing Visibility

Any pricing change made by the Super Administrator appears on the Merchant Administrator's landing page, billing dashboard, subscription screen, and plan-management screen in real time or near-real time. At minimum the Merchant Administrator sees:

```text
Current subscription plan
Current billing period
Current billing mode
Current plan amount
Current discounts, if any
Current extra branch charges, if any
Current SMS / add-on charges, if any
Next invoice amount
Next invoice date
Outstanding invoices
Billing status
Trial / free-period status
Read-only grace status, where applicable
Suspension reason, where applicable
```

The interface clearly distinguishes current active billing terms; scheduled future plan changes; scheduled future price changes; promotional discounts; one-time credits; and overdue balances. Pricing changes must not silently alter already-issued invoices unless the invoice is still draft and governed by an explicit billing rule.

## 6.6 Platform Billing Settings and Plan-Price Source of Truth

Servana uses one normalized authoritative architecture and shall not duplicate plan-price values:

```text
platform_billing_settings   → global billing behaviour, toggles, defaults, and windows
subscription_plans          → plan identity, positioning, limits, and non-price metadata
subscription_plan_prices    → authoritative versioned monetary price per plan and billing period
```

All plan prices are configured by the Super Administrator through the platform billing-settings domain and are authoritatively persisted as versioned records in `subscription_plan_prices` (§18.2b). Plan-price values shall not be stored as duplicated fields inside `platform_billing_settings`, and complete versioned price matrices shall not be duplicated anywhere. The `platform_billing_settings` store holds global configuration only:

```text
active_billing_mode
default_currency
free_period_days
read_only_grace_days
overdue_reminder_days
suspension_after_days
billing_periods_enabled
default_billing_period
platform_percentage_fee_active
platform_percentage_fee_rate
platform_fixed_fee_active
platform_fixed_fee_amount
fixed_plus_percentage_enabled
extra_branch_charge_minimum
extra_branch_charge_maximum
preferred_personnel_fee_settings
mpesa_payment_enabled
subscription_payment_recovery_enabled
sms_billing_enabled
promotion_engine_enabled
trial_offer_engine_enabled
```

Every billing-setting change is audited and records:

```text
actor_user_id
actor_role
old_value
new_value
reason
effective_from
effective_to (where applicable)
created_at
updated_at
```

## 6.7 Shared Overdue Escalation Engine

Subscription invoices, any optional platform-fee invoices, add-on invoices, and branch-linked billing charges share **one** overdue escalation engine. No separate dunning paths are built. Default reminder cadence is day 3 / day 7 / day 14, all configurable. The engine supports:

```text
grace_days
reminder_days
suspension_after_days
invoice_type
merchant_id
invoice_id
billing_status_change
audit_reason
notification_sent_at
```

Audit logs distinguish the cause of each status change:

```text
merchant_suspended_due_to_subscription_overdue
merchant_suspended_due_to_platform_fee_overdue
merchant_suspended_due_to_multiple_overdue_invoices
merchant_entered_read_only_grace_due_to_trial_expiry
merchant_reactivated_after_subscription_payment
```

## 6.8 No Mid-Cycle Proration

Plan upgrades, downgrades, billing-period changes, and per-extra-branch charges take effect at the start of the next billing cycle. The current billing cycle's charge remains unchanged. No mid-cycle proration is applied, and no automatic grandfathering exists at launch.

Two distinct mechanisms are kept separate and shall never be conflated:

- A **merchant plan or billing-period change** (the merchant moving from one plan/period to another) is recorded in `scheduled_plan_changes` and takes effect next cycle:

```text
merchant_id
current_plan_id
requested_plan_id
current_billing_period
requested_billing_period
requested_by_user_id
requested_at
effective_from_next_cycle
status
reason
```

- A **plan-price change** (the Super Administrator changing the monetary price of a plan/period) is recorded as a versioned `subscription_plan_prices` record (§18.2b, §13 in the amendment block), never in `scheduled_plan_changes` and never by overwriting the active price.

`NoProrationPlanChangeTest` asserts that a mid-cycle plan change does not alter the current cycle's issued invoice; `PlanChangeAndPriceChangeSeparationTest` asserts the two mechanisms remain separate.

---
# 7. Subscription Plans and Plan Entitlements

Servana ships four subscription plans. The guiding differentiation strategy is **do not make Starter useless**: every paying merchant can run a real business on Starter, and plans differentiate by scale, control, automation, reporting depth, and multi-branch complexity. The plan architecture uses **entitlements**, not hidden hardcoded restrictions, and entitlements are enforced server-side.

## 7.1 Plan Positioning

| Plan | Best-fit merchant | Differentiator |
| ---- | ----------------- | -------------- |
| **Starter** | Solo or very small service business | Run one branch professionally without advanced controls. |
| **Growth** | Growing single-branch SME | Adds team structure, finance control, HR depth, better reporting. |
| **Pro Branch** | High-volume single branch | Adds advanced operations, finance governance, audit, exports, automation. |
| **Multi-Branch** | Business with 2+ branches | Adds branch expansion, centralized control, branch comparison, multi-branch governance. |

Product roles: **Starter = operate; Growth = manage team; Pro Branch = control money; Multi-Branch = scale branches.** Growth is the plan most serious SMEs should buy. Starter is the acquisition plan, Growth the main revenue plan, Pro Branch the control/governance upsell, and Multi-Branch the expansion/account-value plan.

## 7.2 Merchant Administrator Plan Selection

The Merchant Administrator selects a plan during account creation or immediately after, before operational dashboard access. The plan applies only to that merchant tenant and all its branches and never to other tenants. The Merchant Administrator may request or schedule a plan change while logged in, subject to entitlement validation, billing-cycle rules, the no-proration rule (§6.8), and payment-status rules.

## 7.3 Entitlement-Based Enforcement

Plan entitlements are enforced server-side through middleware, policies, and service classes. Frontend hiding is permitted only for UX and is never the sole enforcement layer. Entitlements include:

```text
branch limit
staff user limit
personnel profile limit
service catalogue limit
finance export access
audit dashboard access
cash-up workflow access
period lock access
refund workflow access
dispute workflow access
advanced reports access
multi-branch dashboard access
branch comparison access
preferred personnel fee feature access
commission feature access
salary compensation feature access
payout run feature access
bulk SMS access
storage quota
extra branch billing access
```

## 7.4 Starter Plan

**Target user:** a solo or very small service business with one outlet and a small team — small barbershop, salon, spa, clinic, repair shop, car wash/detailing shop, or small service SME.

### Recommended Limits

```text
Branches: 1 branch only
Staff users: up to 3 staff users
Personnel profiles: up to 3
Clients: unlimited
Services: up to 20
Appointments: unlimited
Queue entries: unlimited
Invoices / receipts: unlimited
Storage: basic
Exports: no finance exports
Reports: basic daily and weekly reports
```

### Included Functionality

```text
Magic Link login
basic merchant setup
one branch profile
branch operating hours
basic service catalogue and pricing
client records
duplicate client prevention
walk-in queue
next-available assignment
basic appointments
service session tracking
basic invoice creation
payment recording
automatic receipt generation after validation
basic sales reports
daily activity reports
appointment summaries
queue summaries
email notifications
minimal system audit
```

### Excluded / Upgrade Triggers

```text
advanced finance exports              → Pro Branch
HR commission rules                   → Growth
advanced audit logs                   → Pro Branch
multi-role granular finance permissions → Growth / Pro Branch
preferred personnel fee configuration → Growth / Pro Branch
advanced reports                      → Growth / Pro Branch
multiple branches                     → Multi-Branch
full cash-up approval workflow        → Pro Branch
period lock / reopen workflow         → Pro Branch
refund workflow                       → Pro Branch
dispute workflow                      → Pro Branch
multi-branch dashboards               → Multi-Branch
```

Starter must solve the core pain of replacing notebooks, scattered receipts, manual queues, and informal payment tracking. It is a real product, not a demo. Estimated adoption: 30–40% of paying merchants.

## 7.5 Growth Plan

**Target user:** a serious single-branch business with multiple staff, a dedicated front desk, a finance/admin person, and personnel commission needs.

### Recommended Limits

```text
Branches: 1 branch
Staff users: up to 8–12 staff users
Personnel profiles: up to 10
Clients: unlimited
Services: unlimited
Appointments: unlimited
Queue entries: unlimited
Invoices / receipts: unlimited
Exports: limited exports
Reports: standard operational and finance reports
```

### Included Functionality (Everything in Starter, plus)

```text
staff invitations
staff profiles
staff lifecycle management
service eligibility assignment
personnel availability and unavailability
basic commission rules
manual queue assignment
preferred personnel selection
appointment personnel assignment
appointment conflict prevention
payment validation workflow
basic cash-up: Branch Manager submits, Finance single-step approves or rejects, approved record becomes immutable (record-level closure, not period locking)
receipt reissue with permission
staff performance reports
service performance reports
daily finance reports
subscription invoice view
basic role separation (Branch Manager, HR, Finance, Front Office, Personnel)
basic role / action history
```

### Excluded / Upgrade Triggers

```text
full audit dashboard                       → Pro Branch
sensitive finance exports                  → Pro Branch
advanced cash-up governance, period locking, controlled reopening, multi-stage approval, advanced cash-up exports → Pro Branch
advanced commission reports                → Pro Branch
advanced branch governance                 → Multi-Branch
multi-branch operations                    → Multi-Branch
```

Growth is the most important commercial plan and the default recommendation for most serious SMEs because it adds the management layer: HR, commissions, validation, role separation, and operational reports. Estimated adoption: 40–50% of paying merchants; likely the highest-revenue-volume plan.

## 7.6 Pro Branch Plan

**Target user:** a high-volume single-branch business with owner/operator oversight, finance controls, audit needs, and multiple operational roles — busy salon, medical/aesthetic clinic, car service center, premium barbershop, repair center, training/service studio.

### Recommended Limits

```text
Branches: 1 branch
Staff users: up to 25 staff users
Personnel profiles: up to 25
Clients: unlimited
Services: unlimited
Queue entries: unlimited
Appointments: unlimited
Finance exports: included
Audit: full branch and merchant audit visibility
Reports: advanced reports
Storage: higher storage quota
```

### Included Functionality (Everything in Growth, plus)

```text
full payment validation
payment rejection
duplicate payment override permissions
cash-up submission, review, approve, and reject workflow
daily and monthly period locking
period reopening controls
external refund workflow
finance dispute management
finance exports with reason capture
signed URL exports
download logs
full audit dashboard
flagged audit events
sensitive event tracking
advanced finance, personnel, service, client, appointment, and queue reports
advanced commission visibility and reconciliation
preferred personnel fee tracking and reports
granular finance permission overrides
finance task inbox
operational alerts
```

### Excluded / Upgrade Triggers

```text
more than one branch                  → Multi-Branch
branch comparison reports             → Multi-Branch
centralized multi-branch dashboard    → Multi-Branch
cross-branch staff movement           → Multi-Branch
multi-branch billing / add-on logic   → Multi-Branch
```

Pro Branch is for single-location businesses that need control, accountability, and financial discipline — "for businesses where mistakes, leakage, and weak controls are already costing money." Estimated adoption: 10–20% of paying merchants.

## 7.7 Multi-Branch Plan

**Target user:** a merchant operating two or more branches or planning to expand — salon chain, clinic group, car wash network, repair chain, wellness brand, training/service franchise.

### Recommended Limits

```text
Branches: 2 included
Extra branches: charged per additional branch
Staff users: 50+ or configurable
Personnel profiles: 50+ or configurable
Services: unlimited
Clients: unlimited
Reports: multi-branch reports
Exports: multi-branch exports
Audit: multi-branch audit
Storage: highest quota
```

### Included Functionality (Everything in Pro Branch, plus)

```text
create and manage multiple branches
centralized merchant-wide operational dashboard
branch comparison by sales
branch comparison by appointments
branch comparison by queue performance
branch comparison by personnel output
consolidated merchant reports
branch-level finance visibility
merchant-level finance visibility
multi-branch audit filtering (branch / user / module / severity)
assign users to specific branches
entitlement-based branch creation
extra branch billing
centralized subscription covering all branches
branch-by-branch cash-up status
branch-level and merchant-level period locking
```

### Excluded / Future Add-Ons (Outside Core Launch Unless Separately Approved)

```text
franchise royalty engine
cross-merchant benchmarking
enterprise API access
WhatsApp automation
advanced SMS automation beyond approved bulk SMS
inventory
full payroll compliance engine
```

Multi-Branch means central command, not just "more branches": the owner can see every branch, compare performance, control access, and stop leakage. Estimated adoption: 5–10% initially, but the highest expansion revenue.

## 7.8 Differentiation Matrix

| Capability | Starter | Growth | Pro Branch | Multi-Branch |
| ---------- | ------: | -----: | ---------: | -----------: |
| Branches | 1 | 1 | 1 | 2 included + paid extras |
| Staff users | 3 | 8–12 | 25 | 50+ |
| Client records | ✓ | ✓ | ✓ | ✓ |
| Service catalogue | Limited (20) | Unlimited | Unlimited | Unlimited |
| Appointments | ✓ | ✓ | ✓ | ✓ |
| Walk-in queue | ✓ | ✓ | ✓ | ✓ |
| Manual assignment | Basic | ✓ | ✓ | ✓ |
| Preferred personnel | View/use | ✓ | ✓ advanced | ✓ advanced |
| Invoicing | ✓ | ✓ | ✓ | ✓ |
| Payment recording | ✓ | ✓ | ✓ | ✓ |
| Payment validation | Basic/simple | ✓ | Advanced | Advanced |
| Receipt generation | ✓ | ✓ | ✓ | ✓ |
| Receipt reissue | — | Limited | ✓ | ✓ |
| HR staff lifecycle | — | ✓ | ✓ | ✓ |
| Service eligibility | — | ✓ | ✓ | ✓ |
| Availability management | — | ✓ | ✓ | ✓ |
| Commissions | — | Basic | Advanced | Advanced |
| Salary / compensation models | — | Basic | Advanced | Advanced |
| Payout runs | — | Basic | ✓ | ✓ |
| Cash-up | — | Basic submit | Full review/approve | Multi-branch |
| Period locks | — | — | ✓ | ✓ |
| Refund workflow | — | — | ✓ | ✓ |
| Disputes | — | — | ✓ | ✓ |
| Finance exports | — | Limited | ✓ | ✓ |
| Staff roster export | — | ✓ | ✓ | ✓ |
| Audit dashboard | Minimal | Basic | Full | Full multi-branch |
| Advanced reports | — | Standard | Advanced | Consolidated |
| Branch comparison | — | — | — | ✓ |
| Multi-branch users | — | — | — | ✓ |
| Extra branch billing | — | — | — | ✓ |
| Bulk SMS | Optional/add-on | Optional/add-on | ✓ | ✓ |

## 7.9 Commercial Framing

* **Starter — "Digitize your daily service operations."** Replace notebooks and scattered receipts with one simple system.
* **Growth — "Manage your team and money properly."** Add staff control, payment validation, commissions, and real reports.
* **Pro Branch — "Control leakage and run a serious branch."** Advanced finance controls, audit trails, cash-up, exports, and accountability.
* **Multi-Branch — "Run every branch from one command center."** Centralized visibility, branch comparison, multi-branch governance, and expansion control.

## 7.10 Pricing Logic

Servana prices by business maturity and operational complexity, not mainly by transaction volume. Suggested relative pricing: Starter 1.0x (entry point); Growth 2.0x–2.5x (most valuable mainstream tier); Pro Branch 4.0x–5.0x (controls and finance governance); Multi-Branch 6.0x+ (expansion and branch-level complexity).

Illustrative monthly pricing (not final, and always configurable in `platform_billing_settings`):

| Plan | Illustrative monthly price |
| ---- | -------------------------- |
| Starter | KES 1,500–2,500 |
| Growth | KES 3,500–5,000 |
| Pro Branch | KES 7,500–10,000 |
| Multi-Branch | KES 12,000–18,000 for 2 branches + extra-branch fee |

Most realistic revenue mix after 6–12 months: Starter 30–40% of merchants (medium revenue importance); Growth 40–50% (highest); Pro Branch 10–20% (high); Multi-Branch 5–10% (high per account). All plan prices, relative multipliers, and illustrative figures are configurable and not hardcoded.

---
# 8. Promotional Discounts and Free-Period Offers

## 8.1 Super Administrator Promotional Discount Control

The Super Administrator can create promotional discounts of two types:

```text
percentage_discount
fixed_amount_discount
```

Each promotional discount has:

```text
discount_name
discount_type
discount_value
currency (where fixed amount)
discount_period_length
discount_period_unit
effective_from
effective_to
scope
status
created_by
updated_by
audit_reason
```

Supported promotional discount scopes:

```text
all_merchant_administrators
new_merchant_administrators
selected_merchant_administrators
specific_merchant_administrator
selected_merchants
specific_merchant
```

Naming consistently distinguishes merchant accounts from individual users. Where a promotional discount applies to a merchant account, the target is the merchant tenant. Where it applies to a Merchant Administrator, it still ultimately affects that Merchant Administrator's merchant account billing — never the personal billing of a staff user. Promotions can target percentage-format merchants, fixed-amount merchants, all merchants, or specific merchants/Merchant Administrators.

## 8.2 Free-Period (Trial) Offer Control

The Super Administrator configures trial/free-period offers. The "first month free" is **not** hardcoded.

Default setting:

```text
free_period_days = 30
```

The Super Administrator can configure:

```text
free_period_days
free_period_offer_scope
specific merchant targets
specific Merchant Administrator targets (where applicable)
start date
end date
active / inactive status
```

Supported free-period scopes:

```text
all_merchants
new_merchants
selected_merchants
specific_merchants
```

## 8.3 Trial Starts at Merchant Administrator Account Creation

```text
Trial / free period starts at Merchant Administrator account creation.
Trial lasts for the configured number of calendar days.
Default launch duration is 30 calendar days.
Merchant must complete first-time setup before operational dashboard access.
Billing eligibility starts only after the configured free period ends.
If no active paid subscription exists after trial expiry, the merchant enters read_only_grace.
```

The platform does **not** use "billing starts after setup completes or 30 days, whichever comes first." A serious merchant who completes setup early is not penalized. The trial clock runs from Merchant Administrator account creation, not from setup completion.

---

# 9. Merchant Billing Status, Operational Status, Trial, Grace, and Suspension

## 9.1 Separate Operational Status from Billing Status

Merchant operational status and merchant billing status are separate fields and are never collapsed into one column.

```text
merchants.status          → operational / business lifecycle
merchants.billing_status  → subscription, trial, grace, overdue, billing suspension lifecycle
```

Example operational statuses:

```text
pending_setup
active
suspended
deactivated
```

Example billing statuses:

```text
trialing
active_subscription
read_only_grace
overdue
suspended_billing
```

Illustrative combinations:

```text
A merchant may be operationally active but billing-trialing.
A merchant may be operationally active but in read-only billing grace.
A merchant may be billing-suspended but still allowed to access subscription-payment recovery.
A merchant may be manually suspended for fraud or security reasons even if billing is paid.
```

## 9.2 Trial-to-Paid Churn Lifecycle

When the configured free period ends and the merchant has not subscribed or paid the required subscription invoice:

```text
trialing → read_only_grace → suspended_billing
```

Default launch read-only grace duration:

```text
read_only_grace_days = 14
```

The grace duration remains configurable.

## 9.3 Read-Only Grace — Allowed Actions

During `read_only_grace`, the merchant may access historical and billing-recovery functions:

```text
view dashboard
view clients
view past invoices
view receipts
view reports
download existing receipts where permitted
download existing reports where permitted
view subscription screens
view billing screens
view outstanding invoices
pay subscription invoice
view payment status
contact support
logout
```

## 9.4 Read-Only Grace — Blocked Actions (Server-Side)

During `read_only_grace`, the following are blocked server-side (UI hiding is insufficient):

```text
new walk-ins
new appointments
new queue entries
new service sessions
new merchant-client invoices
new merchant-client payment records
new receipts
new staff invitations
new branch creation
service catalogue edits
commission rule changes
salary compensation changes
payout run creation
cash-up mutation
refund creation
dispute mutation
receipt reissue / reversal
any new operational record
any mutating endpoint not explicitly allowed for billing / subscription recovery
```

## 9.5 Billing Suspension

After the read-only grace period ends without payment:

```text
merchant.billing_status = suspended_billing
merchant operational access is restricted to billing recovery only where the suspension reason is unpaid subscription
existing inactivity / dormancy lifecycle applies (per retention policy)
historical records remain preserved according to retention rules
```

A merchant suspended only for billing can still log in and pay. A merchant suspended for fraud, security, legal, compliance, manual platform suspension, or deactivation is not automatically reactivated merely because a billing invoice is paid (see §10.10).

## 9.6 Shared Overdue Escalation Engine

Subscription invoices, optional platform-fee invoices, add-on invoices, and branch-linked billing charges share one overdue escalation engine. No separate dunning paths exist. Default reminder cadence: day 3 / day 7 / day 14, configurable. See §6.7 for the engine fields and the audit-reason taxonomy distinguishing the cause of each suspension or grace transition.

---
# 10. Merchant Subscription M-Pesa Payment and Account Recovery

## 10.1 Correct Product Definition

> **Merchant Recovery through Automated M-Pesa Subscription Payment** allows Merchant Admin, Branch Manager, Finance, and Front Office users to pay the merchant's Servana subscription invoice through M-Pesa. The platform validates the payment automatically using M-Pesa integration, reconciles it to the invoice, updates the subscription invoice status, and restores access automatically when the merchant was suspended only because of unpaid subscription billing.

This replaces any interpretation that the Super Administrator normally records offline subscription payments. The correct process is:

```text
Merchant user opens subscription invoice.
Merchant user pays via M-Pesa.
M-Pesa confirms payment to Servana.
Servana automatically validates the payment.
Servana marks the invoice paid when the amount is cleared.
Servana updates billing status.
Servana reactivates the suspended merchant automatically when suspension is billing-only.
Super Admin monitors exceptions, failed reconciliations, fraud flags, and billing settings.
```

## 10.2 Business Purpose

Merchant billing is self-service. A suspended merchant should not need to call Citrus/Servana support to recover access. They can still log in, view the outstanding subscription invoice, pay through M-Pesa, receive automated confirmation, and regain access after successful validation. Billing suspension blocks operational activity, not payment recovery.

## 10.3 Eligible Users Who Can Pay

The subscription invoice belongs to the **merchant account**, not to a specific branch or individual user. Allowed paying users:

```text
Merchant Administrator
Merchant Branch account user / Branch Manager
Merchant Finance account user
Merchant Front Office account user
```

Default not allowed:

```text
Merchant Human Resource account user (unless explicitly granted later)
Merchant Personnel account user
Merchant Audit account user (read-only)
Super Administrator (configures and monitors only; does not pay merchant invoices)
```

## 10.4 Shared Invoice, User-Specific Payment Attempt

There is one subscription invoice (e.g., Merchant: Fresh Cuts Salon; Invoice: CIT-SUB-000123; Amount Due: KES 5,000; Status: Issued/Overdue/Partially Paid). Multiple eligible users may view it according to permission scope, but **every payment attempt is user-specific** and records:

```text
initiated_by_user_id
initiated_by_role
initiated_from_branch_id
merchant_id
subscription_invoice_id
mpesa_checkout_request_id
mpesa_receipt_number
amount
status
timestamp
ip_address
user_agent
```

## 10.5 M-Pesa Payment Methods

Launch recommendation: **STK Push first**, then **PayBill/Till** fallback. Supported methods:

```text
mpesa_stk_push
mpesa_paybill
mpesa_till
```

STK Push flow:

```text
User enters or confirms phone number.
System sends the M-Pesa prompt.
User enters their M-Pesa PIN on the phone.
M-Pesa sends a callback to Servana.
Servana validates and reconciles the payment.
The invoice is updated; billing status is updated.
```

PayBill/Till fallback flow:

```text
User pays using PayBill or Till.
User enters the account reference, e.g., CIT-SUB-000123.
M-Pesa confirmation reaches Servana.
Servana reconciles using receipt number, account reference, amount, merchant invoice number, phone number, and timestamp.
```

STK Push gives a cleaner in-app UX with fewer reconciliation errors and is the launch-first method.

## 10.6 Subscription Invoice Statuses

```text
draft
issued
pending_payment
partially_paid
paid
overdue
cancelled
payment_failed
reconciliation_required
```

Meaning: `draft` (exists but not issued); `issued` (exists and payable); `pending_payment` (M-Pesa initiated, not confirmed); `partially_paid` (confirmed amount less than total); `paid` (fully cleared); `overdue` (due date passed); `payment_failed` (attempt failed); `reconciliation_required` (received but not safely matched); `cancelled` (cancelled by system/admin process).

## 10.7 Subscription Payment Attempt Statuses

```text
initiated
stk_push_sent
customer_cancelled
timeout
failed
callback_received
validated
rejected
duplicate
reconciliation_required
applied_to_invoice
refunded_externally
```

Primary success flow:

```text
initiated → stk_push_sent → callback_received → validated → applied_to_invoice
```

Failure paths:

```text
stk_push_sent → timeout
stk_push_sent → customer_cancelled
callback_received → reconciliation_required
callback_received → duplicate
callback_received → rejected
```

## 10.8 Automated Validation Logic

When M-Pesa confirms a payment, Servana validates:

```text
M-Pesa receipt number exists
M-Pesa receipt number is unique
amount is greater than zero
amount matches invoice amount or a valid partial-payment rule
invoice exists
merchant exists
invoice belongs to the merchant
invoice is payable
payment has not already been applied
callback signature / security validation passes
transaction timestamp is valid
phone number / reference is captured
```

When validation passes:

```text
subscription_payment.status = validated
subscription_invoice.paid_amount increases
subscription_invoice.status = paid when fully cleared
merchant.billing_status = active_subscription
merchant access is restored when suspension is billing-related only
```

## 10.9 Suspended Merchant Recovery

A merchant user suspended for unpaid subscription can still complete Magic Link login. After login, instead of the normal dashboard, they land on **Account Suspended — Payment Required**.

Allowed on the recovery surface:

```text
view suspension reason
view outstanding subscription invoice
pay invoice through M-Pesa
track payment status
download invoice
contact support
logout
```

Blocked during suspension:

```text
normal operational dashboards
clients
queue
appointments
sessions
merchant-client invoices
merchant-client payments
receipts
staff management
service management
reports unrelated to billing recovery
exports unrelated to billing recovery
branch operations
```

After full payment validation:

```text
subscription_invoice.status = paid
merchant_subscription.status = active
merchant.billing_status = active_subscription
merchant.billing_suspended_at = null
merchant.read_only_grace_started_at = null
merchant.read_only_grace_ends_at = null
```

Then:

```text
invalidate stale billing-suspension cache
refresh user permissions / session context
record audit event
show reactivation success screen
```

## 10.10 Automatic Reactivation Rule

Automatic reactivation is allowed only when:

```text
billing_status = suspended_billing
suspension_reason = unpaid_subscription
invoice fully paid
payment validated
```

Automatic reactivation is not allowed when the suspension reason is:

```text
fraud
security
manual platform suspension
legal
compliance
merchant deactivated
```

In those cases the payment may be accepted, but access remains blocked pending Super Administrator review.

## 10.11 Payment Locks and Double-Payment Protection

Because multiple eligible users can pay the same invoice, double-payment risk is real (estimated 35–50% without protections). A short payment lock is added.

Payment lock fields:

```text
subscription_invoice_id
locked_by_user_id
locked_until
status = payment_in_progress
```

Recommended lock timeout: 2–5 minutes. Required protections:

```text
payment lock
idempotency key
unique M-Pesa receipt number
invoice balance check
callback replay protection
payment attempt expiry
```

When one user starts payment, the invoice shows to others as "Payment in progress" (with who started it and when). Other users may wait, retry after timeout, or start another payment only after the current attempt expires.

When the amount received exceeds the invoice balance:

```text
Apply the invoice balance.
Record the excess as merchant billing credit.
Show the credit in the billing dashboard.
Apply the credit to the next subscription invoice.
```

Overpayment is never silently discarded.

## 10.12 Per-Role Payment UX

**Merchant Administrator** — Billing & Subscription dashboard shows the current plan, billing status, next invoice, and outstanding balance; when an invoice exists, "Pay with M-Pesa," "View invoice," and "Download PDF" are available. The payment modal sends an STK prompt to the confirmed phone, shows a waiting state, and then a success state with the M-Pesa receipt. The suspended-account UX routes the Admin to recovery with a "Pay with M-Pesa" action.

**Branch Manager** — sees an account-billing notice / subscription-payment screen showing the unpaid merchant invoice with "Pay with M-Pesa," without full platform billing settings. The suspended UX is a restricted billing-recovery screen ("View Invoice," "Pay with M-Pesa," "Logout"). The payment record captures that the Branch Manager initiated from a specific branch context, even though the invoice is merchant-wide.

**Finance** — has the most detailed payment visibility among merchant-side users: a dashboard notification, a **payment attempts view** (attempt, user, branch, masked phone, amount, status, M-Pesa receipt), a payment modal showing invoice total / amount paid / balance due / allowed payment amount, and post-payment downloads (subscription invoice PDF, payment confirmation, subscription payment statement).

**Front Office** — sees a simple banner ("Subscription payment required … Pay now to avoid service interruption … Amount due … Pay with M-Pesa") without plan-change controls, billing settings, all historical invoices, merchant-wide financial reports, or other branches' attempts. The flow is enter phone → send prompt → wait → success/failure, with simple success messaging and no advanced reconciliation detail unless payment fails.

## 10.13 Shared Payment UX Rules

The invoice state is synchronized across users; a payment lock prevents simultaneous prompts; on timeout, retry is allowed; on success, the lock releases and the invoice is marked paid. Failure UX is explicit: user cancelled ("No amount was deducted. Try Again"), STK timeout ("We could not confirm in time. No payment applied. Check your M-Pesa messages. Try Again"), insufficient funds ("Use another phone or add funds and try again"), and received-but-unmatched ("Payment received, but we could not automatically match it … flagged for review. Reference: …"). The unmatched case becomes a Super Administrator billing exception, not a manual payment-recording task.

## 10.14 Super Administrator Role After Correction

The Super Administrator does **not** normally record subscription payments. The Super Administrator can configure the M-Pesa integration, configure subscription plans and the billing lifecycle, view subscription invoices and payment attempts, view and resolve reconciliation exceptions, refund/credit overpayments where policy allows, suspend/reactivate merchants manually for non-billing reasons, and audit payment events. The Super Administrator intervenes only on: an unmatched callback (resolve reconciliation exception); a duplicate payment (confirm credit/refund workflow); a fraud flag (investigate); a paid-but-not-restored non-billing suspension (explain or manually handle per policy); an integration outage (trigger reconciliation job); or a chargeback/reversal (mark billing exception).

## 10.15 Subscription Payment Notifications

```text
Merchant Admin: "Subscription payment successful. Invoice CIT-SUB-000123 has been paid via M-Pesa. Merchant account is active."
Finance: "Subscription payment validated. Amount: KES 5,000. M-Pesa receipt: <ref>. Invoice: CIT-SUB-000123."
Branch Manager / Front Office: "Subscription payment confirmed. Branch access has been restored."
Super Admin (exception): "M-Pesa subscription payment requires reconciliation. Receipt: <ref>. Amount: KES 5,000. Reason: account reference mismatch."
```

## 10.16 Acceptance Criteria (Subscription Payment)

```text
1. Merchant Admin can pay a subscription invoice via M-Pesa.
2. Branch Manager can pay the same merchant subscription invoice via M-Pesa from branch context.
3. Merchant Finance can pay and view detailed payment attempts.
4. Front Office can pay with a simplified billing-recovery UX.
5. Suspended merchant users can log in only to billing-recovery screens.
6. Suspended merchant users can pay the outstanding invoice.
7. M-Pesa callback automatically validates a successful payment.
8. The invoice becomes paid without Super Admin manual recording.
9. Billing status changes to active_subscription after full payment.
10. Billing-only suspended merchants are automatically reactivated after full payment.
11. Non-billing suspended merchants are not automatically reactivated.
12. Duplicate M-Pesa callbacks do not double-credit the invoice.
13. Concurrent payment attempts are blocked or safely handled.
14. Overpayments become merchant billing credit.
15. Failed / timeout / cancelled STK attempts are visible and retryable.
16. Finance can see payment attempts and results.
17. Front Office cannot see sensitive billing settings.
18. Super Admin can monitor exceptions but does not normally record payments.
19. Every payment event is audited.
20. The system remains usable for billing recovery even during suspension.
```

---
# 11. Merchant Self-Registration as the Only Merchant Creation Path

## 11.1 Binding Rule

```text
Merchant creation path = Merchant Administrator self-registration only.
```

No other account user can create a merchant tenant.

| Actor | Can create merchant? | Can create first Merchant Admin? |
| ----- | -------------------: | -------------------------------: |
| Public self-registering Merchant Administrator | Yes | Yes, themselves |
| Super Administrator | No | No |
| Branch Manager | No | No |
| HR | No | No |
| Finance | No | No |
| Front Office | No | No |
| Personnel | No | No |
| Audit | No | No |
| System seeders | Only local/staging/demo, never production onboarding | No production merchant creation |

## 11.2 Why the Rule Exists

This is a deliberate security and governance boundary, not a limitation. If the Super Administrator could create merchants and first admins, the platform would be exposed to tenant-ownership disputes, fraudulent merchant creation, governance overreach (creator and regulator in one), audit ambiguity, data-protection risk, and support abuse. The clean rule is: **the merchant creates itself; the platform governs after creation.**

## 11.3 Functional Registration Flow

A new merchant starts from a public registration page such as:

```text
/register
```

or:

```text
/merchant-registration/self-register
```

The registering person becomes the first Merchant Administrator. The registration transaction creates:

```text
users record for the registering person
merchants record for the business
merchant_profiles record
merchant_users membership with role merchant_admin
initial merchant_subscriptions record
trial / free-period timestamps
billing status trialing
operational status pending_setup
audit event for merchant self-registration
Magic Link verification flow
```

## 11.4 Public Registration Fields

Required:

```text
Business name
Business category (salon, barbershop, clinic, repair, spa, etc.)
Town / location
Contact phone
Merchant Admin first name
Merchant Admin last name
Merchant Admin email (login identity)
Merchant Admin phone (contact and optional M-Pesa billing phone)
Terms acceptance
Data consent
```

Optional:

```text
Referral code
Preferred plan (now or during first-time setup)
M-Pesa billing phone (can default to admin phone)
```

Preferred plan may be collected during public registration or first-time setup, but the final flow must ensure plan selection before operational dashboard access.

## 11.5 Initial Merchant State

```text
merchants.status = pending_setup
merchants.billing_status = trialing
merchant_users.role = merchant_admin
merchant_users.status = active or pending_verification
trial_started_at = merchant admin account creation timestamp
trial_ends_at = trial_started_at + configured free period days
setup_completed_at = null
created_by_registration = true
registration_source = self_registration
```

The trial starts at Merchant Administrator account creation, not at setup completion.

## 11.6 Magic Link Verification and First-Time Setup

After successful registration, Servana sends a Magic Link ("Check your email. We have sent a secure login link … Use it to activate your Merchant Administrator account."). Verification logs the Merchant Administrator in and directs them to first-time setup.

Required first-time setup steps:

```text
verify email
choose subscription plan
confirm merchant profile
create first branch
invite Branch Manager and HR users where needed
confirm business contact details
finish setup
```

After completion: `merchants.status = active`, `setup_completed_at = now()`, billing status remains `trialing` until trial expiry or subscription activation. Until setup is complete, the Merchant Administrator may access only merchant profile setup, plan selection, branch creation, initial user invitation, billing/trial information, and logout. Blocked until setup completes: queue, appointments, client operations, invoicing, merchant-client payments, receipts, reports, exports, and audit dashboards.

## 11.7 Super Administrator Post-Creation Governance

Super Administrator governance begins only after a merchant exists. The Super Administrator **can**: view registered merchants; suspend, reactivate, and deactivate merchants; view subscription status, invoices, payment attempts, and M-Pesa reconciliation exceptions; configure seeded plans, prices, limits, extra-branch charges, trial/free-period rules, and grace periods; apply free-period offers; view suspicious registrations, duplicate-business warnings, and abusive trial usage; view platform audit logs; and add governance notes.

The Super Administrator **cannot**: create a merchant; create the first Merchant Admin; assign themselves a merchant role; impersonate a Merchant Admin at launch; complete a merchant's first-time setup; create a merchant branch on behalf of a merchant; configure branch services/pricing; add operational staff directly; or run merchant operations.

## 11.8 Forbidden Super Administrator Routes

These production routes must not exist:

```text
POST /api/v1/platform/merchants
POST /api/v1/platform/merchants/{merchant}/admins
POST /api/v1/platform/merchant-admins
POST /api/v1/platform/merchant-registration
```

Guessed routes return `404 Not Found` (or a hard denial per security design). Audit event: `platform_forbidden_merchant_creation_attempt`, severity `high`. For public registration, `actor_user_id` may be null until the user is created; use `actor_role = public_registration`.

## 11.9 Allowed Super Administrator Merchant Governance Routes

```text
GET  /api/v1/platform/merchants
GET  /api/v1/platform/merchants/{merchant}
POST /api/v1/platform/merchants/{merchant}/suspend
POST /api/v1/platform/merchants/{merchant}/reactivate
POST /api/v1/platform/merchants/{merchant}/deactivate
GET  /api/v1/platform/merchants/{merchant}/billing
GET  /api/v1/platform/merchants/{merchant}/audit
GET  /api/v1/platform/merchants/{merchant}/mpesa-payments
```

These are governance routes, not creation routes.

## 11.10 Super Administrator Merchant List and Detail UX

The merchant list (Platform Admin → Merchants) shows merchants that already exist with columns: merchant name, Merchant Admin, status, billing status, plan, trial ends, created at, risk flags, and actions (view, suspend, reactivate, audit, billing details). There is **no** "Create Merchant" or "Create Merchant Admin" button. Empty-state copy:

> "Merchants appear here after they self-register. Super Administrators cannot create merchant accounts manually."

The merchant detail page shows the merchant profile summary, Merchant Admin summary, subscription/billing status, branch count, staff count, trial/free-period details, M-Pesa payment attempts, audit log, and governance actions (suspend, reactivate, deactivate, view billing, view reconciliation exceptions, apply free-period offer where policy allows, add governance note, view audit trail). Forbidden actions are absent: editing the merchant operational profile as owner, creating a branch/staff/service/invoice, creating the first Merchant Admin, or changing merchant ownership directly without a controlled transfer workflow.

A registration **monitoring** dashboard (Platform Admin → Merchant Registrations) shows metrics — new registrations today, pending-setup merchants, trialing merchants, failed verification, duplicate-business warnings, suspicious registrations, and conversion to paid — with actions to view, flag for review, suspend for fraud/security, send a support reminder, and view audit. It contains no creation action.

## 11.11 Registration Security Rules

Registration endpoint rate limit:

```text
3 attempts per hour per IP
```

Duplicate/suspicious checks:

```text
business name
slug
contact email
contact phone
Merchant Admin email
Merchant Admin phone
IP / device patterns
same phone used for many merchants
same admin email attempting many registrations
disposable email domain
high-risk IP
failed Magic Link verification patterns
duplicate business name in same town
trial abuse pattern
```

Suspicious registrations are flagged for Super Administrator review **after** creation. The Super Administrator may flag, suspend, request verification, add an internal note, or deactivate a fraudulent merchant, but still cannot manually create or replace the first Merchant Admin or complete setup. Registration and Magic Link flows avoid account enumeration except where a registering user clearly needs a meaningful validation error. The Super Administrator is never inserted into `merchant_users`, `branch_user_assignments`, or `staff_profiles`.

## 11.12 Data Model Rules

`merchants`: registration sets `status = pending_setup`, `billing_status = trialing`, `trial_started_at = merchant_admin_user.created_at`, `trial_ends_at = trial_started_at + configured_free_period_days`, `setup_completed_at = null`, `created_by_registration = true`, and `registration_source ∈ {self_registration, imported_demo, system_seeded}` (production-allowed value: `self_registration`).

`merchant_users`: the first Merchant Admin membership is created automatically with `role = merchant_admin`, `status = active or pending_verification`, `invited_by = null`, `activated_at = after Magic Link verification`. Hard rule: a merchant must have exactly one initial `merchant_admin` created by self-registration. Future additional Merchant Admins require controlled invitation or an ownership-transfer policy, never Super Admin creation.

`users`: the registering person becomes a user with `is_platform_staff = false` and receives no platform role.

## 11.13 Final Feature Definition

> **Merchant Administrator self-registration is the only merchant creation path in Servana. A new merchant tenant is created only when a public Merchant Administrator registers their own business, verifies through Magic Link, and completes first-time setup. Super Administrators do not create merchants, do not create first Merchant Administrators, and do not operate merchant setup. Super Admin governance begins only after the merchant exists, and is limited to platform oversight such as merchant review, suspension, reactivation, billing governance, registration-risk monitoring, and audit.**

---
# 12. Personnel Compensation Model Management

## 12.1 Feature Definition

**Personnel Compensation Model Management** enables the Merchant HR user to define how each merchant personnel member is paid:

```text
commission_only
salary_plus_commission
salary_only
```

The feature lives primarily in the HR module, integrates with the Commissions module, exposes payout visibility to Finance, and gives each Merchant Personnel user a private view of their own compensation, commissions, salary accruals, payout history, and earnings statements. The clean product rule is:

```text
HR defines how personnel earns.
Finance confirms what is paid.
Personnel sees what they earned.
Audit verifies what changed.
```

| Compensation model | HR configures | System calculates | Personnel sees |
| ------------------ | ------------- | ----------------- | -------------- |
| **Commission only** | Commission rule | Earned commission from validated payments | Own commission earnings and payout status |
| **Salary plus commission** | Salary + commission rule | Salary accrual + earned commission | Base salary, commission, gross earnings, payout status |
| **Salary only** | Salary terms | Salary accrual only | Salary amount, accrued salary, payout status |

## 12.2 Do Not Overload `employment_type`

`employment_type` (e.g., `full_time`, `part_time`, `contract`, `commission_only`) describes the employment relationship and must not be overloaded. A separate field is required:

```text
compensation_model:
  commission_only
  salary_plus_commission
  salary_only
```

A full-time employee may earn salary plus commission; a contractor may receive a salary-only retainer. Employment relationship and compensation model are different business concepts; combining them creates reporting, payroll, and audit confusion.

## 12.3 Commission Only

Commission-only personnel earn only commission from completed and paid service work.

```text
base salary: none
commission: yes
commission source: validated invoices / invoice items
earnings timing: commission becomes earned when payment is validated
reversal: commission reverses if invoice/payment is voided, refunded, or adjusted
personnel account view: pending, earned, reversed, and paid commission
HR setup requirement: at least one active commission rule
Finance visibility: commission liability, payout status, reversals
```

Example: a 40%-commission barber completes a KES 1,000 haircut that is invoiced and validated → commission base KES 1,000, rate 40%, personnel earning KES 400, status earned. No salary line is created.

## 12.4 Salary Plus Commission

Salary-plus-commission personnel earn a fixed salary and additional commission.

```text
base salary: yes
commission: yes
salary source: compensation plan
commission source: validated invoices / invoice items
earnings timing: salary accrues by pay period; commission earns on validated payment
reversal: salary is not reversed by invoice refund; commission can be reversed
personnel account view: base salary, commission, gross expected pay, paid/unpaid status
HR setup requirement: salary amount and commission rule
Finance visibility: salary liability plus commission liability
```

Example: monthly salary KES 20,000 + 10% on services; validated services worth KES 80,000 in June → base salary KES 20,000, commission KES 8,000, gross expected pay KES 28,000.

## 12.5 Salary Only

Salary-only personnel earn only a fixed salary; service work generates no commission.

```text
base salary: yes
commission: no
commission source: not applicable
earnings timing: salary accrues by pay period
reversal: service refunds do not affect salary
personnel account view: salary amount, current-period accrual, payout status
HR setup requirement: salary amount
Finance visibility: salary liability only
```

Example: receptionist on KES 25,000 monthly, no commission — even if they create invoices or manage appointments, no commission ledger entries are created for them.

## 12.6 HR Navigation

```text
HR
 └── Personnel
      └── Staff Profile
           └── Compensation
```

or a dedicated page:

```text
HR → Compensation
```

Recommended sub-tabs: Current Compensation, Commission Rules, Salary Terms, Payout History, Change History.

## 12.7 HR Compensation Setup Flow

**Step 1 — Select personnel.** The profile shows: name, role title, branch, employment type, employment status, service eligibility, and current compensation model. HR can manage only personnel within their branch scope unless granted merchant-wide HR authority. Merchant Admin does not directly configure compensation unless specifically granted later.

**Step 2 — Choose compensation model** (commission only / salary plus commission / salary only). The UI dynamically shows only the relevant fields.

### Step 3A — Commission-Only Configuration

| Field | Required | Notes |
| ----- | -------: | ----- |
| Commission type | Yes | Percentage or fixed amount |
| Commission value | Yes | e.g., 30% or KES 300 per service |
| Commission basis | Yes | Service price, invoice item total, paid amount, or net after discount |
| Applies to | Yes | All services, selected services, or service category |
| Applies to preferred personnel fee | Optional | Yes/No |
| Effective from | Yes | Date |
| Effective to | Optional | Null means ongoing |
| Notes | Optional | Internal HR note |

Validation: salary fields must be empty; the commission rule must be active before saving; the commission percentage cannot exceed the configured merchant/platform maximum; effective dates cannot overlap another active compensation plan for the same personnel; HR must provide a reason when changing an existing compensation model.

### Step 3B — Salary-Plus-Commission Configuration

Salary section: salary amount (required, integer minor units); currency (required, default KES); salary period (required, monthly recommended at launch); effective from (required); effective to (optional); salary payout day (optional); notes (optional).

Commission section: commission type (required); commission value (required); commission basis (required, paid amount recommended); applies to (required); applies to preferred personnel fee (optional); effective from (required, usually same as salary); effective to (optional).

Validation: salary amount > 0; commission value > 0; salary period selected; effective dates cannot overlap another active plan; any change creates compensation history; existing earned commissions are not recalculated unless HR explicitly applies a backdated correction workflow.

### Step 3C — Salary-Only Configuration

Fields: salary amount (required, integer minor units); currency (required, default KES); salary period (required, monthly recommended); effective from (required); effective to (optional); salary payout day (optional); notes (optional).

Validation: salary amount > 0; commission fields must be empty; the system must not generate commission ledger entries for this personnel; any previously active commission rule is ended, not deleted.

A clear preview is shown before submission, e.g.: "Jane Wanjiku will be paid: KES 25,000 monthly salary; 12% commission on validated service revenue; effective from 1 June 2026."

## 12.8 Compensation Approval Logic

HR may create or edit compensation plans as drafts. Sensitive changes require approval.

| Change | Approval required |
| ------ | ----------------: |
| First compensation setup | Optional |
| Salary amount increase | Yes |
| Salary amount decrease | Yes |
| Commission increase | Yes |
| Commission decrease | Yes |
| Switch salary-only → commission-only | Yes |
| Switch commission-only → salary-plus-commission | Yes |
| Backdated effective date | Yes |
| Terminating compensation | Yes |
| High-value compensation change | Yes |

Recommended approvers: salary change → Merchant Admin or Finance; commission rule change → HR lead or Merchant Admin; backdated change → Merchant Admin; high-value compensation change → Merchant Admin + Finance. Without approval, payroll fraud risk is high (estimated 45–60% in real SME operations).

## 12.9 Compensation Lifecycle Statuses

```text
draft
pending_approval
scheduled
active
expired
superseded
rejected
cancelled
```

Meaning: `draft` (HR started, not submitted); `pending_approval` (waiting for approver); `scheduled` (approved, future effective date); `active` (currently used for calculation); `expired` (end date passed); `superseded` (replaced by a newer plan); `rejected` (approver rejected); `cancelled` (draft/scheduled plan cancelled before activation).

Hard rule: there must be only **one active compensation plan per personnel per branch at a time**.

## 12.10 Commission Calculation

```text
Personnel completes a service session.
Invoice is created.
Payment is recorded (Front Office).
Finance validates the payment.
Commission ledger entry is created or moved to earned.
Personnel sees the commission in their account.
Finance sees the commission as a liability.
Refunds / voids reverse commission where applicable.
```

Commission status flow:

```text
pending → earned → paid
```

Exception states:

```text
earned → reversed
pending → cancelled
earned → disputed
```

Event triggers: service session completed (no earned commission yet); invoice created (optional pending preview); payment recorded (still not earned); **payment validated (commission becomes earned)**; invoice voided before payment (pending commission cancelled); paid invoice voided (earned commission reversed or adjustment-required); refund approved (commission reversed partially or fully); external refund finalized (commission adjustment finalized); compensation model changed (future invoices use the new model).

## 12.11 Salary Calculation

Salary is not tied to individual invoices; it accrues by pay period. Recommended launch behaviour: salary accrues monthly (shown as an estimate until Finance marks the payout paid). For example, a KES 30,000 monthly salary at mid-month shows an accrued estimate of KES 15,000.

Salary ledger states:

```text
accruing
due
approved
paid
adjusted
cancelled
```

Salary calculation rules: active salary-only plan → generate salary accrual for the period; active salary-plus-commission plan → generate salary accrual plus commission ledger; commission-only plan → no salary accrual; personnel suspended → salary accrual follows merchant policy, default pause from the suspension date; personnel terminated → salary accrual ends on the termination date; mid-month compensation change → effective-date split calculation; backdated salary change → requires a correction entry; leave/unpaid absence → future extension, launch supports manual adjustment.

## 12.12 Personnel Payout Run

Servana does not move money directly for personnel compensation. The realistic behaviour is:

```text
Servana calculates compensation.
Finance / merchant pays personnel externally.
Servana records the payout status.
```

Path: `Finance → Payouts` or `HR → Compensation → Payout Runs`. Responsibility split: HR prepares compensation data; Finance verifies and marks paid; Merchant Admin can view and approve high-value payout runs.

Payout run fields: branch; period start; period end; personnel count; salary total; commission total; adjustments; gross total; status; prepared by; submitted by; verified by; approved by; paid by; paid at; is_high_value; payment reference (external payroll/bank/mobile-money reference).

Payout run status flow (maker-checker; see §10 of the amendment block). Ordinary payout:

```text
draft → submitted → finance_verified → approved → paid
```

High-value payout (above the configurable, integer-minor-unit threshold) inserts Merchant Administrator approval:

```text
draft → submitted → finance_verified → pending_merchant_admin_approval → approved → paid
```

The full status set is `draft, submitted, finance_verified, pending_merchant_admin_approval, approved, rejected, paid, cancelled, adjusted`. The preparer/submitter (HR) must never verify, approve, or mark the same run paid; Finance verifies, approves ordinary runs, and marks paid; the Merchant Administrator approves high-value runs only and never prepares or marks paid.

Exception states:

```text
submitted → rejected
finance_verified → rejected
pending_merchant_admin_approval → rejected
draft → cancelled
submitted → cancelled
paid → adjusted
```

---
## 12.13 Personnel "My Earnings"

The Personnel account shows only the logged-in personnel member's own compensation information. No personnel user may see another personnel user's salary or commission. This aligns with Servana's own-scope personnel model (Personnel users see their own queue, appointments, sessions, commissions, and served clients only).

Personnel navigation:

```text
Personnel Dashboard
 └── My Earnings
      ├── Overview
      ├── Commission
      ├── Salary
      ├── Payouts
      └── Compensation Terms
```

Tab visibility by compensation model:

| Compensation model        | Overview | Commission tab          | Salary tab              | Payouts | Compensation Terms |
| ------------------------- | -------- | ----------------------- | ----------------------- | ------- | ------------------ |
| `commission_only`         | Shown    | Shown                   | Hidden / disabled empty | Shown   | Shown              |
| `salary_only`             | Shown    | Hidden / disabled empty | Shown                   | Shown   | Shown              |
| `salary_plus_commission`  | Shown    | Shown                   | Shown                   | Shown   | Shown              |

For salary-only personnel, the commission tab is hidden or disabled with a clear empty state. For commission-only personnel, the salary tab is hidden or disabled with a clear empty state. For salary-plus-commission personnel, both tabs are shown.

### Overview

The overview must answer four questions: How am I paid? How much have I earned this period? What is pending validation? What has been paid to me?

Example — commission-only personnel:

```text
Compensation model: Commission only
Current period: June 2026

Pending commission: KES 2,400
Earned commission: KES 8,700
Paid commission: KES 5,000
Reversed commission: KES 0

Next payout status: Not yet submitted
```

Example — salary-plus-commission personnel:

```text
Compensation model: Salary plus commission
Current period: June 2026

Base salary: KES 25,000
Accrued salary estimate: KES 15,000
Pending commission: KES 3,200
Earned commission: KES 9,800
Estimated gross earnings: KES 34,800
Paid this period: KES 0
```

Example — salary-only personnel:

```text
Compensation model: Salary only
Current period: June 2026

Base salary: KES 30,000
Accrued salary estimate: KES 18,000
Commission: Not applicable
Paid this period: KES 0
```

### Commission tab

Shows service-linked earnings. Columns: date (service or payment validation date); client (masked or visible depending on policy); service performed; invoice number; base amount (the amount commission was calculated from); rate/rule (for example, 10%); commission amount; status (pending, earned, paid, reversed); reason (reversal or adjustment reason). Personnel must not see merchant-wide revenue, other personnel earnings, finance exports, or full client lists.

### Salary tab

Appears only for `salary_only` and `salary_plus_commission`. Shows: salary amount; salary period; effective from; current period; accrued estimate; adjustments (if visible); approved payout; paid amount; payment date; status (accruing, due, approved, paid). For trust, the personnel sees the difference between estimated earnings, approved earnings, and paid earnings, which avoids disputes when not all payments are validated yet.

### Compensation Terms tab

Shows the personnel member's current pay arrangement, view-only. Examples:

Commission-only view:

```text
You are currently paid by commission only.

Commission:
- 35% on validated service invoice items
- Applies to: Haircut, Styling, Treatment
- Effective from: 1 June 2026
```

Salary-plus-commission view:

```text
You are currently paid salary plus commission.

Salary:
- KES 25,000 monthly

Commission:
- 10% on validated service invoice items
- Applies to: All eligible services
- Effective from: 1 June 2026
```

Salary-only view:

```text
You are currently paid salary only.

Salary:
- KES 30,000 monthly
- Effective from: 1 June 2026

Commission:
- Not applicable
```

Personnel cannot edit these terms; they can only view them. An optional but recommended **Acknowledge compensation terms** action records that the personnel viewed and acknowledged their current compensation model.

### Payout History tab

Shows payments marked paid by Finance. Columns: period; salary paid; commission paid; adjustments; gross paid; status (paid, adjusted, disputed); paid date; reference (external payment reference, partially masked); payslip/statement (download link if generated). Personnel can download their own earning statement, which includes: personnel name; branch; period; compensation model; salary amount; commission earned; adjustments; gross amount; payment status; payment reference; generated timestamp.

## 12.14 Earnings Query Flow

A **Raise earnings query** feature lets personnel raise a question against their own earnings without editing anything directly. Query types: `missing_commission`, `reversed_commission`, `salary_amount`, `payout_status`, `adjustment`, `other`.

Examples: "I served this client but no commission appears." / "Why was this reversed?" / "My salary amount looks wrong." / "I was paid but this says unpaid." / "Why was this deducted?"

Flow:

```text
Personnel submits query
 → HR/Finance receives task
 → HR/Finance responds
 → Query resolved or escalated
```

This reduces informal WhatsApp disputes and creates an audit trail.

## 12.15 Compensation Data Models

### `personnel_compensation_plans`

```text
id
ulid
merchant_id
branch_id
staff_profile_id
compensation_model enum:
  commission_only
  salary_plus_commission
  salary_only
salary_amount bigint nullable
salary_currency char(3) default 'KES'
salary_period enum:
  monthly
  weekly
  daily
  hourly
  per_shift
commission_rule_id nullable
effective_from date
effective_to date nullable
status enum:
  draft
  pending_approval
  scheduled
  active
  expired
  superseded
  rejected
  cancelled
created_by
approved_by nullable
approved_at nullable
rejected_by nullable
rejected_at nullable
rejection_reason nullable
change_reason text
created_at
updated_at
```

Hard rule: only one active compensation plan per `staff_profile_id` per date range (and per branch).

### `salary_ledger_entries`

```text
id
ulid
merchant_id
branch_id
staff_profile_id
compensation_plan_id
pay_period_start date
pay_period_end date
entry_type enum:
  salary_accrual
  salary_adjustment
  salary_reversal
amount bigint
currency char(3)
description
status enum:
  accruing
  due
  approved
  paid
  adjusted
  cancelled
created_by nullable
created_at
updated_at
```

### `personnel_payout_runs`

```text
id
ulid
merchant_id
branch_id
period_start date
period_end date
salary_total bigint
commission_total bigint
adjustment_total bigint
gross_total bigint
currency char(3)
status enum:
  draft
  submitted
  finance_verified
  pending_merchant_admin_approval
  approved
  rejected
  paid
  cancelled
  adjusted
is_high_value boolean
high_value_threshold_minor bigint
prepared_by
submitted_by nullable
verified_by nullable
approved_by nullable
rejected_by nullable
rejection_reason nullable
paid_by nullable
paid_at nullable
payment_reference nullable
notes nullable
created_at
updated_at
```

### `personnel_payout_items`

```text
id
ulid
merchant_id
branch_id
payout_run_id
staff_profile_id
compensation_plan_id
salary_amount bigint
commission_amount bigint
adjustment_amount bigint
gross_amount bigint
status enum:
  pending
  approved
  paid
  disputed
  adjusted
created_at
updated_at
```

### `personnel_earnings_queries`

```text
id
ulid
merchant_id
branch_id
staff_profile_id
raised_by
query_type enum:
  missing_commission
  reversed_commission
  salary_amount
  payout_status
  adjustment
  other
related_commission_ledger_id nullable
related_salary_ledger_id nullable
related_payout_item_id nullable
message text
status enum:
  open
  under_review
  resolved
  rejected
  escalated
assigned_to nullable
resolution_note nullable
resolved_by nullable
resolved_at nullable
created_at
updated_at
```

All salary and commission money values are stored as integer minor units.

## 12.16 Compensation Permissions

HR permissions:

```text
compensation.view
compensation.create
compensation.update
compensation.submit_for_approval
compensation.cancel_draft
compensation.view_history
compensation.manage_commission_terms
compensation.manage_salary_terms
compensation.payouts.view
compensation.payouts.create
compensation.payouts.update_draft
compensation.payouts.submit
compensation.payouts.cancel_draft
```

Finance permissions:

```text
compensation.payouts.view
compensation.payouts.verify
compensation.payouts.approve_standard
compensation.payouts.reject
compensation.payouts.mark_paid
compensation.adjustments.create
compensation.queries.respond
```

Merchant Admin permissions:

```text
compensation.approve_sensitive_changes
compensation.view_merchant_summary
compensation.view_branch_summary
compensation.payouts.view_merchant
compensation.payouts.approve_high_value
compensation.payouts.reject_high_value
```

Personnel permissions:

```text
own_compensation.view
own_earnings.view
own_payouts.view
own_earnings_query.create
own_earnings_statement.download
```

Audit permissions:

```text
compensation.audit.view
compensation.audit.export
```

## 12.17 Compensation API Endpoints

HR endpoints:

```text
GET    /api/v1/hr/personnel/{staff}/compensation
POST   /api/v1/hr/personnel/{staff}/compensation-plans
PATCH  /api/v1/hr/compensation-plans/{plan}
POST   /api/v1/hr/compensation-plans/{plan}/submit
POST   /api/v1/hr/compensation-plans/{plan}/approve
POST   /api/v1/hr/compensation-plans/{plan}/reject
POST   /api/v1/hr/compensation-plans/{plan}/cancel
GET    /api/v1/hr/compensation-plans/{plan}/history
```

Payout-run endpoints (policy-protected resource routes; **not** placed under a Finance-only namespace — each endpoint enforces its own role, permission, branch scope, tenant scope, maker-checker rule, and status transition; see §10.5 of the amendment block):

```text
GET    /api/v1/payout-runs
POST   /api/v1/payout-runs                       (HR creates)
GET    /api/v1/payout-runs/{run}
PATCH  /api/v1/payout-runs/{run}                 (HR updates draft)
POST   /api/v1/payout-runs/{run}/submit          (HR submits)
POST   /api/v1/payout-runs/{run}/verify          (Finance verifies)
POST   /api/v1/payout-runs/{run}/approve-standard (Finance approves ordinary)
POST   /api/v1/payout-runs/{run}/approve-high-value (Merchant Administrator approves high-value)
POST   /api/v1/payout-runs/{run}/reject          (Finance / Merchant Administrator)
POST   /api/v1/payout-runs/{run}/cancel          (HR cancels draft)
POST   /api/v1/payout-runs/{run}/mark-paid       (Finance marks paid after external payment)
```

Personnel own-account endpoints:

```text
GET    /api/v1/personnel/me/compensation
GET    /api/v1/personnel/me/earnings
GET    /api/v1/personnel/me/commissions
GET    /api/v1/personnel/me/salary
GET    /api/v1/personnel/me/payouts
GET    /api/v1/personnel/me/earning-statements/{period}
POST   /api/v1/personnel/me/earnings-queries
```

## 12.18 Compensation Audit Events

```text
compensation_plan.created
compensation_plan.updated
compensation_plan.submitted
compensation_plan.approved
compensation_plan.rejected
compensation_plan.activated
compensation_plan.superseded
compensation_plan.cancelled
salary_ledger.generated
salary_ledger.adjusted
commission_ledger.generated
commission_ledger.reversed
payout_run.created
payout_run.submitted
payout_run.approved
payout_run.rejected
payout_run.paid
personnel_earnings_query.created
personnel_earnings_query.resolved
```

Severity:

| Event                          | Severity |
| ------------------------------ | -------- |
| Compensation plan created      | Medium   |
| Salary increase/decrease       | High     |
| Commission increase/decrease   | High     |
| Backdated compensation change  | Critical |
| Payout marked paid             | High     |
| Payout adjusted after payment  | Critical |
| Personnel query raised         | Low      |
| Query rejected/resolved        | Medium   |

## 12.19 Compensation Business Rules

**Rule 1 — Compensation does not grant system access.** Changing someone from salary-only to commission-only must not change their login role, branch access, or service eligibility. Access is still controlled by `merchant_users.role`, `branch_user_assignments`, `permissions`, and `staff_profiles.employment_status`.

**Rule 2 — Salary-only personnel must not generate commission.** For `salary_only`: no commission ledger entries, no commission preview, no commission payout item.

**Rule 3 — Commission-only personnel must not generate salary accrual.** For `commission_only`: no salary ledger entries, no salary payout item unless a manual adjustment exists.

**Rule 4 — Salary-plus-commission generates both.** For `salary_plus_commission`: salary ledger plus commission ledger.

**Rule 5 — Commission is earned only after validated payment.** Do not pay commission merely because a service was completed or an invoice was issued. Correct trigger: payment validated → commission earned.

**Rule 6 — Reversals must be traceable.** Every reversal must point to its cause: `invoice_voided`, `payment_reversed`, `refund_finalized`, `manual_adjustment`, `correction`.

**Rule 7 — Backdated changes require approval.** Backdating compensation can alter liabilities; it must be approval-gated and audited.

**Rule 8 — Personnel can view but not edit.** Personnel can view own compensation, download own statements, and raise queries. Personnel cannot edit compensation, edit commission, mark payout paid, or see other personnel earnings.

## 12.20 Compensation Edge Cases

| Scenario                                            | Expected behaviour                                                                                                          |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| HR changes salary mid-month                         | System splits salary calculation by effective dates                                                                       |
| HR changes commission rate mid-period               | New rate applies only from effective date                                                                                 |
| Invoice paid after compensation rule changed        | Commission uses rule active on service/invoice date, not validation date, unless configured otherwise                     |
| Personnel transferred branch                        | Old branch compensation ends; new branch compensation must be created                                                     |
| Personnel suspended                                 | Salary accrual pauses or follows merchant-configured policy                                                                |
| Personnel terminated                                | Salary accrual ends on termination date; unpaid earned commissions remain payable                                         |
| Refund after commission paid                        | Create negative adjustment or reversal in next payout                                                                      |
| Duplicate compensation plans                        | Block overlapping active date ranges                                                                                       |
| Missing compensation model                          | HR dashboard shows warning; personnel can work, but payout calculation is incomplete                                      |
| Salary-only personnel performs service              | No commission generated                                                                                                   |
| Commission-only personnel has no validated payments | Earnings remain zero for that period                                                                                      |
| Personnel disputes payout                           | Creates earnings query, not direct edit                                                                                   |

## 12.21 Compensation UI States

Every compensation page must support: Loading; Empty; Unauthorized; No branch access; No permission; Pending approval; Rejected; Active; Scheduled; Superseded; Error; Financial period locked; Billing read-only. When merchant billing status is `read_only_grace`, HR may view historical compensation records but must not create or edit operational compensation data unless billing-recovery policy explicitly allows it.

## 12.22 Compensation Reports

HR reports: personnel compensation summary; missing compensation setup; compensation changes report; commission rule report; salary liability report.

Finance reports: payout run summary; salary liability by branch; commission liability by personnel; unpaid earned commissions; reversed commissions; compensation adjustments.

Personnel reports: my earnings statement; my commission statement; my payout history.

Audit reports: compensation change audit; backdated compensation changes; payout marked-paid audit; high-value salary changes; commission reversal audit.

## 12.23 Compensation Implementation Phases

Phase 1 — Compensation model foundation: `personnel_compensation_plans`; `compensation_model` enum; HR setup UI; personnel own compensation view; audit events.

Phase 2 — Commission integration: commission rule link; commission previews; earned commission on payment validation; commission reversal rules; personnel commission tab.

Phase 3 — Salary ledger: salary accrual ledger; salary-only and salary-plus-commission logic; salary tab for personnel; salary reports.

Phase 4 — Payout runs: payout run creation; payout item generation; approval flow; mark paid; personnel payout history; statement download.

Phase 5 — Disputes and advanced controls: personnel earnings queries; backdated correction workflow; sensitive change approval; audit dashboards; advanced exports.

## 12.24 Compensation Acceptance Criteria

The feature is complete when these pass:

1. HR can assign **commission only** to a personnel user.
2. HR can assign **salary plus commission** to a personnel user.
3. HR can assign **salary only** to a personnel user.
4. Salary-only personnel never receive commission ledger entries.
5. Commission-only personnel never receive salary ledger entries.
6. Salary-plus-commission personnel receive both salary and commission calculations.
7. Commission is earned only after Finance validates payment.
8. Refunded or voided paid invoices reverse or adjust commission.
9. Personnel can see only their own earnings.
10. Personnel cannot edit compensation terms.
11. HR cannot edit compensation outside their branch.
12. Compensation changes are audited.
13. Backdated compensation changes require approval.
14. Payouts can be marked paid without moving money through Servana.
15. Personnel can download their own earnings statement.
16. Personnel can raise an earnings query.
17. Finance can see payout liabilities.
18. Audit can review compensation changes.
19. Merchant billing read-only grace blocks new compensation mutations.
20. All salary and commission money values are stored as integer minor units.

The clean product rule: **HR defines how personnel earns. Finance confirms what is paid. Personnel sees what they earned. Audit verifies what changed.**

---
# 13. Role Boundary Corrections and Separation of Duties

This section consolidates the corrected role boundaries so that the platform enforces clean separation of duties. Every rule below must be enforced through permissions and backend policies, not only through UI hiding.

## 13.1 Merchant Administrator Is Account Owner, Not Operational Superuser

The Merchant Administrator account user is the merchant account owner and top-level account administrator. The Merchant Administrator is **not** an operational superuser.

The Merchant Administrator may manage: merchant ownership; subscription; billing plan; branches; account lifecycle; merchant-wide oversight; staff lifecycle where specifically allowed; merchant-wide reports where the plan permits; compensation visibility where permitted.

The Merchant Administrator shall not automatically receive authority to perform every operational function. Restricted operational functions remain assigned as follows: service catalogue management belongs to the Merchant Branch account user; personnel eligibility and compensation setup belong to the Merchant HR account user; payment validation belongs to the Merchant Finance account user; queue, client, appointment, session, invoice, and default payment-recording operations belong to the Merchant Front Office account user where specified; business audit review belongs to the Merchant Audit account user.

## 13.2 Merchant Administrator Compensation Visibility

The Merchant Administrator shall not be limited to viewing merchant personnel commissions only. Because merchant personnel may be paid by salary, commission, or salary plus commission, Merchant Administrator visibility shall cover personnel compensation according to each personnel member's configured compensation model.

The Merchant Administrator may view: salary-only compensation summaries; commission-only compensation summaries; salary-plus-commission compensation summaries; salary liabilities; commission liabilities; payout status; payout history; compensation approvals; compensation exceptions. Visibility remains tenant-scoped and branch-aware. The Merchant Administrator must not directly edit compensation unless explicitly granted by permission and workflow.

## 13.3 Branch Manager Owns the Service Catalogue

The Merchant Branch account user (Branch Manager) owns the service catalogue. Branch Manager may: create services; edit service names; set service prices; set service durations; manage service availability at branch level; manage the branch operating calendar; manage the branch profile; manage the branch day opening/closing workflow; submit cash-up. Branch Manager does **not** own personnel eligibility.

## 13.4 HR Owns Staff, Eligibility, and Compensation

Merchant HR owns: staff profiles; employment status; service eligibility; personnel availability/unavailability; compensation setup; commission rules; salary terms; compensation change history.

Example division of duties:

| Action                                     | Owner                        |
| ------------------------------------------ | ---------------------------- |
| Create "Haircut" service                   | Merchant Branch account user |
| Set haircut price                          | Merchant Branch account user |
| Set haircut duration                       | Merchant Branch account user |
| Assign Jane as eligible to perform Haircut | HR                           |
| Remove Jane's eligibility for Haircut      | HR                           |

The branch user defines **what services the branch offers**. HR defines **which personnel are allowed to perform those services**.

## 13.5 Front Office Owns Default Customer Payment Recording

Front Office shall be the default user that records customer payments. Finance shall validate, reject, correct, and audit payments. Finance may record payments only as an optional permission override for merchants that want Finance to handle back-office payment capture. This prevents Finance from becoming both maker and checker by default.

| Action                                          | Default owner                      |
| ----------------------------------------------- | ---------------------------------- |
| Record customer payment                         | Front Office                       |
| Validate/reject payment                         | Finance                            |
| Correct payment reference                       | Finance                            |
| Override duplicate payment reference            | Finance                            |
| Record payment as exception/back-office capture | Finance only if explicitly granted |

## 13.6 Invoice Creation Is a Front Office Function Only

Invoice creation shall be a Merchant Front Office account function only. The Merchant Branch account user may view branch invoices where permitted, but shall not create invoices.

Corrected module ownership:

| Module    | Primary users        | Secondary/read users                                               |
| --------- | -------------------- | ------------------------------------------------------------------ |
| Invoicing | Front Office creates | Finance, Merchant Admin, Branch Manager view, Personnel own, Audit |

## 13.7 Receipts Are Generated Automatically

Receipts shall be generated automatically as a service action after payment validation. There must not be a manual receipt-generation button available before validation. Correct rule: validated payment → automatic receipt generation. Receipt generation must respect: invoice status; payment validation status; duplicate receipt prevention; receipt numbering rules; audit logging; receipt reversal/reissue permissions.

## 13.8 Branch Route Scope Is Not Permission

The branch route grouping may contain endpoints for payments, refunds, disputes, receipt reversal, cash-up approval, and cash-up submission. However, the Merchant Branch account user may only access branch-management functions they are permitted to use.

Correct rule: **branch route = branch scope, not role permission. Every action still needs policy/permission enforcement.**

| Action                                            | Merchant Branch account user         |
| ------------------------------------------------- | -----------------------------------: |
| Manage branch profile / services / day operations |                                  Yes |
| Submit cash-up                                    |                                  Yes |
| Approve/reject cash-up                            |                    No — Finance only |
| Validate payments                                 |                    No — Finance only |
| Refunds/disputes                                  |                    No — Finance only |
| Reverse/reissue receipts                          | No — Finance only / permission-gated |

## 13.9 Queue and Appointment Transfer

The Merchant Branch account user shall not transfer queue entries or appointments within their branch. Queue and appointment transfer is an operational continuity function assigned only to the Merchant Front Office account user. The Merchant Branch account user may view branch queue/appointment activity where permitted, but cannot: reassign queue entries; transfer queue entries; move queue entries between personnel; reassign appointments; transfer appointments; move appointments between personnel.

HR still owns service eligibility and personnel assignment authority. Front Office owns operational continuity transfer.

| Conflict                              | Corrected rule                                                                                                                                                                     |
| ------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Operational transfer vs HR assignment | Merchant Branch account user cannot transfer queue/appointments. Transfer is for Merchant Front Office only. HR still owns service eligibility and personnel assignment authority. |

## 13.10 Finance Cannot Mutate Locked Financial Periods

Merchant Finance cannot freely edit financial records after a financial period is locked. Once a day or month is locked, the system must block: editing payments; validating late payments into the locked period; voiding invoices; adjusting paid invoices; reissuing receipts; reversing receipts; creating refunds; changing cash-up figures.

Every merchant finance mutation must call one central service, `PeriodLockService`. Period-lock checks must not be scattered across controllers. Purpose: prevent inconsistent financial history; prevent post-close tampering; preserve an audit-ready accounting state.

---

# 14. Deactivation and Deletion Terminology

Whenever the scope says "deactivate user", "delete user", or "remove user", and the intended meaning is lifecycle disabling rather than physical database deletion, the requirement is standardized as **deactivate**.

For Servana by Citrus, "delete" and "deactivate" are treated as the same functional instruction unless the scope explicitly says "hard delete from database."

Default rule:

```text
User deletion means deactivation/soft removal from active access.
Historical records remain preserved.
Audit history remains preserved.
Invoices, receipts, service sessions, payments, commissions, salary records, and payout records remain preserved.
```

The system must not hard-delete users who are referenced by operational, financial, audit, compensation, or billing records. Use soft deletion or status-based deactivation where historical integrity is required.

**Removal of obsolete precondition.** The platform must not block Merchant Admin lifecycle actions because of a non-existent old platform-fee debt rule. Any earlier logic that gated deactivation/deletion on an outstanding platform-fee balance is removed. Merchant Admin lifecycle actions (deactivating staff, deactivating branches where permitted, and similar) proceed under role/permission and tenant-scope checks only, with historical preservation applied automatically.

---

# 15. Cash-Up and Reconciliation

## 15.1 Definition

Cash-up means the end-of-day reconciliation process where a branch confirms whether the money collected matches the sales and payments recorded in Servana. In plain terms: **"Did the branch actually collect the money the system says it should have collected today?"**

## 15.2 Worked Example

Expected totals (what Servana recorded as received today):

| Payment method     | Expected amount |
| ------------------ | --------------: |
| Cash               |      KES 12,000 |
| M-Pesa             |      KES 35,000 |
| Card               |       KES 8,000 |
| Bank transfer      |       KES 5,000 |
| **Total expected** |  **KES 60,000** |

Actual totals (what the branch counted/confirmed):

| Payment method   |  Actual amount |
| ---------------- | -------------: |
| Cash counted     |     KES 11,500 |
| M-Pesa confirmed |     KES 35,000 |
| Card settlement  |      KES 8,000 |
| Bank transfer    |      KES 5,000 |
| **Actual total** | **KES 59,500** |

Difference:

```text
Expected: KES 60,000
Actual:   KES 59,500
Shortage: KES 500
```

The branch must explain the discrepancy. Example note:

```text
KES 500 cash shortage. Front desk reported one unpaid balance mistakenly marked as paid.
```

## 15.3 Responsibility Split

| User                              | Responsibility                                   |
| --------------------------------- | ------------------------------------------------ |
| **Merchant Branch account user**  | Prepares and submits the branch cash-up.         |
| **Merchant Finance account user** | Reviews, approves, or rejects the cash-up.       |
| **Merchant Admin**                | Views summaries and exceptions.                  |
| **Audit**                         | Reviews the audit trail.                         |

The contradiction historically arose because the branch route group contains both submit and approve/reject actions, but those actions belong to different roles. The corrected ownership is: submit cash-up → Merchant Branch account user; approve cash-up → Merchant Finance; reject cash-up → Merchant Finance.

## 15.4 Required Functionality

The cash-up feature must allow the Branch account user to: view the day's expected payment totals; enter actual counted/confirmed totals; compare expected versus actual; add discrepancy notes where amounts do not match; submit the cash-up to Finance; prevent silent editing after submission.

The cash-up feature must allow Finance to: review the submitted cash-up; approve cash-up; reject cash-up; request correction where permitted; lock the day after approval.

## 15.5 Why It Matters

Cash-up prevents: cash leakage (staff collected cash but did not remit all of it); fake payment recording (payment marked paid but money was not actually received); M-Pesa mismatch (wrong reference or unconfirmed transaction); end-of-day confusion (owner cannot tell whether sales and collections match); financial tampering (someone edits records after the day is closed). In Servana, cash-up is the branch day-close financial accountability process. Once a day is approved and locked, all subsequent financial mutations for that period are blocked through `PeriodLockService` (see §13.10).

---
# 16. Core Modules Required for Product Launch

This section upgrades the previous modules §5.1–5.18. The operational core (onboarding, auth, branch management, HR/staff, service catalogue, clients, appointments/walk-ins, queue, service sessions, invoices, offline payment, receipts, notifications, audit) is preserved and amended to match the corrected role boundaries (§13), the subscription-first billing engine (§6), and the new compensation, M-Pesa, and personnel-protection requirements.

## 16.1 Merchant Onboarding and First-Time Setup Module

**Purpose.** Enable Merchant Administrator self-registration, automatic merchant tenant creation, first-time merchant setup, branch setup, and initial staff invitation without Super Administrator merchant creation and without compliance/KYC approval (see §11).

**Required features:** Merchant Administrator self-registration; automatic merchant tenant creation; automatic assignment of the registering user as Merchant Owner / Merchant Administrator; merchant profile setup; business-category setup; merchant logo upload for invoices and receipts; **subscription plan selection (Starter / Growth / Pro Branch / Multi-Branch) during creation or first-time setup before operational dashboard access** (replacing service-fee-tier-first onboarding); branch creation by Merchant Administrator only; branch profile setup; initial Merchant Branch and HR user email invitations; auto-select branch where only one branch exists; Magic Link welcome email for invited users; merchant suspension and deactivation workflows; merchant status history; **trial/free-period start at Merchant Administrator account creation**; **billing status `trialing` and operational status `pending_setup` on creation**; audit logs.

**Explicit exclusions:** no Super Administrator merchant-registration workflow; no Super Administrator creation of the first Merchant Administrator; no compliance/KYC/activation-document submission; no live-operational-access block awaiting Super Administrator activation after self-registration. The **service-fee-tier selection is no longer a required onboarding field** — it applies only when percentage billing is active (§6.3).

## 16.2 Authentication and Access Control Module

**Purpose.** Secure account access and prevent unauthorized merchant or branch data exposure.

**Required features:** Magic Link login for all users; one-time-use tokens; token expiry; email verification; active invited-email verification under the correct merchant tenant; role-based access control; permission-based access control; tenant-based access control; branch-based access control; login rate limiting; session timeout; login audit logs; optional MFA for high-privilege roles.

**Universal login rule.** All users log in via Magic Link. A user must not log in merely because the email exists; login checks tenant, role, account status, suspension status, branch scope where applicable, and Magic Link validity. **Billing-suspended merchant users may log in only to billing-recovery screens** (§10.9).

## 16.3 Merchant and Branch Management Module

**Required features:** merchant profile; **merchant subscription plan and billing settings visibility** (replacing the old merchant service-fee-tier as a launch concept); branch creation by Merchant Administrator only; branch profile; branch operating hours and calendar; public-holiday exceptions; special closures; same-day emergency closure; branch status; branch service configuration and pricing (owned by Branch Manager, §13.3); branch queue configuration; branch appointment controls; branch day opening/closing; branch cash-up and reconciliation; branch invoice and receipt numbering; branch dashboard; branch reports; branch audit logs; branch closure and archival protection.

**Final production-launch Merchant Branch navigation:** Branch Overview; Branch Profile; Operating Hours; Calendar Exceptions; Services; Personnel (read-only HR-controlled visibility); Users & Access (read where permitted); Queue; Appointments; Service Sessions; Invoices (view; creation is Front Office); Payments (view; recording is Front Office); Receipts; Day Opening/Closing; Cash-Up & Reconciliation (submit only); Subscription Payment (pay from branch context, §10); Reports; Audit Logs; Settings.

## 16.4 Staff, HR, and Role Management Module

**Required features:** HR-managed staff invitation within the same branch scope; staff email invitation; resend invite; revoke invite; pending-activation visibility; staff profile creation and editing; staff deactivation (the standardized term covering delete/remove, §14); Magic Link invalidation and active-session invalidation after deactivation; role assignment; branch-scoped assignment enforcement; personnel service eligibility; availability calendar; employment status; staff roster search; staff roster export only; permission preview; role/branch/status history; **personnel compensation setup (commission/salary/compensation models, §12)**; audit logs.

**Account-creation boundary.** Merchant Administrator adds only Merchant Branch and HR user email addresses. HR adds Merchant Personnel, Front Office, Finance, and Audit staff users within the HR user's branch scope. HR does not manage staff in other branches and does not export client/payment data.

## 16.5 Service Catalogue Module

**Required features:** service creation, editing, and archiving by the Merchant Branch account user (service-catalogue ownership, §13.3); service category; price; estimated duration; eligible personnel through HR-controlled service eligibility (§13.4); branch availability; active/inactive status; discount support; preferred-personnel-fee eligibility; service-level revenue performance reporting.

**Authority rule.** The Branch Manager owns *what services the branch offers* and their pricing/duration. HR owns *which personnel may perform those services*. The Merchant Administrator does not configure services or pricing.

## 16.6 Client Records Module

**Required features:** client profile; phone number; optional email and gender; visit history; service history; assigned-personnel history; payment history; receipt history; client preferences; preferred-personnel history; notes; consent records; **same-branch duplicate-client prevention by phone number returning `409 Conflict`** (§4.6.1).

**Launch rule.** Clients are branch-scoped records only — no login, portal, Magic Link, or dashboard; the nullable `user_id` remains inactive (§4.9).

## 16.7 Appointment and Walk-In Module

**Required features:** walk-in creation; appointment creation, rescheduling, cancellation; no-show marking; client check-in; appointment-to-active-queue conversion without duplicate records; service selection; personnel assignment based on HR-controlled eligibility; preferred-personnel selection (with the configured fee, §5); appointment status history; notification triggers; conflict prevention; branch-closure protection. **Queue/appointment transfer between personnel is a Front Office function** (§4.6.2); the Branch Manager cannot transfer.

## 16.8 Queue Management Module

**Required features:** branch queue board; personnel-specific queue; next-available-personnel assignment; preferred-personnel assignment; estimated wait time; queue open/close; queue capacity; assignment mode; queue cancellation reason; no-show handling; queue reorder permission; preferred-personnel override reason; queue audit logs; reassignment when personnel becomes unavailable. **Operational queue transfer/reassignment between personnel is Front Office-only** (§4.6.2). Required queue statuses: waiting, assigned, in service, completed, cancelled, no-show.

## 16.9 Service Session Module

**Required features:** client selected; service selected; branch selected; personnel assigned; service-eligibility checked; session status; start timestamp; end timestamp; service notes; cancellation reason; invoice trigger; audit trail; double-booking prevention; duplicate-service-session prevention. Recommended statuses: draft, waiting, assigned, in progress, completed, cancelled, invoiced, paid.

## 16.10 Invoice Module

**Required features:** unique invoice number; merchant; merchant logo on all merchant-to-client invoices; branch; optional branch prefix; client; service; assigned personnel; invoice line items; service price; discount; preferred-personnel fee (separate line, §5); **percentage platform-fee effect on the merchant-client invoice only where percentage billing is active** (§6.3); final invoice amount; payment status; created by; timestamp; void workflow; adjustment-approval workflow; audit log.

**Ownership rule.** **Invoice creation is a Merchant Front Office function only** (§13.6). The Branch Manager may view branch invoices where permitted but shall not create invoices.

**Numbering rule.** Merchant-wide uniqueness with optional branch prefix; no duplicate invoice numbers at database level; voided invoices keep their number.

## 16.11 Offline Payment Recording and Validation Module

**Required features:** payment method; payment amount; payment reference; payment date/time; payment note; recorded by; validated by; validation status; split-payment support; partial-payment support; multiple payment records per invoice; payment-leg validation; duplicate payment-reference detection; method-specific reference rules; external refund record; payment dispute flag.

**Ownership rule.** **Front Office is the default recorder of customer payments; Finance validates, rejects, corrects, and audits** (§13.5). Finance may record payments only as an optional permission override. Recommended payment statuses: unpaid, partially paid, paid, pending validation, validated, partially validated, rejected, correction requested, voided, refunded externally, disputed.

**Scope note.** This module concerns **merchant-client service payments**, which remain offline/off-platform and recorded only. It does **not** apply to merchant subscription payments owed to Servana/Citrus, which are paid through M-Pesa and auto-validated (§10).

## 16.12 Receipt Module

**Required features:** receipt number; merchant logo on all receipts; linked invoice; linked payment record; payment method; validated paid amount only; issued by; issued timestamp; downloadable receipt; receipt reversal without deletion; receipt reissue referencing the original receipt; receipt download log; receipt audit log.

**Critical rule.** A receipt must not be generated before payment is validated. Receipts are generated **automatically** as a service action after payment validation; there is no manual pre-validation receipt button (§13.7).

## 16.13 Subscription-First Billing Engine (replaces the Citrus Billing Engine)

**Purpose.** The billing engine generates and manages what merchants owe Citrus Labs Limited under a **subscription-first** model with configurable billing modes (§6).

**Required features:** configurable billing modes (`fixed_amount`, `percentage_on_merchant_client_invoice`, `fixed_amount_plus_percentage`), all three launch-active and Super-Administrator-selectable; plans and entitlements (§7); billing periods (weekly, bi-weekly, monthly, quarterly, annual); subscription invoice generation; **M-Pesa subscription payment and automated validation** (§10); billing recovery; promotional discounts and free-period offers (§8); the trial → read-only-grace → suspended-billing lifecycle (§9); the shared overdue escalation engine (§6.7); extra-branch charges; SMS/add-on billing; overpayment credit; **launch-active percentage platform-fee engine** with the full fee lifecycle, ledger, adjustments, and disputes of §6A (service-fee-tiers `customer_centric`, `shared`, `business_centric` active only when a percentage component is active); platform billing settings (§6.6) and versioned plan prices (§6B); billing audit logs; the merchant-facing subscription/billing dashboard; billing dispute/exception handling via Super Admin reconciliation.

**Separation rule.** `merchants.status` (operational) and `merchants.billing_status` (billing) remain separate columns and are never collapsed (§9.1). The old default that validated merchant-client invoices automatically accrue an active platform fee is removed; percentage fees apply only when configured.

## 16.14 Personnel Compensation Management (replaces Commission Tracking)

**Purpose.** Expand commission tracking into full **Personnel Compensation Model Management** (§12), covering commission-only, salary-plus-commission, and salary-only models.

**Required features:** compensation models and lifecycle; commission rules (per personnel, role, service; optional branch-level; cumulative/all-personnel/individualized; fixed or percentage); **commission earned only after validated payment**; commission statuses (pending, earned, paid; reversed/cancelled/disputed); reversal on voided invoice or external refund; salary ledger and accrual; payout runs (HR prepares, Finance verifies/marks paid, Merchant Admin approves high-value); personnel earnings queries; personnel earnings statements; preferred-personnel-fee inclusion decided by HR; compensation audit trail. **Merchant Administrator can view personnel compensation by configured model — salary, commission, or salary-plus-commission** (§13.2).

**Authority rule.** Personnel compensation is set by HR, not by the Merchant Administrator and not by Finance (Finance verifies/pays; Audit reviews).

## 16.15 Client Data Protection and Contact Access Control Module (strengthened)

**Required features:** personnel can view only allowed personally served clients; **no Merchant Personnel contact export field, endpoint, UI, permission, or database flag — permanently** (§4.7.1); sensitive client contact/payment data masking where permission does not allow full visibility; field-level masking at read time (§4.8.2); unauthorized contact-access attempt logging; consent record tracking; signed URLs for any non-personnel export explicitly permitted by policy; audit log for any contact-access or legally permitted export event; **export-shaped personnel routes return `404 Not Found` and create an unauthorized-access audit event**; **in-platform bulk SMS to a personnel member's own served-client list, billed to the branch** (§4.7.2), with no raw contact export.

**Removed launch capability.** The earlier Personnel Client Contact Download capability is permanently removed from the Merchant Personnel account.

## 16.16 Reports and Dashboards Module (expanded)

Per-role dashboards each have a landing page and get-started page (§3). The module preserves the existing dashboards and adds subscription, M-Pesa, compensation, and multi-branch surfaces.

**Super Administrator dashboard:** total/active/suspended merchants; total branches; invoice volume; gross invoice value; **subscription revenue; trial conversion; plan distribution; billing-mode usage; M-Pesa payment attempts; reconciliation exceptions; promotional-discount usage; extra-branch charges; SMS charges**; preferred-personnel-fee totals; overdue merchants; audit alerts.

**Merchant Administrator dashboard:** today/weekly/monthly sales; branch performance and revenue (today, this week, last month, last 3 months); service revenue per branch; personnel performance per branch and per staff; services completed; clients served; repeat clients; invoices; payment methods; **subscription plan, billing status, next invoice amount/date, outstanding invoices, trial/grace status, scheduled plan/price changes, promotional discounts, billing credits**; **personnel compensation summaries by model (salary/commission/both), salary and commission liabilities, payout status/history**; preferred-personnel demand; daily branch day-close and cash-up PDFs.

**Merchant Branch dashboard:** today's appointments/walk-ins; active queue; clients waiting/in service; completed sessions; unpaid invoices; pending payment validations; receipts issued; today's revenue; payment-method breakdown; personnel currently active; queue delays; no-shows; cancelled sessions; staff performance for the specific branch only; **branch subscription-payment notice where due**.

**Merchant Finance dashboard:** pending payment validations; paid/unpaid/partial/split invoices; receipts issued; voided invoices; outstanding balances; commission and salary liabilities; disputes; external refunds; cash-up reviews; financial periods; **payout runs; subscription billing and M-Pesa payment attempts**; audit activity; finance notifications.

**Merchant Front Office dashboard:** next client; waiting; in service; completed today; pending commission; preferred requests; today's appointments; walk-ins; active queue; clients waiting/in service; paid/unpaid invoices; payments pending validation; receipts issued; **simplified subscription-payment banner where due**.

**Merchant Personnel dashboard:** assigned clients; own queue; own appointments; clients served; services completed; **My Earnings (compensation model, commission, salary where applicable, payouts, compensation terms, earnings queries)**; preferred-personnel requests; clients who specifically requested them; estimated wait order; service requested; request state.

**Merchant Audit dashboard (branch-scoped):** high-risk events; recent activity; flagged items; payment issues; role changes; contact-access/export attempts; preferred-personnel overrides; **compensation/salary/commission/payout audit events**.

**Multi-Branch (Multi-Branch plan):** centralized operational dashboard; branch comparison (sales, appointments, queue, personnel output); consolidated reports; branch-by-branch cash-up status.

## 16.17 Notifications Module

**Required notification types:** Magic Link login email; staff welcome/activation/invitation/resend/revocation emails; appointment confirmation/cancellation; queue update; preferred-personnel wait confirmation; payment validation notice; receipt availability; merchant suspension warning; **subscription invoice issued/overdue; trial-expiry and read-only-grace notices; M-Pesa payment success/failure/reconciliation; billing reactivation**; duplicate payment-reference alert; cash-up submitted for review; branch cash-up discrepancy; refund-approval requested; invoice-void-approval requested; financial-period-ready-for-lock; **compensation approval requests; payout-run submitted/marked-paid; personnel earnings-query updates; SMS cost confirmation**. Launch channels: email required; SMS/WhatsApp recommended (SMS is also the billed bulk-SMS channel, §4.7.2).

## 16.18 Audit Logging Module

Audit logs capture all previously listed events plus the new subscription, M-Pesa, compensation, registration-governance, and personnel-protection events defined throughout this document (see §10.16, §11 audit events, §12.18, and the per-role audit subsections). Audit records include:

```text
actor_user_id
actor_role
merchant_id
branch_id
action
target_entity_type
target_entity_id
old_values
new_values
severity
event_status
ip_address
user_agent
record_hash
previous_record_hash
created_at
```

Sensitive changes must include before-and-after values. Audit records are append-only and tamper-evident (hash/chained hash). Merchant Audit reads are branch-scoped with field-level masking (§4.8).

---
# 17. Recommended Technical Architecture

Required stack:

| Layer | Required Technology |
| ----- | ------------------- |
| Backend | Laravel |
| Backend Language | PHP 8.2+ |
| Frontend | Vue.js or React.js |
| Frontend Language | TypeScript preferred |
| Styling | Tailwind CSS or Bootstrap 5 |
| Database | PostgreSQL preferred; MySQL acceptable |
| Authentication | Laravel Sanctum for SPA/API auth |
| API Style | REST by default |
| Build Tooling | Vite |
| Queues | Redis-backed Laravel Queues |
| Cache | Redis |
| Storage | S3-compatible object storage |
| Search | Meilisearch, Typesense, or Elasticsearch |
| Deployment | Dockerized deployment with CI/CD |

High-level architecture:

```text
Browser / Client UI
        ↓
Vue.js or React.js SPA
        ↓
Laravel Web/API Layer
        ↓
Magic Link Authentication
        ↓
RBAC + Permission + Tenant + Branch Authorization
        ↓
Billing/Plan/Period Enforcement Layer
  - EnsureMerchantBillingAllowsMutation
  - EnsurePlanEntitlement
  - EnsureBranchLimitNotExceeded
  - EnsureStaffLimitNotExceeded
  - PeriodLockService
        ↓
Domain Services
  - Merchant Onboarding & Self-Registration
  - Branch Management
  - HR, Staff Access & Compensation
  - Service Catalogue
  - Queue Management
  - Appointments
  - Service Sessions
  - Invoicing
  - Offline Merchant-Client Payment Recording
  - Receipts
  - Subscription-First Billing Engine
  - M-Pesa Subscription Payment & Reconciliation
  - Personnel Compensation & Payout Runs
  - Promotions & Free-Period Offers
  - Client Data Protection, Masking & Bulk SMS
  - Reports
  - Audit Logs
        ↓
PostgreSQL / MySQL
        ↓
Redis Queues / Cache
        ↓
Email, SMS Gateway, M-Pesa Daraja Integration, Storage, Monitoring, Error Tracking
```

The architecture adds, relative to the previous version, a dedicated billing/plan/period enforcement layer between authorization and domain services, plus M-Pesa Daraja integration and an SMS gateway as external dependencies.

---

# 18. Multi-Tenant Data Model

The model preserves the previous core entities and adds the subscription-billing, M-Pesa, promotion, and compensation tables.

## 18.1 Preserved Core Entities

| Entity | Purpose |
| ------ | ------- |
| `users` | Global identity records. |
| `merchants` | Merchant tenants created through Merchant Administrator self-registration. Holds separate `status` (operational) and `billing_status` columns. |
| `merchant_profiles` | Merchant profile, logo, business category, and owner-managed profile data. |
| `merchant_branches` | Branches under merchants. |
| `branch_operating_hours` | Weekly operating hours. |
| `branch_calendar_exceptions` | Public-holiday exceptions, special closures, break periods, emergency closures. |
| `branch_day_records` | Branch opening, paused, closing, reopening, and day-close records. |
| `branch_cash_ups` | Daily branch cash-up and reconciliation submissions. |
| `merchant_users` | User-to-merchant-role mapping. |
| `branch_user_assignments` | Branch-scoped user access records. |
| `staff_profiles` | Staff identity, employment data, account status, role, branch, phone, email, start date, inviter. |
| `staff_invitations` | Invite, resend, revoke, pending activation, and welcome-email tracking. |
| `roles` | Role records. |
| `permissions` | Permission records. |
| `role_permission_assignments` | Permission mapping per role. |
| `magic_login_tokens` | Magic Link tokens. |
| `services` | Branch-controlled service catalogue. |
| `service_categories` | Service grouping. |
| `personnel_service_eligibilities` | Which personnel can perform which services. |
| `personnel_availability_schedules` | Working days, shifts, breaks, unavailable times, emergency unavailability. |
| `clients` | Branch-scoped client records; nullable `user_id` (inactive at launch). |
| `appointments` | Scheduled service bookings. |
| `queue_entries` | Walk-in and queue records. |
| `service_sessions` | Actual service delivery. |
| `preferred_personnel_fee_rules` | Super Administrator preferred-personnel fee configuration. |
| `invoices` | Invoice headers (merchant-client). |
| `invoice_items` | Invoice line items. |
| `invoice_number_sequences` | Merchant-wide unique numbering with optional branch prefix. |
| `payment_records` | Offline merchant-client payment records. |
| `payment_validation_events` | Validation status history, validator, notes, rejection reason. |
| `payment_reference_checks` | Duplicate reference check records and overrides. |
| `receipts` | Receipt records. |
| `receipt_number_sequences` | Merchant-wide unique receipt numbering with optional branch prefix. |
| `receipt_reissues` | Receipt reissue tracking referencing original receipts. |
| `external_refunds` | External refund records and approvals. |
| `finance_disputes` | Payment and finance disputes. |
| `commission_rules` | HR-controlled commission configuration. |
| `commission_ledger` | Personnel commission records. |
| `finance_exports` | Finance export requests, signed-URL expiry, download count, audit metadata. |
| `notification_logs` | Notification tracking. |
| `audit_logs` | Immutable sensitive-activity records with tamper-evident hash fields. |
| `flagged_audit_events` | Audit event review status and resolution workflow. |

## 18.2 New / Amended Billing, Subscription, and Promotion Entities

| Entity | Purpose |
| ------ | ------- |
| `platform_billing_settings` | Configurable billing mode, currency, free-period/grace/overdue/suspension windows, fee toggles and rates, extra-branch charge bounds, preferred-personnel-fee settings, and engine toggles (§6.6). All values seeded but editable; every change audited. |
| `subscription_plans` | Plan identity, positioning, and **non-price** metadata only (Starter, Growth, Pro Branch, Multi-Branch): name, relative-tier metadata, included branch/staff/personnel/service limits, extra-branch charge. **Monetary prices are NOT stored here** (see `subscription_plan_prices`). |
| `subscription_plan_prices` | **Authoritative, versioned monetary price** per plan and billing period (§18.2b): plan_id, billing_period, amount_minor, currency, effective_from/to, status (draft/scheduled/active/expired/cancelled), created_by, approved_by, change_reason. One active price per plan+period+currency+instant; no overlaps; integer minor units; issued invoices keep captured price. This is the single plan-price source of truth (C2). |
| `plan_entitlements` | Entitlement flags/limits per plan (§7.3), enforced server-side. |
| `merchant_subscriptions` | Per-merchant subscription: current plan, billing period, status (active, trialing, etc.), cycle dates, scheduled change reference. |
| `scheduled_plan_changes` | A merchant changing from one **plan or billing period** to another, effective next cycle (no proration, §6.8). **Not used for plan-price changes** — those are versioned `subscription_plan_prices` records (C9). |
| `subscription_invoices` | Subscription invoice headers: amount, currency, due date, status (draft, issued, pending_payment, partially_paid, paid, overdue, cancelled, payment_failed, reconciliation_required), paid amount. |
| `subscription_invoice_items` | Line items: plan charge, extra-branch charges, SMS/add-on charges, discounts, credits. |
| `subscription_payments` | M-Pesa subscription payments (§10.l): method, status, M-Pesa identifiers (checkout/merchant request IDs, unique receipt number, phone, account reference, transaction date), failure code/reason, initiated_by user/role/branch, validated_at, applied_at, masked/encrypted raw callback. |
| `subscription_payment_attempts` | Per-attempt audit: initiated_by, phone, amount, channel, status, idempotency_key, locked_until, IP, user agent. |
| `mpesa_reconciliation_events` | Reconciliation outcomes: receipt number, amount, phone, account reference, status (matched, unmatched, duplicate, overpayment, underpayment, suspicious), resolver, resolution note. |
| `subscription_invoice_payment_locks` | Short-lived payment locks (subscription_invoice_id, locked_by_user_id, locked_until, status) to prevent double payment. |
| `merchant_billing_credits` | Overpayment/credit balances applied to future subscription invoices. |
| `promotional_discounts` | Super Admin promotions (§8.1): name, discount_type (percentage/fixed_amount), discount_value, currency, starts_at, ends_at, **structured `target_scope` enum** (all_merchants, new_merchants, selected_merchants, specific_merchant, fixed_amount_billing_merchants, percentage_billing_merchants, fixed_plus_percentage_billing_merchants, selected_plans), billing_mode_filter, new_merchant_only, plan_filter, status, created/approved by, change_reason. **No generic unvalidated scope string** (C8). |
| `promotional_discount_targets` | Explicit per-target rows (§18.2c): promotional_discount_id, merchant_id (the billing target), merchant_admin_user_id (selection evidence only), plan_id, billing_mode, selection_snapshot_json, selected_at, selected_by. The discount applies to the merchant billing account, not to individual payers. |
| `platform_fee_ledger_entries` | Launch-active percentage-fee ledger (§6A / §18.2d): merchant/branch, source merchant_client_invoice(_item)_id, billing_mode_snapshot, service_fee_tier_snapshot, fee_basis_type, fee_basis_amount_minor, percentage_rate_snapshot, gross_platform_fee_minor, client_shifted_amount_minor, merchant_liability_minor, currency, status, effective_configuration_id, created_at, billable_at, aggregated_billing_invoice_id, reversed_entry_id, adjustment_reason. Entries are never silently edited or deleted. |
| `platform_fee_adjustments` | Reversals/adjustments traceable to a cause (§6A): original_platform_fee_entry_id, adjustment_type, adjustment_amount_minor, reason, source_refund_id, source_void_id, created_by, approved_by, created_at. |
| `platform_fee_disputes` | Merchant percentage-fee disputes (§6A): disputed entry, reason, evidence, assigned reviewer, status (open/under_review/resolved/rejected/escalated), resolution, timestamps. |
| `billing_invoice_lines` | Servana billing-invoice line items distinguishing subscription_plan_charge, percentage_platform_fee, fixed_platform_fee, extra_branch_charge, sms_charge, other_approved_add_on, discount, credit, adjustment (§6A). |
| `billing_reconciliation_records` | M-Pesa reconciliation of merchant-to-Servana billing invoices (subscription and aggregated/standalone platform-fee invoices). |
| `free_period_offers` | Configurable free-period offers (§8.2): free_period_days, scope, targets, dates, status. |
| `billing_escalation_events` | Shared overdue-engine events (§6.7): grace_days, reminder_days, suspension_after_days, invoice_type, status change, audit reason, notification_sent_at. |

## 18.3 New Compensation Entities

| Entity | Purpose |
| ------ | ------- |
| `personnel_compensation_plans` | Compensation model per personnel (§12.15): commission_only / salary_plus_commission / salary_only, salary amount/currency/period, commission_rule_id, effective dates, lifecycle status, approval metadata, change reason. One active plan per personnel per branch. |
| `salary_ledger_entries` | Salary accrual/adjustment/reversal entries with pay-period bounds, amount, status. |
| `personnel_payout_runs` | Payout-run headers: period, salary/commission/adjustment/gross totals, status, prepared/submitted/approved/paid-by, payment reference. |
| `personnel_payout_items` | Per-personnel payout lines: salary/commission/adjustment/gross amounts, status. |
| `personnel_earnings_queries` | Personnel earnings queries: type, related ledger/payout references, message, status, assignment, resolution. |

## 18.4 Tenant-Scoped Columns and Constraints

Required tenant-scoped columns on tenant-owned tables:

```text
id
uuid_or_ulid
merchant_id
branch_id
created_by
updated_by
created_at
updated_at
deleted_at
```

Required constraints: unique active staff email across the platform; unique active staff phone across the platform; unique client identity within the same branch by phone (same branch + same phone blocked, §4.6.1); unique invoice number at database level; unique receipt number at database level; **unique `mpesa_receipt_number` on `subscription_payments`** (duplicate-callback protection); branch closure blocked where active operational/financial records exist; payment-reference duplicate detection by merchant and branch; server-side tenant and branch authorization on every tenant-owned resource; **one active `personnel_compensation_plans` row per `staff_profile_id` per date range**; **all money values stored as integer minor units**; Merchant Personnel contact export disabled at schema/API/UI level permanently; audit records append-only with record hash or chained hash; `merchants.status` and `merchants.billing_status` kept as separate columns.

---

# 19. Required Backend Enforcement Layers

All enforcement is server-side; frontend hiding is for UX only and is never the sole control. The platform enforces role/permission checks, plan-entitlement checks, billing-status checks, tenant-isolation checks, branch-scope checks, own-scope checks, period-lock checks, field-level masking, financial-mutation guards, the billing-recovery route allowlist, the read-only-grace mutation blocker, and the suspended-billing recovery allowlist.

Required middleware/services:

| Layer | Responsibility |
| ----- | -------------- |
| `EnsureMerchantBillingAllowsMutation` | Blocks mutating endpoints when `billing_status` is `read_only_grace` or `suspended_billing`, except endpoints on the billing/subscription-recovery allowlist (§9.4, §10.9). |
| `EnsurePlanEntitlement` | Verifies the merchant's plan includes the requested feature/entitlement (§7.3). |
| `EnsureBranchLimitNotExceeded` | Blocks branch creation beyond the plan's branch entitlement; triggers extra-branch billing where the plan allows paid extras. |
| `EnsureStaffLimitNotExceeded` | Blocks staff-user creation beyond the plan's staff entitlement. |
| `EnsureTenantIsolation` | Authorizes every tenant-owned resource by `merchant_id`; prevents cross-tenant access. |
| `EnsureBranchScope` | Authorizes every branch-owned resource by `branch_id`; prevents cross-branch access (Branch, HR, Finance, Front Office, Audit, Personnel). |
| `EnsurePersonnelOwnScope` | Restricts Personnel reads/writes to their own records across queue, appointments, sessions, commissions, salary, payouts, served clients, messages, and statements (§4.7). |
| `EnsureAuditReadMasking` | Applies field-level masking at response time for Merchant Audit and other under-permissioned readers (§4.8.2). |
| `PeriodLockService` | Single central service all finance mutations call; blocks edits to locked financial periods (§13.10). |
| `BillingRecoveryAccessService` | Defines and enforces the allowlist of routes accessible during read-only grace and billing suspension. |
| `MpesaSubscriptionPaymentService` | Initiates STK Push / handles PayBill-Till, validates callbacks, applies payments, manages locks/idempotency, records attempts, and triggers reactivation (§10.8, §10.10). |
| `SubscriptionInvoiceReconciliationService` | Matches M-Pesa payments to invoices, records reconciliation events, handles overpayment credit, and raises exceptions for Super Admin (§10.11, §10.14). |

---

# 20. API Route Structure

All API routes use `/api/v1`. The previous route groups are preserved and amended; new billing, subscription, recovery, webhook, platform-billing, and compensation groups are added.

## 20.1 Preserved and Amended Groups

```text
/api/v1/auth
/api/v1/auth/magic-link
/api/v1/auth/magic-link/verify
/api/v1/me
/api/v1/platform
/api/v1/platform/settings
/api/v1/platform/billing/settings
/api/v1/platform/merchants
/api/v1/platform/audit-logs
/api/v1/merchant-registration/self-register
/api/v1/merchant-registration/first-time-setup
/api/v1/merchants/profile
/api/v1/branches/profile
/api/v1/branches/operating-hours
/api/v1/branches/calendar-exceptions
/api/v1/branches/day-opening-closing
/api/v1/branches/cash-up
/api/v1/branches/reports
/api/v1/merchant-users
/api/v1/hr/staff
/api/v1/hr/staff-invitations
/api/v1/hr/service-eligibility
/api/v1/hr/availability
/api/v1/services
/api/v1/clients
/api/v1/appointments
/api/v1/queue
/api/v1/service-sessions
/api/v1/invoices            (creation = Front Office only)
/api/v1/invoices/voids
/api/v1/invoices/adjustments
/api/v1/payments            (recording = Front Office default; validation = Finance)
/api/v1/payments/validation
/api/v1/payments/duplicates
/api/v1/payments/partial-split
/api/v1/receipts
/api/v1/receipts/reissue
/api/v1/refunds
/api/v1/disputes
/api/v1/reports
/api/v1/finance/exports
/api/v1/finance/audit
/api/v1/audit-logs
/api/v1/audit-logs/flagged-events
/api/v1/notifications
```

The old `/api/v1/merchants/service-fee-tier`, `/api/v1/billing/platform-fees`, and `/api/v1/billing/platform-fee-disputes` routes are superseded by the subscription billing groups below; percentage-mode fee configuration lives under `/api/v1/platform/billing/settings`.

## 20.2 Subscription Billing and Recovery (Merchant-Side)

```text
GET    /api/v1/billing/subscription
GET    /api/v1/billing/subscription-invoices
GET    /api/v1/billing/subscription-invoices/{invoice}
POST   /api/v1/billing/subscription-invoices/{invoice}/mpesa-payments
GET    /api/v1/billing/subscription-invoices/{invoice}/payment-status
GET    /api/v1/billing/subscription-invoices/{invoice}/payment-attempts
GET    /api/v1/billing/subscription-invoices/{invoice}/download
POST   /api/v1/billing/subscription/plan-change          (scheduled, no proration)
```

Suspended-recovery endpoints (must remain accessible during billing suspension):

```text
GET    /api/v1/billing/recovery
GET    /api/v1/billing/recovery/invoice
POST   /api/v1/billing/recovery/invoice/mpesa-payments
GET    /api/v1/billing/recovery/payment-status
```

## 20.3 M-Pesa Webhooks (No Merchant Session; M-Pesa Security Validation)

```text
POST   /api/v1/webhooks/mpesa/stk-callback
POST   /api/v1/webhooks/mpesa/c2b-confirmation
POST   /api/v1/webhooks/mpesa/c2b-validation
```

These endpoints must not require a merchant user session and must pass M-Pesa/security validation. (There is no merchant-client service-payment webhook — merchant-client payments remain offline-recorded.)

## 20.4 Platform-Side Billing and Governance

```text
GET    /api/v1/platform/billing/mpesa-payments
GET    /api/v1/platform/billing/reconciliation-exceptions
POST   /api/v1/platform/billing/reconciliation-exceptions/{event}/resolve
GET    /api/v1/platform/merchants/{merchant}/subscription-payments
GET    /api/v1/platform/merchants
GET    /api/v1/platform/merchants/{merchant}
POST   /api/v1/platform/merchants/{merchant}/suspend
POST   /api/v1/platform/merchants/{merchant}/reactivate
POST   /api/v1/platform/merchants/{merchant}/deactivate
GET    /api/v1/platform/merchants/{merchant}/billing
GET    /api/v1/platform/merchants/{merchant}/audit
GET    /api/v1/platform/merchants/{merchant}/mpesa-payments
GET    /api/v1/platform/promotions
POST   /api/v1/platform/promotions
GET    /api/v1/platform/free-period-offers
POST   /api/v1/platform/free-period-offers
GET    /api/v1/platform/preferred-personnel-fee-rules
POST   /api/v1/platform/preferred-personnel-fee-rules
```

**Forbidden platform routes (must not exist in production; guessed calls return `404 Not Found` + audit `platform_forbidden_merchant_creation_attempt`, severity high):**

```text
POST /api/v1/platform/merchants
POST /api/v1/platform/merchants/{merchant}/admins
POST /api/v1/platform/merchant-admins
POST /api/v1/platform/merchant-registration
```

## 20.5 Compensation Routes

HR:

```text
GET    /api/v1/hr/personnel/{staff}/compensation
POST   /api/v1/hr/personnel/{staff}/compensation-plans
PATCH  /api/v1/hr/compensation-plans/{plan}
POST   /api/v1/hr/compensation-plans/{plan}/submit
POST   /api/v1/hr/compensation-plans/{plan}/approve
POST   /api/v1/hr/compensation-plans/{plan}/reject
POST   /api/v1/hr/compensation-plans/{plan}/cancel
GET    /api/v1/hr/compensation-plans/{plan}/history
```

Payout runs (neutral, policy-protected resource routes — see §10.5 of the amendment block):

```text
GET    /api/v1/payout-runs
POST   /api/v1/payout-runs
GET    /api/v1/payout-runs/{run}
PATCH  /api/v1/payout-runs/{run}
POST   /api/v1/payout-runs/{run}/submit
POST   /api/v1/payout-runs/{run}/verify
POST   /api/v1/payout-runs/{run}/approve-standard
POST   /api/v1/payout-runs/{run}/approve-high-value
POST   /api/v1/payout-runs/{run}/reject
POST   /api/v1/payout-runs/{run}/cancel
POST   /api/v1/payout-runs/{run}/mark-paid
```

Personnel own-account:

```text
GET    /api/v1/personnel/me/compensation
GET    /api/v1/personnel/me/earnings
GET    /api/v1/personnel/me/commissions
GET    /api/v1/personnel/me/salary
GET    /api/v1/personnel/me/payouts
GET    /api/v1/personnel/me/earning-statements/{period}
POST   /api/v1/personnel/me/earnings-queries
GET    /api/v1/personnel/me/served-clients
POST   /api/v1/personnel/me/served-clients/bulk-sms
```

## 20.6 API Rules

Authenticate protected routes; authorize every tenant-owned and branch-owned resource server-side; use UUIDs/ULIDs externally; validate every request; rate-limit sensitive endpoints (registration 3/hour/IP, §11.11); paginate large responses; return consistent JSON; never expose internal IDs unnecessarily; never rely on frontend authorization; enforce same-branch access per permitted scope; enforce Personnel own-scope; **block all Merchant Personnel contact-export endpoints permanently (return `404` + audit)**; **block mutating endpoints during read-only grace and billing suspension except the recovery allowlist**; **require M-Pesa security validation on webhook routes**; log unauthorized-access attempts.

---
# 21. Frontend Structure and Required Screens

## 21.1 Recommended Structure

```text
src/
  layouts/
    AuthLayout
    PlatformAdminLayout
    MerchantLayout
    BranchLayout
    FrontOfficeLayout
    PersonnelLayout
    FinanceLayout
    AuditLayout

  pages/
    auth/
    platform/
    merchant/
    branch/
    hr/
    finance/
    front-office/
    personnel/
    audit/

  components/
    forms/
    tables/
    modals/
    dashboards/
    queue/
    appointments/
    invoices/
    receipts/
    reports/
    cash-up/
    billing/
    subscription/
    compensation/
    payouts/
    sms/
    audit/

  services/
    apiClient.ts
    authService.ts
    tenantContext.ts
    permissionService.ts
    billingContext.ts
    entitlementService.ts
```

Relative to the previous structure, new component groups (`billing/`, `subscription/`, `compensation/`, `payouts/`, `sms/`) and new services (`billingContext.ts`, `entitlementService.ts`) are added to support subscription billing, M-Pesa recovery, compensation, and entitlement gating in the UI.

## 21.2 Required Screens (New and Upgraded)

Every role has a **landing page** and a **get-started page** (§3). Beyond the existing operational screens, the following are required:

Merchant Administrator: plan selection during registration/first-time setup; billing dashboard; landing-page pricing-change notice (real-time); trial countdown; read-only-grace banner; subscription plan-change screen (scheduled, no proration); M-Pesa payment modal; personnel-compensation summary views (by model).

Billing recovery: billing-recovery screen; suspended-account recovery screen.

Branch Manager: subscription-payment notice (pay from branch context); service-catalogue management; cash-up submission.

Finance: subscription-payment attempts view; payout-runs screens; cash-up review/approve; period-lock management.

Front Office: simplified subscription-payment banner; client creation with duplicate-phone `409` handling; invoice creation; queue/appointment transfer.

Super Administrator: billing-settings screen; plan-configuration screen; promotional-discount screen; free-period-offer configuration screen; M-Pesa reconciliation-exceptions screen; preferred-personnel-fee-rules screen; merchant list/detail/registration-monitoring (no create-merchant button).

HR: compensation list; compensation detail; compensation setup modal; payout-run preparation.

Personnel: My Earnings (Overview / Commission / Salary / Payouts / Compensation Terms, with tabs hidden per compensation model); earnings-query screen; served-clients screen; SMS composer with cost-notice confirmation.

Audit: branch-scoped salary/commission/compensation audit views; audit export screen (permission-gated, reason-required, masked).

---

# 22. Modern UX Methodology and Interaction Design

The user experience must be modern and follow best-case methodology throughout. The platform applies the following standards consistently across every role surface.

**Per-role landing and get-started.** Every account user has a dedicated landing page (a role-appropriate dashboard answering "what needs my attention now") and a get-started page (a guided, progress-tracked checklist of first actions, §3). Completed steps persist and the get-started surface recedes as setup completes.

**Perceived performance.** Skeleton loaders for initial data loads; optimistic UI for low-risk mutations (with rollback on failure); progressive rendering so partial data appears as it arrives rather than blocking the whole screen.

**Real-time and near-real-time.** Pricing changes, billing status, queue boards, payment-validation states, and M-Pesa payment progress update in real time or near-real time (polling or push). The M-Pesa payment screen auto-updates after callback without a manual refresh (§10.13).

**Feedback.** Toasts/snackbars for action outcomes; clear inline validation; explicit empty states with a primary next action; explicit error states with retry; explicit "no permission", "no branch access", "financial period locked", and "billing read-only" states (§12.21).

**Iconography and visual language.** Use a consistent professional icon set (e.g., Font Awesome) rather than emojis; left-side navigation; consistent KES currency formatting (`KES 1,200`) and integer-minor-unit-safe money rendering; consistent date/time formatting.

**Forms and modals.** Dynamic forms that reveal only relevant fields (e.g., the compensation setup modal shows salary/commission sections per model, §12.7); clear previews before submission of sensitive changes (compensation, plan change, payment); confirmation steps for irreversible or billed actions (e.g., the SMS cost notice, §4.7.2).

**Clarity of billing state.** The merchant billing UI always distinguishes current active terms, scheduled future changes, promotional discounts, one-time credits, and overdue balances (§6.5), so the merchant is never surprised by a charge.

**Consistency.** Shared components for tables, filters, status badges, and dashboards keep interactions predictable across roles; destructive actions are visually distinct and require confirmation.

---

# 23. Responsive Layout

The platform is fully responsive and mobile-aware; Personnel surfaces are mobile-first.

| Breakpoint | Target | Behaviour |
| ---------- | ------ | --------- |
| Desktop | ≥ 1025px | Full multi-column layouts, persistent left navigation, data tables. |
| Tablet | 768–1024px | Condensed navigation, responsive grids, key tables retained with horizontal scroll where needed. |
| Mobile | ≤ 767px | Single-column flow; collapsible navigation; **data tables convert to cards**; primary actions surfaced; touch targets ≥ 44px. |

Queue, appointment, payment-recording, and M-Pesa-payment flows must be fully usable on mobile, since front-desk and personnel users frequently operate on phones/tablets. Personnel "My Earnings", served-clients, and SMS composer are optimized for phones.

---

# 24. Security

**Authentication.** Magic Link login for all users; one-time-use tokens with expiry; tokens and active sessions invalidated immediately on deactivation/suspension; login checks tenant, role, status, suspension, and branch scope; optional MFA for high-privilege roles.

**Authorization.** Server-side RBAC + permission + tenant + branch + own-scope enforcement on every protected resource; frontend hiding is never the sole control; plan-entitlement and billing-status gates enforced server-side (§19).

**Rate limiting and abuse prevention.** Registration limited to 3 attempts/hour/IP; sensitive endpoints rate-limited; duplicate/suspicious registration detection and flagging (§11.11); no account enumeration in registration/Magic Link flows.

**CSRF and transport.** CSRF protection for browser-session flows; HTTPS everywhere; secure cookie/session handling.

**Tenant and branch isolation.** UUIDs/ULIDs externally; tenant and branch scoping on every tenant-owned resource; Super Admin never inserted into `merchant_users`, `branch_user_assignments`, or `staff_profiles` (§11.12).

**Data protection and masking.** Field-level masking at read time for under-permissioned readers and Merchant Audit (§4.8.2); signed, expiring URLs with download counts for permitted exports; **permanent removal of Merchant Personnel contact export** across schema/API/UI (§4.7.1).

**Financial integrity.** `PeriodLockService` blocks post-close financial mutations (§13.10); maker/checker separation for payments and cash-up (§13.5, §15.3); invoice/void/refund approval workflows.

**Audit integrity.** Append-only, tamper-evident audit logs with record/chained hashes; mandatory before/after values on sensitive changes; unauthorized-access-attempt logging.

**M-Pesa callback security.** Webhook routes require M-Pesa/security validation and no merchant session; unique `mpesa_receipt_number` and callback-replay protection prevent double-credit; idempotency keys and payment locks prevent duplicate submissions; raw callbacks stored masked/encrypted per policy (§10.11, §10.s).

---

# 25. Accessibility

The platform targets WCAG 2.1 AA. Requirements: full keyboard navigation for all interactive elements; visible focus states; logical tab order; semantic HTML and ARIA labels where needed; sufficient color contrast; form fields with associated labels and clear, programmatically-associated error messages; status changes announced to assistive technology; respect for reduced-motion preferences (skeletons/animations degrade gracefully); touch targets ≥ 44px on mobile; no reliance on color alone to convey status (status badges include text/iconography). Accessibility is treated as a launch-critical requirement, not a later enhancement.

---
# 26. Testing Strategy

Automated tests are launch-critical and run before every deployment. The previous test areas are preserved and extended with the new subscription, M-Pesa, compensation, role-boundary, and personnel-protection suites.

## 26.1 Preserved Test Areas

Tenant isolation; branch isolation; Magic Link authentication and token expiry; role/permission enforcement; staff duplicate prevention (email/phone); same-branch duplicate-client prevention; atomic walk-in creation and valid state transitions; queue/appointment integrity; double-booking and duplicate-session prevention; invoice numbering uniqueness; payment validation workflow and statuses; method-specific payment-reference rules and duplicate detection; partial/split payment correctness; receipt-after-validation rules and numbering; commission calculation and reversal; finance export governance; append-only tamper-evident audit logs (before/after values, severity, status, hash/chained hash); unauthorized-access logging; API validation; critical frontend workflows.

## 26.2 New and Updated Test Suites

```text
PlanSelectionDuringRegistrationTest
TrialStartsAtMerchantAdminCreationTest
FreePeriodOfferScopeTest
SuperAdminCanConfigureFreePeriodDaysTest
PromotionalDiscountScopeTest
TrialExpiryReadOnlyGraceTest
ReadOnlyGraceBlocksMutatingEndpointsTest
ReadOnlyGraceAllowsHistoricalReadsTest
SubscriptionInvoiceGenerationTest
SubscriptionOverdueEscalationTest
SubscriptionSuspensionAuditReasonTest
NoProrationPlanChangeTest
PlanFeatureEntitlementTest
PlanBranchLimitTest
PlanStaffLimitTest
ExtraBranchChargeTest
PlatformBillingModeConfigurationTest
PercentageTierOnlyAppliesWhenPercentageModeActiveTest
FixedBillingPeriodSelectionTest
MerchantPricingChangeVisibilityTest
MpesaSubscriptionPaymentInitiationTest
MpesaCallbackValidationTest
SubscriptionPaymentDuplicateCallbackTest
SubscriptionPaymentOverpaymentCreditTest
SuspendedBillingRecoveryTest
NonBillingSuspensionNoAutoReactivationTest
MerchantSelfRegistrationOnlyTest
ForbiddenSuperAdminMerchantCreationRoutesTest
MerchantRegistrationRateLimitTest
MerchantDuplicateRegistrationFlagTest
CompensationModelCommissionOnlyTest
CompensationModelSalaryOnlyTest
CompensationModelSalaryPlusCommissionTest
CommissionEarnedOnlyAfterPaymentValidationTest
SalaryOnlyNoCommissionLedgerTest
CommissionOnlyNoSalaryLedgerTest
CompensationBackdateApprovalTest
PersonnelOwnScopeTest
PersonnelContactExportBlockedTest
PersonnelBulkSmsOwnScopeTest
AuditBranchScopeTest
AuditContactMaskingTest
AuditExportPermissionTest
FrontOfficeClientDuplicatePhoneTest
FrontOfficeCreatesInvoiceTest
BranchManagerCannotCreateInvoiceTest
BranchManagerCannotTransferQueueOrAppointmentsTest
FinancePeriodLockMutationBlockTest
CashUpSubmitApproveSeparationTest
ReceiptAutoGeneratedAfterValidationTest
```

## 26.3 Required Case Coverage per Suite

Each suite must include, where applicable: positive cases; negative cases; authorization-denial cases; tenant-isolation cases; branch-scope-denial cases; own-scope-denial cases; validation-failure cases; billing read-only cases; suspended-recovery cases; and audit-event assertions.

Specific high-value assertions: `NoProrationPlanChangeTest` asserts a mid-cycle plan change does not alter the current cycle's issued invoice; `CommissionEarnedOnlyAfterPaymentValidationTest` asserts commission becomes earned only on Finance validation; `SalaryOnlyNoCommissionLedgerTest` and `CommissionOnlyNoSalaryLedgerTest` assert no cross-model ledger entries; `PersonnelOwnScopeTest` proves one Personnel user cannot view, search, download, message, or access another Personnel user's records across every personnel-facing API and screen; `PersonnelContactExportBlockedTest` asserts export-shaped personnel routes return `404` and create an unauthorized-access audit event; `SubscriptionPaymentDuplicateCallbackTest` asserts duplicate M-Pesa callbacks do not double-credit; `NonBillingSuspensionNoAutoReactivationTest` asserts fraud/security/legal/compliance/manual suspensions are not auto-reactivated by payment; `BranchManagerCannotCreateInvoiceTest` and `BranchManagerCannotTransferQueueOrAppointmentsTest` assert those actions are Front Office-only; `FinancePeriodLockMutationBlockTest` asserts locked-period mutations are blocked through `PeriodLockService`; `CashUpSubmitApproveSeparationTest` asserts Branch submits and Finance approves; `ReceiptAutoGeneratedAfterValidationTest` asserts receipts are generated automatically after validation with no pre-validation manual button.

---

# 27. Deployment and Production Readiness

Production requirements: Dockerized deployment; CI/CD pipeline; automated test execution before deployment; environment-specific configuration; HTTPS; queue workers; Laravel Scheduler (for billing cycles, trial/grace transitions, overdue escalation, salary accrual, and reconciliation jobs); Redis; database backups; rollback procedure; centralized logging; error monitoring; uptime monitoring; health checks; dependency vulnerability scanning; secure S3-compatible object storage; production mail provider; **SMS gateway integration for bulk SMS billing**; **M-Pesa Daraja production credentials and webhook endpoints with security validation**; staging environment.

Scheduled jobs (via the Laravel Scheduler and Redis queues) specifically include: subscription invoice generation per billing cycle; trial-expiry → read-only-grace → suspended-billing transitions; the shared overdue escalation engine (reminders day 3/7/14 and suspension after the configured window); salary accrual per pay period; M-Pesa reconciliation retries; and SMS cost rollups onto branch subscription billing.

---
# 28. Product-Launch-Ready Feature Checklist

This upgrades the previous checklist and adds the subscription, M-Pesa, compensation, and protection rows.

| Feature | Priority |
| ------- | -------- |
| Magic Link authentication for all users | Critical |
| Merchant Administrator self-registration (only creation path) | Critical |
| Automatic merchant tenant creation | Critical |
| Merchant Owner / Merchant Administrator single account model (account owner, not operational superuser) | Critical |
| First-time Merchant Administrator setup | Critical |
| Subscription plan selection (Starter/Growth/Pro Branch/Multi-Branch) at registration/setup | Critical |
| No Super Admin merchant creation / first-admin creation workflow | Critical |
| Forbidden Super Admin merchant-creation routes return 404 + audit | Critical |
| No compliance/KYC submission for self-registration | Critical |
| Configurable platform billing settings (never hardcoded) | Critical |
| Fixed-amount billing mode | Critical |
| Percentage billing mode (service-fee-tiers active only here) | Critical |
| Fixed-amount-plus-percentage billing mode | Critical |
| Real-time pricing visibility on Merchant Admin landing/billing | Critical |
| Billing periods: weekly, bi-weekly, monthly, quarterly, annual | Critical |
| Entitlement-based plan enforcement (server-side) | Critical |
| Branch and staff limits enforced by plan | Critical |
| Extra-branch billing (Multi-Branch) | High |
| No mid-cycle proration; scheduled plan changes next cycle | Critical |
| Promotional discounts (percentage/fixed; scoped) | High |
| Free-period offers (configurable; default 30 days) | Critical |
| Trial starts at Merchant Admin account creation | Critical |
| Trial → read-only-grace (14 days, configurable) → suspended-billing lifecycle | Critical |
| Separate operational status and billing status columns | Critical |
| Read-only grace blocks mutating endpoints server-side | Critical |
| Shared overdue escalation engine (reminders day 3/7/14) | Critical |
| Merchant subscription payment via M-Pesa (Admin/Branch/Finance/Front Office) | Critical |
| Automated M-Pesa callback validation and reconciliation | Critical |
| Auto-reactivation for billing-only suspension; not for fraud/security/legal/manual | Critical |
| Duplicate-callback, payment-lock, idempotency, and overpayment-credit protection | Critical |
| Suspended-merchant billing-recovery screens and endpoints | Critical |
| Super Admin reconciliation-exception handling (does not record payments) | Critical |
| Tenant isolation | Critical |
| Branch isolation | Critical |
| Role and permission management | Critical |
| Branch creation by Merchant Administrator only | Critical |
| Branch Manager owns service catalogue; HR owns eligibility/compensation | Critical |
| Front Office is default customer-payment recorder | Critical |
| Front Office creates invoices; Branch Manager cannot | Critical |
| Front Office owns queue/appointment transfer; Branch Manager cannot | Critical |
| Front Office client creation with same-branch duplicate-phone 409 | Critical |
| Finance validates/rejects/corrects/audits payments | Critical |
| Finance approves cash-up; Branch submits cash-up | Critical |
| Receipts generated automatically after payment validation | Critical |
| Finance cannot mutate locked periods (central PeriodLockService) | Critical |
| Preferred personnel fee rules (launch-active Super Admin setting; fixed/percentage) | Critical |
| Preferred personnel fee as separate invoice line and receipt component | Critical |
| Personnel compensation models: commission-only, salary-plus-commission, salary-only | Critical |
| Commission earned only after validated payment; traceable reversals | Critical |
| Salary ledger and accrual; payout runs (HR prepares, Finance pays, Admin approves high-value) | Critical |
| Merchant Admin compensation visibility by model (salary/commission/both) | High |
| Personnel My Earnings, earnings statements, earnings queries | High |
| Personnel own-scope enforced across all surfaces (PersonnelOwnScopeTest) | Critical |
| Permanent removal of Merchant Personnel contact export | Critical |
| Personnel in-platform bulk SMS to own served clients, billed to branch | High |
| Clients are branch records only; no client login/portal at launch | Critical |
| Audit covers salary/commission/compensation/payouts | Critical |
| Audit branch-scoped; contact masking; field-level masking at read time | Critical |
| Audit read-only except flagged-event metadata | Critical |
| Audit exports permission-gated, branch-scoped, reason-required, masked, signed, counted, audited | High |
| Deactivate == delete (soft removal; historical preservation) | Critical |
| No Merchant Admin lifecycle block from obsolete platform-fee debt rule | Critical |
| Append-only tamper-evident audit logs; severity and flagged statuses | Critical |
| Unauthorized access attempt logging | Critical |
| Notifications (incl. billing, M-Pesa, compensation, SMS cost) | High |
| Responsive UI (tables → cards on mobile; ≥44px targets) | Critical |
| Modern UX (landing + get-started per role; skeletons; optimistic UI; real-time; Font Awesome; KES) | Critical |
| Accessibility (WCAG AA) | Critical |
| Automated tests (incl. all new suites) | Critical |
| Production monitoring, M-Pesa Daraja, SMS gateway, scheduler jobs | Critical |

---

# 29. Risk Assessment

This upgrades the previous risk register and adds subscription, M-Pesa, compensation, and protection risks.

| Risk | Likelihood | Impact | Mitigation |
| ---- | ---------: | -----: | ---------- |
| Merchants under-record invoices | 60%–75% | High | Make invoices necessary for commissions, receipts, queue history, cash-up, and reporting. |
| Weak daily branch accountability | 60%–75% if day open/close omitted | High | Require branch day opening/closing, statuses, and daily PDF to Merchant Administrator. |
| Payment disputes and cash leakage | 65%–85% if cash-up omitted | High | Require daily cash-up, expected-vs-actual, Finance review, discrepancy notes, period locks. |
| Cross-tenant data leakage | 8%–15% if poorly built; <2% with strong controls | Critical | Tenant scopes, policies, UUIDs, isolation tests, code review. |
| Cross-branch data leakage | 15%–25% if weak; <3% with strong controls | Critical | Branch-scoped server-side policies, explicit assignment, AuditBranchScopeTest. |
| Over-permissioned Finance users | 55%–75% if Finance is one broad permission | High | Granular Finance permissions; maker/checker separation; audit high-risk actions. |
| Finance becomes both maker and checker | 40%–60% if recording is default for Finance | High | Front Office records by default; Finance validates; recording only via explicit override. |
| Branch Manager and Front Office duty confusion | 40%–55% if boundaries unclear | High | Front Office creates invoices and transfers queue/appointments; Branch Manager cannot; enforce via policy + tests. |
| Post-close financial tampering | 50%–70% if periods not centrally locked | High | Route all finance mutations through PeriodLockService; FinancePeriodLockMutationBlockTest. |
| Hardcoded fees/prices causing rigidity and pricing errors | 50%–70% if hardcoded | High | Store all fees/prices/windows in platform_billing_settings; audit changes; seed-but-editable. |
| Billing/operational status collapse breaks suspension/recovery | 45%–65% if collapsed | Critical | Keep merchants.status and merchants.billing_status separate; never collapse. |
| Mid-cycle proration disputes | 40%–60% if proration attempted | Medium | No mid-cycle proration; changes next cycle; NoProrationPlanChangeTest. |
| Trial abuse / premature billing of early-setup merchants | 35%–55% | Medium | Trial starts at account creation; configurable free period; flag duplicate/suspicious registrations. |
| Double payment of one subscription invoice by multiple users | 35%–50% without locks | High | Payment lock, idempotency key, unique receipt number, balance check, callback-replay protection. |
| M-Pesa callback duplicate/replay double-credit | 35%–55% without protection | Critical | Unique mpesa_receipt_number, replay protection, idempotency; SubscriptionPaymentDuplicateCallbackTest. |
| Unmatched M-Pesa payments / merchant locked out after paying | 30%–50% | High | Reconciliation events, exception queue, Super Admin resolution, recovery screens remain accessible. |
| Auto-reactivating fraud/security-suspended merchants on payment | 25%–45% if reactivation not reason-gated | Critical | Auto-reactivate only when suspension reason is unpaid_subscription; NonBillingSuspensionNoAutoReactivationTest. |
| Super Admin creating merchants causing ownership/governance disputes | 45%–60% if allowed | High | Self-registration is the only creation path; forbidden routes return 404 + audit. |
| Payroll fraud via unrestricted salary/commission changes | 45%–60% | High | Approval-gated sensitive compensation changes; backdating requires approval; critical-severity audit. |
| Cross-model compensation errors (salary for commission-only, etc.) | 70%–80% if employment_type overloaded | High | Separate compensation_model field; no cross-model ledger entries; model-specific tests. |
| Commission paid before payment validated | 40%–60% if trigger is wrong | High | Commission earned only on Finance validation; CommissionEarnedOnlyAfterPaymentValidationTest. |
| Personnel sees data beyond own scope | 35%–55% without server-side controls | Critical | EnsurePersonnelOwnScope across all surfaces; PersonnelOwnScopeTest. |
| Client contact exposure / export by Personnel | 35%–50% if export exists | High | Permanently remove personnel contact export; 404 + audit on export-shaped routes; masked in-platform SMS only. |
| SMS cost leakage / unbilled SMS | 30%–45% | Medium | Log SMS cost against branch; bill to branch subscription; cost-notice confirmation before send. |
| Audit over-exposure of sensitive fields | 35%–55% if masking is frontend-only | High | Field-level masking at read time server-side; permission-gated, reason-required unmasking. |
| Duplicate client records within a branch | 35%–50% without controls | Medium | Same-branch same-phone 409 server-side; FrontOfficeClientDuplicatePhoneTest. |
| Premature or duplicate receipts | 55%–75% if controls weak | High | Auto-generate only after validation; unique receipt numbers; ReceiptAutoGeneratedAfterValidationTest. |
| Product becomes too complex for SMEs | 35%–50% | High | Keep Starter genuinely usable; role dashboards simple and workflow-driven; landing + get-started guidance. |
| Launch delay from overbuilding | 40%–60% | High | Prioritize operational core, subscription billing, and M-Pesa recovery before franchise/API/inventory add-ons. |

---
# 30. Consolidated Acceptance Criteria

The Servana by Citrus Project Scope is complete only when all of the following hold:

1. Platform fees and plan prices are configurable, not hardcoded.
2. Super Admin can configure fixed-amount billing.
3. Super Admin can configure percentage billing.
4. Super Admin can configure fixed-plus-percentage billing.
5. Merchant Admin sees pricing changes on landing/billing screens in real time or near-real time.
6. Fixed-amount billing supports weekly, bi-weekly, monthly, quarterly, and annual periods.
7. Tier pricing (customer_centric, shared, business_centric) applies only when percentage billing is active.
8. Merchant Admin selects a plan during account creation or first-time setup, before operational dashboard access.
9. Merchant Admin can request/schedule a plan change while logged in.
10. Plan selection applies only to the merchant tenant and its branches.
11. Plan entitlements are server-enforced.
12. Starter is a real, usable plan, not a demo.
13. Growth is the mainstream team-management plan.
14. Pro Branch is the finance-control and audit plan.
15. Multi-Branch is the centralized multi-branch plan.
16. Super Admin can configure promotional discounts.
17. Promotional discounts can be percentage or fixed amount.
18. Promotional discounts can target all, new, selected, or specific merchants/Merchant Admins.
19. Free period starts at Merchant Admin account creation.
20. Free-period duration is configurable and defaults to 30 days.
21. Read-only grace defaults to 14 days and is configurable.
22. Billing status and operational status are separate columns.
23. Read-only grace blocks mutating operational endpoints server-side.
24. Subscription invoices and platform/add-on invoices share one overdue escalation engine.
25. Plan changes do not prorate mid-cycle; they take effect next cycle, and a test asserts the current cycle's invoice is unchanged.
26. Merchant subscription invoices are paid by merchant-side users (Admin, Branch Manager, Finance, Front Office) via M-Pesa.
27. M-Pesa subscription payments are automatically validated.
28. The invoice becomes paid without Super Admin manual recording.
29. Billing status changes to `active_subscription` after full payment.
30. Billing-only suspended merchants are automatically reactivated after full payment.
31. Non-billing suspended merchants (fraud/security/legal/compliance/manual/deactivated) are not automatically reactivated.
32. Duplicate M-Pesa callbacks do not double-credit the invoice.
33. Concurrent payment attempts are blocked or safely handled (locks, idempotency).
34. Overpayments become merchant billing credit, applied to the next invoice.
35. Failed/timeout/cancelled STK attempts are visible and retryable.
36. Suspended merchant users can log in only to billing-recovery screens and can pay there.
37. Super Admin monitors exceptions but does not normally record subscription payments.
38. Merchant creation occurs only through Merchant Administrator self-registration.
39. Super Admin cannot create merchants or first Merchant Admins; forbidden routes return 404 and are audited.
40. Merchant self-registration is rate-limited (3/hour/IP) and duplicate/suspicious registrations are flagged.
41. "Deactivate" and "delete" are the same functional instruction (soft removal with historical preservation) unless "hard delete from database" is explicitly stated.
42. No Merchant Admin lifecycle action is blocked by an obsolete platform-fee debt rule.
43. Merchant Admin is the account owner, not an operational superuser.
44. Merchant Admin can view personnel compensation by configured model (salary, commission, or salary-plus-commission).
45. Branch Manager owns the service catalogue and branch setup/day operations.
46. HR owns staff, eligibility, compensation, commission, and salary setup.
47. Front Office creates clients, queue entries, appointments, and invoices, and records customer payments by default.
48. Front Office client creation is blocked by same-branch same-phone with HTTP 409.
49. Front Office owns queue/appointment transfer; Branch Manager cannot transfer.
50. Branch Manager cannot create invoices.
51. Finance validates, rejects, corrects, and audits payments; may record only via explicit override.
52. Finance approves/rejects cash-up; Branch Manager submits cash-up.
53. Finance cannot mutate locked financial periods; all finance mutations call PeriodLockService.
54. Receipts are generated automatically after payment validation, with no pre-validation manual button.
55. Preferred personnel fee rules are launch-active Super Admin settings (fixed or percentage), shown as a separate invoice line and receipt component, and audited.
56. HR decides whether the preferred personnel fee is included in the personnel commission basis.
57. Personnel compensation supports commission-only, salary-plus-commission, and salary-only.
58. Salary-only personnel never receive commission ledger entries; commission-only never receive salary ledger entries; salary-plus-commission receive both.
59. Commission is earned only after Finance validates payment; reversals are traceable to a cause.
60. Backdated compensation changes require approval; sensitive changes are approval-gated and audited.
61. Payouts can be marked paid without moving money through Servana (HR prepares, Finance verifies/pays, Admin approves high-value).
62. Personnel can view only their own compensation, earnings, served clients, and payout records; can download own statements; can raise earnings queries; cannot edit compensation.
63. Personnel cannot export client contacts (no endpoint/UI/permission/flag); export-shaped routes return 404 and are audited.
64. Personnel may send in-platform bulk SMS only to their own served-client list, with a cost notice, billed to the branch.
65. Clients are branch records only at launch; no client login, portal, Magic Link, or dashboard; nullable `user_id` stays inactive.
66. Audit covers salary, commission, compensation, payout, and related changes.
67. Audit is branch-scoped (server-side filtering).
68. Audit contact and other sensitive fields are masked by default at read time; unmasking is permission-gated, reason-required, and audited.
69. Audit is read-only except for flagged-event metadata.
70. Audit exports are permission-gated, branch-scoped, reason-required, masked, signed-URL-delivered, download-counted, and audited.
71. All salary and commission money values are stored as integer minor units.
72. Every account user has a landing page and a get-started page.
73. The user experience is modern (skeletons, optimistic UI, real-time updates, toasts, explicit empty/error/no-permission/locked/read-only states, Font Awesome icons, left-side navigation, KES formatting).
74. The platform is responsive (tables convert to cards on mobile; touch targets ≥ 44px) and meets WCAG 2.1 AA.
75. All new business rules are tested server-side, with positive, negative, authorization-denial, tenant-isolation, branch-scope, own-scope, validation, billing-read-only, suspended-recovery, and audit-assertion cases.

---

# 31. Final Servana by Citrus Project Scope Positioning Statement

Servana by Citrus is a multi-tenant service-operations SaaS platform for service-based SMEs. It combines merchant self-registration, branch operations, client management, walk-ins, appointments, queues, service sessions, invoicing, offline merchant-client payment recording, automatic receipt generation, staff lifecycle management, compensation management, salary and commission visibility, cash-up accountability, audit controls, subscription billing, M-Pesa subscription payment recovery, configurable plan entitlements, promotional billing controls, and strict tenant/branch/own-scope data protection.

Servana is not only a booking system, POS system, invoicing system, payroll system, or payment processor. It is an operating-control platform for service businesses, with subscription-first platform billing, three launch-active configurable fee modes (fixed, percentage, and fixed-plus-percentage), and strong separation between merchant ownership, branch management, HR, finance, front office, personnel, audit, and Super Administrator governance.

Concretely, Servana by Citrus is a production-ready, secure, scalable, multi-tenant SaaS platform created and operated by Citrus Labs Limited for service-based SMEs such as barbershops, salons, massage parlours, spas, grooming studios, beauty parlours, and similar appointment/walk-in businesses. It supports Merchant Administrator self-registration as the only merchant creation path; automatic tenant creation; Merchant Administrator account ownership (not operational super-use); first-time setup with subscription-plan selection; Magic Link login for all users; branch creation by the Merchant Administrator; Branch-Manager-owned service catalogue and pricing; HR-owned staff lifecycle, service eligibility, and compensation; Front-Office-owned client creation, invoicing, default payment recording, and queue/appointment transfer; Finance-owned payment validation, cash-up approval, period locking, and payout verification; Merchant-Personnel own-scope service work and private earnings; branch-scoped read-only Merchant Audit with masking and a flagged-event exception; configurable subscription-first platform billing (fixed, percentage, or fixed-plus-percentage) with plans, entitlements, promotions, free-period offers, a trial-to-grace-to-suspension lifecycle, and a shared overdue engine; automated M-Pesa subscription payment and account recovery; automatic receipt generation after validation; deactivation treated as soft removal with historical preservation; permanent removal of personnel contact export with masked in-platform bulk SMS; modern best-practice UX with per-role landing and get-started pages; full responsiveness and WCAG 2.1 AA accessibility; comprehensive server-side enforcement and automated testing; and deployment-grade security, observability, and scalability.

The platform shall not use a Super-Administrator-created merchant onboarding model; shall not require merchant KYC/compliance submission for self-registration; shall not collapse operational and billing status; shall not hardcode fees, prices, or billing windows; shall not prorate plan changes mid-cycle; shall not give the Merchant Administrator broad operational authority beyond the expressly permitted functions; shall not allow Finance to be both maker and checker by default; shall not allow the Branch Manager to create invoices or transfer queue/appointments; shall not generate receipts before payment validation; shall not provide Merchant Personnel with any client-contact export; and shall not block Merchant Administrator lifecycle actions on the basis of any obsolete platform-fee debt rule.

---

*End of Servana by Citrus — Upgraded Platform Project Scope.*

---
---

# PART B — Contradiction-Resolution Amendment Package

This amendment package is an integral, governing part of the Servana by Citrus Upgraded Platform Project Scope. It resolves every contradiction identified in the contradiction-resolution mandate, amends the affected body sections (§0–§31) above, and supplies the detailed rule sets the body cross-references (the "amendment block", §6A, §6B, §18.2b, §18.2c, §18.2d, §10.5). Where this package and any earlier prose conflict, **this package and the NEW/UPGRADED FEATURES governing source of truth prevail**, then the explicit contradiction resolutions, then the existing upgraded scope, then the older scope. No resolution below is optional, conditional, or deferred.

# B0. Document Control and Source-of-Truth Statement

Source-of-truth hierarchy (highest first): (1) the settled NEW/UPGRADED FEATURES requirements; (2) the explicit contradiction resolutions in this package; (3) the existing upgraded scope (§0–§31); (4) the older Servana scope, whose operational, security, accessibility, audit, and usability safeguards are preserved unless expressly replaced. All rules in this package are normative ("shall", "must", "must not", "only", "never"), enforced server-side (frontend hiding is never authorization), and apply tenant, branch, role, permission, billing-status, plan-entitlement, own-scope, period-lock, and audit controls as applicable. Every account user retains a role-specific landing page and get-started page (§3), updated to reflect each correction below. No contradiction is left as a future decision; no "option A / option B" remains.

# B1. Consolidated Contradiction-Resolution Table

| # | Contradiction | Settled authoritative rule |
| - | ------------- | -------------------------- |
| C1 | Percentage billing is described as both a launch mode and a future-only mode. | Fixed, percentage, and fixed-plus-percentage are **all launch-active, Super-Administrator-selectable** modes. The percentage engine, fee lifecycle, snapshots, tiers, ledger, adjustments, and disputes are fully specified at §6A. All future-only language is removed. |
| C2 | Plan prices are said to live in `platform_billing_settings`, but also in `subscription_plans`. | `subscription_plan_prices` is the **single authoritative, versioned** plan-price source of truth (§6B/§18.2b). `platform_billing_settings` holds global config only; `subscription_plans` holds non-price metadata only. |
| C3 | Read-only grace and billing suspension imply different allowlists. | `read_only_grace` and `suspended_billing` use **one identical historical read-only allowlist** and the same mutation block (§B-C3). Both permit billing recovery; non-billing suspensions are excluded from auto-reactivation. |
| C4 | A "Front Office or Branch Manager" preferred-personnel selection interface. | Selection and movement are **Front Office only**. Branch Manager is operationally read-only for queue/appointment assignment, transfer, and preferred-personnel selection (§B-C4, §13.9). |
| C5 | Growth excludes "cash-up approval workflow" yet must close cash-up. | Growth has a **complete basic cash-up workflow** (Branch submits → Finance single-step approves/rejects → approved record immutable, record-level closure). Pro Branch adds period locking, controlled reopening, multi-stage approval, advanced exports (§B-C5). |
| C6 | Payout-run routes/ownership placed entirely under Finance. | Payout runs use **neutral, policy-protected routes** with maker-checker: HR prepares/submits, Finance verifies/approves-standard/marks-paid, Merchant Admin approves high-value, Personnel views own, Audit reviews (§10.5/§B-C6). |
| C7 | Merchant Administrator "locks financial periods". | **Finance owns routine period locks and controlled reopening.** Merchant Administrator only approves an exceptional reopen where the governance workflow requires it; the Merchant Administrator performs no routine locks (§B-C7, §13.10). |
| C8 | Promotion targeting uses a generic unvalidated `scope` string. | Promotions use **structured targeting** (`promotional_discounts.target_scope` enum + `promotional_discount_targets` rows). Targets resolve to the **merchant billing account**, not individual payers (§18.2c/§B-C8). |
| C9 | Scheduled price changes overload `scheduled_plan_changes`. | Scheduled **price** changes are versioned `subscription_plan_prices` records; `scheduled_plan_changes` is reserved for a merchant switching plan/period. No automatic grandfathering at launch (§B-C9). |
| C10 | Previously-settled requirements omitted or weakened. | Restored and strengthened: audit flagged-event status schema; Front Office search fields; estimated-wait inputs; private-by-default storage; no secrets in frontend; no secrets in logs; server-side upload validation; CSS-based responsive design; 200% zoom/reflow; no whole-page horizontal scroll (§B-C10). |

Each contradiction is detailed below in the mandated structure (Contradiction / Settled resolution / Sections to delete / rewrite / add / Affected account users / Data-model / Permission / API / Frontend / Audit / Plan-entitlement / Migration / Tests / Acceptance criteria), followed by the account-user impact matrix (§B14), plan-entitlement matrix (§B15), consolidated data-model (§B16), permissions (§B17), API (§B18), frontend (§B19), audit (§B20), background jobs (§B21), migration (§B22), tests (§B23), acceptance criteria (§B24), and final verification (§B25).

# 6A. Percentage Platform-Fee Engine (Contradiction 1 — launch-active)

**Contradiction.** Earlier prose treated percentage and fixed-plus-percentage billing as future-only / forward-compatibility / placeholder while elsewhere listing them as active modes.

**Settled resolution.** The three active billing modes are `fixed_amount`, `percentage_on_merchant_client_invoice`, and `fixed_amount_plus_percentage_on_merchant_client_invoice`. All are launch-supported and Super-Administrator-activatable without a code deployment. Fixed-amount may be the default launch configuration. The percentage component is fully specified and implementable here.

**Sections to delete / rewrite.** All "future-only", "forward compatibility only", "inactive placeholder", "later extension", "optional future percentage" wording in §0, §1, §2, §6.3, §6.4, and §16.13 is removed and rewritten to "launch-active" (done in-body).

**Sections to add.** This §6A, plus the platform-fee entities in §18.2d.

## 6A.1 Percentage-Fee Lifecycle

```text
Merchant-client invoice is finalized
→ percentage-fee basis and configuration are snapshotted
→ provisional platform-fee entry is created (status: provisional)
→ merchant-client payment is validated by Finance
→ the platform-fee liability becomes billable (status: billable)
→ billable entries are aggregated into a Servana billing invoice
→ merchant pays the Servana billing invoice through M-Pesa (§10)
→ M-Pesa reconciliation clears the billing liability (status: settled)
```

Where a merchant-client invoice is voided, refunded, corrected, or partially refunded, the system shall create a traceable fee reversal or adjustment in `platform_fee_adjustments` and shall never silently edit or delete an original `platform_fee_ledger_entries` row.

## 6A.2 Fee-Basis and Configuration Snapshot

Every percentage-derived entry captures, at minimum: `merchant_id`, `branch_id`, `merchant_client_invoice_id`, `merchant_client_invoice_item_id` (where applicable), `billing_mode_snapshot`, `service_fee_tier_snapshot`, `fee_basis_type`, `fee_basis_amount_minor`, `percentage_rate_snapshot`, `gross_platform_fee_minor`, `client_shifted_amount_minor`, `merchant_liability_minor`, `currency`, `status`, `effective_configuration_id`, `created_at`, `billable_at`, `aggregated_billing_invoice_id`, `reversed_entry_id` (where applicable), `adjustment_reason` (where applicable). Permitted `fee_basis_type` values are explicitly enumerated and must match the configured platform rule:

```text
service_price
invoice_item_total
invoice_subtotal
net_after_discount
validated_paid_amount
```

The fee basis is never implicit; the platform rule defines which basis is active.

## 6A.3 Tier Behaviour (percentage component only)

The tiers `customer_centric`, `shared`, `business_centric` apply only when the active billing mode contains a percentage component. Settled effects:

| Tier | Merchant-client invoice | Client-shifted amount | Merchant liability to Servana |
| ---- | ----------------------- | --------------------- | ----------------------------- |
| `customer_centric` | Contains the original merchant service amount only. | Zero. | Full calculated platform fee (merchant absorbs all). |
| `shared` | Adds the configured share of the platform fee. | The configured share, stored separately. | Full platform fee remains traceable; merchant-absorbed share stored separately. |
| `business_centric` | Adds the full platform fee. | Equals the calculated platform fee. | Full fee remains the merchant's traceable liability until the Servana billing invoice is paid. |

The tier changes how the merchant-client invoice is priced; it never removes the merchant's accounted liability to Servana.

## 6A.4 Payment Path

All merchant-to-Servana invoices — subscription invoices and standalone or aggregated platform-fee invoices — use the approved Servana M-Pesa billing-payment and reconciliation infrastructure (§10). Merchant-client service payments remain offline/off-platform and recorded only (§16.11). The Super Administrator does not have a routine manual payment-recording function; the Super Administrator monitors reconciliation exceptions, fraud flags, and failed matches.

## 6A.5 Account-User Effects (C1)

Super Administrator: activates any mode; configures rates, fixed amounts, tier-allocation rules, and billing periods; reviews fee ledgers, disputes, reconciliation exceptions, and aggregate platform revenue/liability; cannot edit merchant-client invoices, fabricate fee entries, routinely mark merchant payments paid, or perform merchant operations. Merchant Administrator: sees active mode, selects the applicable tier when a percentage component is active, views current rate/tier and accumulated/invoiced/adjusted/disputed/paid fees, receives pricing changes in real time, pays Servana billing invoices via M-Pesa, may raise a permitted fee dispute; cannot change the platform rate, ledger history, or source amounts. Branch Manager: views branch-attributable fee totals where permitted and tier impact on branch pricing, and may pay a merchant billing invoice from the permitted billing-recovery context; cannot configure tier/rates or edit ledger entries. Finance: views fee reconciliation, merchant liability, adjustments, disputes within scope, pays billing invoices via M-Pesa, reviews refund/void accounting effects; cannot change platform-wide config or snapshots. Front Office: sees the final invoice total and the percentage fee as a separate component when the active tier shifts an amount to the client, and explains it with approved wording; cannot select/change tier, change the percentage, or remove the fee line without an authorized invoice-correction workflow. HR: no fee-config authority; sees only what is needed to decide whether a preferred-personnel fee is part of a commission basis. Personnel: see only fee information relevant to their own service/commission; never merchant-wide fee liability. Audit: reviews fee creation/adjustment/reversal/dispute/reconciliation within branch scope and masking; cannot mutate the fee or its source.

**Data-model impact:** §18.2d (`platform_fee_ledger_entries`, `platform_fee_adjustments`, `platform_fee_disputes`, `billing_invoice_lines`, `billing_reconciliation_records`). **Permission impact:** platform-fee config (Super Admin), fee view/dispute (Merchant Admin/Finance), fee-line read (Front Office), branch totals (Branch Manager). **API impact:** §B18 percentage-fee and fee-dispute endpoints. **Frontend impact:** Super Admin billing-mode config; Merchant Admin tier selection + fee dashboard; Front Office fee-line display. **Audit impact:** fee create/adjust/reverse/dispute/reconcile events. **Plan-entitlement impact:** percentage-fee visibility surfaces follow plan reporting/finance entitlements. **Migration impact:** create fee ledger/adjustment/dispute/line tables; backfill none. **Tests:** §B23 (PercentageBillingLaunchMode, FixedPlusPercentageBillingLaunchMode, PercentageBillingNotMarkedFutureOnly, PlatformFeeSourceInvoiceLink, PlatformFeeRateSnapshot, PlatformFeeTierSnapshot, PlatformFeeClientShiftedAmount, PlatformFeeMerchantLiability, PlatformFeeAggregation, PlatformFeeVoidReversal, PlatformFeePartialRefundAdjustment, PlatformFeeDisputeLifecycle, PlatformFeeMpesaPaymentReconciliation, PlatformFeeTenantIsolation, PlatformFeeBranchScope, PlatformFeeAuditTrail). **Acceptance:** all three modes launch-supported; no future-only language; every fee links to its source invoice; basis/rate/tier/allocation snapshotted; reversals/disputes traceable; fee + subscription share the billing/overdue infra and M-Pesa path.

# 6B. Plan-Price Source of Truth and Scheduled Prices (Contradictions 2 and 9)

**Contradiction 2.** Plan prices were said to live in `platform_billing_settings` and also duplicated in `subscription_plans`. **Contradiction 9.** Scheduled price changes overloaded `scheduled_plan_changes`.

**Settled resolution (normalized architecture).**

```text
platform_billing_settings  → global billing behaviour, toggles, defaults, windows (no plan prices)
subscription_plans         → plan identity, positioning, limits, non-price metadata
subscription_plan_prices   → authoritative, versioned monetary price per plan and billing period
```

Every statement saying "all plan prices are stored in `platform_billing_settings`" is rewritten to: *all plan prices are configured by the Super Administrator through the platform billing-settings domain and are authoritatively persisted as versioned records in `subscription_plan_prices`.* Duplicate price fields are removed from `subscription_plans` (done in §18.2). A plan-**price** change is a versioned `subscription_plan_prices` record; `scheduled_plan_changes` is used **only** for a merchant switching plan or billing period.

## 18.2b `subscription_plan_prices`

```text
id
ulid
plan_id
billing_period enum: weekly | bi_weekly | monthly | quarterly | annual
amount_minor
currency
effective_from
effective_to nullable
status enum: draft | scheduled | active | expired | cancelled
created_by
approved_by nullable
change_reason
created_at
updated_at
```

Hard rules: (1) only one active price per plan + billing period + currency + effective instant; (2) price periods must not overlap; (3) prices stored in integer minor units; (4) issued invoices preserve their captured price; (5) every price change is audited; (6) the Super Administrator must supply a change reason; (7) a future price is a `scheduled` record, never an overwrite of the active price.

## 6B.1 Scheduled-Price Lifecycle (C9)

```text
draft → scheduled → active → expired
draft → cancelled
scheduled → cancelled
```

On approval of a future price: create a `scheduled` `subscription_plan_prices` record; preserve the current active price; show the scheduled price to affected Merchant Administrators in real time or near-real time; at `effective_from`, activate the new price and set the former price's `effective_to`; use the new price only for billing cycles starting on or after `effective_from`; never alter an already-issued invoice; never prorate the current cycle; audit the activation. A scheduled price may be cancelled before activation, recording `cancelled_by`, `cancelled_at`, `cancellation_reason`. An active historical price is never deleted.

## 6B.2 Invoice Price Capture

Every billing invoice captures: `subscription_plan_price_id`, `plan_id_snapshot`, `billing_period_snapshot`, `unit_price_minor_snapshot`, `currency_snapshot`, `price_effective_from_snapshot`, `promotion_snapshot`, `extra_branch_charge_snapshot`, `total_amount_minor`. Invoice generation selects the price version whose effective interval contains the billing-cycle start.

## 6B.3 No Automatic Grandfathering

At launch, a scheduled price applies to every merchant matching the plan, billing period, currency, and configured target on the first billing cycle starting on or after `effective_from`. Already-issued invoices are unchanged. No automatic grandfathering exists; any future grandfathering is a separately designed, documented extension, never an undocumented exception.

## 6B.4 Account-User Effects (C2/C9)

Super Administrator configures, schedules, cancels, and inspects price history from billing settings. Merchant Administrator sees the current active price, scheduled price, effective date, current plan/period, next-invoice estimate (reflecting the scheduled price only when the next cycle begins on/after the effective date), and promotion/credit effects — in real time or near-real time, and the notice persists until activation or cancellation. Finance and permitted merchant billing users see the price captured on each invoice and the active future-price notice. Branch Manager and Front Office see only the merchant-level amount due and their permitted payment action. HR, Personnel, and Audit have no price-configuration authority; Audit may inspect price-change/scheduling/activation/capture events within scope.

**Data-model impact:** add `subscription_plan_prices` (§18.2b); remove price fields from `subscription_plans`; add invoice price-capture columns. **Permission impact:** `billing.plan_prices.manage` (Super Admin), read-only price view (Merchant Admin/Finance). **API impact:** §B18 versioned plan-price endpoints. **Frontend impact:** Super Admin price scheduling; Merchant Admin current/future price notice. **Audit impact:** price create/schedule/activate/cancel + invoice-capture events. **Plan-entitlement impact:** none to entitlements; price applies per plan. **Migration impact:** migrate existing prices into versioned active records without duplicating the source of truth. **Tests:** SinglePlanPriceSourceOfTruth, PlanPriceOverlapBlocked, PlanPriceMinorUnits, PlanPriceVersionHistory, PlanPriceChangeReasonRequired, IssuedInvoicePriceSnapshot, ScheduledPriceActivation, PlanPriceTenantDisplay, ScheduledPriceRecord, CurrentPriceNotOverwritten, ScheduledPriceRealTimeNotice, ScheduledPriceEffectiveDate, ScheduledPriceCancellation, ScheduledPriceNoProration, ScheduledPriceIssuedInvoiceUnchanged, ScheduledPriceInvoiceVersionCapture, NoAutomaticGrandfathering, PlanChangeAndPriceChangeSeparation. **Acceptance:** `subscription_plan_prices` is the only plan-price source of truth; scheduled prices versioned; issued invoices preserve captured prices; no current-cycle proration; immediate Merchant Admin visibility; no automatic grandfathering.

# B-C3. Read-Only Grace and Billing-Suspension Allowlist Parity (Contradiction 3)

**Settled resolution.** The canonical billing-status progression is `trialing → read_only_grace → suspended_billing → active_subscription` (after full validated payment). Operational status is a separate field. `read_only_grace` and `suspended_billing` use the **same historical read-only resource allowlist** and the **same mutation block**. The user-facing label for `suspended_billing` may be "Suspended".

Both statuses **permit**: login; view clients within the user's normal role/branch scope; view past merchant-client invoices, receipts, historical reports, and previously generated authorized downloads; view the billing dashboard, outstanding Servana billing invoices, and M-Pesa payment attempts; initiate M-Pesa payment where the role is permitted; view payment/recovery status; contact support through billing recovery.

Both statuses **block**: create/update clients; create walk-ins; create/modify queue entries; create/reschedule appointments; start/alter service sessions; create/amend merchant-client invoices; record or validate merchant-client payments; generate new operational receipts; create/edit staff, services, compensation, or payouts; submit/approve new cash-up records; **any other operational or financial mutation**. Existing authorized files may be downloaded only where the underlying authorization still permits; **new exports/reports must not be generated** during read-only states.

**Billing-payment authority during restriction.** Roles that may initiate the merchant's M-Pesa payment: Merchant Administrator, Branch Manager, Finance, Front Office. Roles that may not by default: HR, Personnel, Audit, Super Administrator (the Super Administrator monitors exceptions but is never the merchant payer).

**Reactivation.** After full payment is automatically validated: `billing_status = active_subscription`, billing restriction removed, normal role-based access restored — **only** when the suspension is billing-only. The platform must not auto-reactivate a merchant under fraud, security, legal, manual-governance suspension, or deactivation.

**Scope preservation.** Restriction never broadens a user's historical visibility beyond their normal tenant/branch/role/own-scope. Affected body sections: §9.3, §9.4, §9.5, §10.9 — amended so the grace allowlist and the suspended allowlist are identical. **Tests:** ReadOnlyGraceAllowlist, SuspendedBillingAllowlistParity, ReadOnlyGraceMutationBlocked, SuspendedBillingMutationBlocked, RestrictedRoleScopePreserved, RestrictedOwnScopePreserved, RestrictedNewExportBlocked, BillingRecoveryEligibleRole, BillingRecoveryIneligibleRole, BillingOnlyAutomaticReactivation, NonBillingSuspensionNotReactivated. **Acceptance:** parity allowlist; all mutations blocked; billing recovery available; non-billing suspensions not auto-removed.

# B-C4. Branch Manager Preferred-Personnel Interface (Contradiction 4)

**Settled resolution.** Remove every "Front Office or Branch Manager selection interface" phrasing; the selection interface is **Front Office only** (done in §5.4). Front Office owns operational selection and movement: `next available`, `manual assignment`, `preferred personnel`, plus queue/appointment transfer where permitted, invoice creation, and merchant-client payment recording. The Branch Manager is **operationally read-only** for queue-entry and appointment assignment, reassignment, transfer, and preferred-personnel selection, and must not appear on the selection interface. The Branch Manager retains: service catalogue, service price/duration, branch service availability, branch calendar, branch-day open/close, queue/appointment visibility, branch-performance visibility, cash-up submission, and read-only visibility of HR-controlled eligibility/availability. Branch Manager read-only endpoints reject mutation attempts with an authorization response and an audit event.

**Permissions.** Front Office holds `queue.create`, `queue.assign`, `queue.transfer`, `appointments.create`, `appointments.assign`, `appointments.transfer`, `preferred_personnel.select`, `invoices.create`, `customer_payments.record`. The Branch Manager must not receive these by default. **Tests:** FrontOfficePreferredPersonnelSelection, FrontOfficeQueueAssignment, FrontOfficeAppointmentTransfer, BranchManagerCannotSelectPreferredPersonnel, BranchManagerCannotTransferQueueOrAppointments, BranchManagerCannotCreateInvoice, BranchManagerMutationAttemptAudited. **Acceptance:** Branch Manager has no preferred-personnel selection or queue/appointment transfer authority; Front Office owns permitted assignment and movement.

# B-C5. Growth Cash-Up Workflow (Contradiction 5)

**Settled resolution.** Growth includes a **complete basic cash-up workflow** that reaches a terminal state, without Pro Branch's advanced financial-period controls:

```text
Branch Manager prepares and submits cash-up
→ Finance performs one-step review
→ Finance approves or rejects (rejection requires a reason)
→ approved cash-up record becomes immutable (record-level closure)
```

This is **record-level cash-up closure**, not the PeriodLockService day/period lock-and-reopen system. Growth excludes multi-stage approval, merchant-level cash-up governance, PeriodLockService control over a day/period, reopening an approved period, advanced discrepancy escalation, advanced cash-up exports, multi-branch cash-up comparison, and approval-delegation chains. Pro Branch adds full review/approval, period locking, controlled reopening, reason-required overrides, advanced discrepancy workflows, advanced exports, and enhanced audit. Multi-Branch adds branch-by-branch cash-up status, merchant-level consolidated visibility, multi-branch exception reporting, and branch- and merchant-level period governance. Growth's exclusion wording is rewritten from "cash-up approval workflow → Pro Branch" to "advanced cash-up governance, period locking, controlled reopening, multi-stage approval, and advanced cash-up exports → Pro Branch" (done in §7.5).

**Account-user effects.** On Growth: Branch Manager prepares/submits, sees approval/rejection, cannot approve own submission, cannot edit an approved record. Finance reviews, approves/rejects with reason, and cannot use full period-lock/reopen on Growth. Merchant Administrator views Growth cash-up status/summaries and does not routinely approve. Audit reviews submission/approval/rejection/amendment history within scope. **Tests:** GrowthCashUpCompleteWorkflow, GrowthCashUpSubmitApproveSeparation, GrowthCashUpApprovedRecordImmutable, GrowthCashUpNoPeriodLockEntitlement, ProBranchPeriodLockEntitlement, ProBranchCashUpReopenReason, MultiBranchCashUpConsolidation. **Acceptance:** Growth cash-up reaches an approved/rejected terminal state; Growth does not receive Pro's full period-lock system.

# B-C6. Payout-Run Ownership, Permissions, and Routes (Contradiction 6)

**Settled responsibility split.** HR prepares payout data; Finance verifies; Finance approves ordinary runs; Merchant Administrator approves high-value runs (above a configurable, integer-minor-unit threshold in merchant compensation-governance settings); Finance marks approved runs paid after external payment; Personnel views only their own payout results; Audit reviews complete history. Servana records external payout completion and does not move personnel funds at launch.

**Maker-checker.** The user who prepares/submits a run must not verify, approve, or mark it paid. The approver must not unilaterally rewrite source salary/commission entries. High-value runs require Merchant Administrator approval after Finance verification.

**Status model.** `draft, submitted, finance_verified, pending_merchant_admin_approval, approved, rejected, paid, cancelled, adjusted`. Ordinary flow: `draft → submitted → finance_verified → approved → paid`. High-value flow inserts `pending_merchant_admin_approval` before `approved` (done in §12.12, §12.15).

## 10.5 Payout API (neutral, policy-protected)

```text
GET    /api/v1/payout-runs
POST   /api/v1/payout-runs
GET    /api/v1/payout-runs/{run}
PATCH  /api/v1/payout-runs/{run}
POST   /api/v1/payout-runs/{run}/submit
POST   /api/v1/payout-runs/{run}/verify
POST   /api/v1/payout-runs/{run}/approve-standard
POST   /api/v1/payout-runs/{run}/approve-high-value
POST   /api/v1/payout-runs/{run}/reject
POST   /api/v1/payout-runs/{run}/cancel
POST   /api/v1/payout-runs/{run}/mark-paid
```

Each endpoint enforces its role, permission, branch scope, tenant scope, maker-checker rule, and valid status transition. Permissions are split HR/Finance/Merchant Admin/Personnel/Audit as listed in §12.16 (amended). Branch Manager and Front Office have no payout authority by default. **Tests:** HrCreatesPayoutRun, HrSubmitsPayoutRun, HrCannotApprovePayoutRun, FinanceVerifiesPayoutRun, FinanceApprovesStandardPayout, HighValuePayoutRequiresMerchantAdmin, MerchantAdminCannotMarkPayoutPaid, FinanceMarksApprovedPayoutPaid, PayoutMakerChecker, PayoutInvalidTransitionBlocked, PersonnelOwnPayoutScope, PayoutAuditTrail. **Acceptance:** HR prepares; Finance verifies/marks paid; Merchant Admin approves high-value; maker-checker prevents self-approval; routes are neutral and policy-protected.

# B-C7. Period-Lock Ownership (Contradiction 7)

**Settled resolution.** Finance owns routine financial-period lock execution and controlled reopening. The Merchant Administrator's functionality is rewritten from "lock financial periods where permitted" to: *view financial-period status and, where the configured governance workflow requires it, approve or reject an exceptional period-reopen request; the Merchant Administrator does not execute routine period locks by default* (done in §4.5 / §13.10).

Finance can lock an eligible period; request/execute a controlled reopen per policy; supply a mandatory reason; respect maker-checker; review affected transactions before lock; and receive clear mutation-block responses after lock — all through the central `PeriodLockService`. Finance cannot mutate locked records outside the controlled reopen workflow, delete lock history, or self-approve a reopen where Merchant Administrator approval is required. Merchant Administrator can view all merchant period-lock statuses and lock/reopen reasons, approve/reject an exceptional high-risk reopen where configured, and receive lock-exception notifications; cannot edit underlying transactions merely because they approved a reopen, bypass Finance permissions, or perform routine Finance operations by default. Branch Manager views branch period status but cannot lock/reopen/mutate locked finance records. Front Office receives a clear blocked-state message on a locked-period mutation. HR/Personnel compensation or payout changes touching a locked period follow the approved correction workflow. Audit inspects lock creation, attempted violations, reopen requests, approvals, and resulting changes.

**Tests:** FinancePeriodLockCreate, FinancePeriodLockMutationBlock, FinancePeriodReopenReasonRequired, MerchantAdminExceptionalReopenApproval, MerchantAdminCannotRoutineLock, BranchManagerCannotLockPeriod, FrontOfficeLockedPeriodMutationBlocked, LockedPeriodAttemptAudited. **Acceptance:** Finance owns routine locks; Merchant Administrator limited to exceptional governance approval; all routed through PeriodLockService.

# B-C8. Promotional Targeting Data Model (Contradiction 8)

**Settled resolution.** Promotional targeting is implemented as explicit structured criteria, never a generic unvalidated `scope` string. Supported targets: `all_merchants`, `new_merchants`, `selected_merchants`, `specific_merchant`, `fixed_amount_billing_merchants`, `percentage_billing_merchants`, `fixed_plus_percentage_billing_merchants`, `selected_plans`. A reference to selected Merchant Administrators is a selection mechanism to identify their merchant accounts; the resulting discount applies to the **merchant billing account**, not differently to individual users who initiate payment.

`promotional_discounts` (amended in §18.2): `id, ulid, name, discount_type (percentage|fixed_amount), discount_value, currency nullable, starts_at, ends_at, target_scope, billing_mode_filter nullable, new_merchant_only boolean, plan_filter nullable, status (draft|scheduled|active|expired|cancelled), created_by, approved_by nullable, change_reason, created_at, updated_at`.

## 18.2c `promotional_discount_targets`

```text
id
promotional_discount_id
merchant_id nullable          (the billing target)
merchant_admin_user_id nullable (selection evidence only)
plan_id nullable
billing_mode nullable
selection_snapshot_json
selected_at
selected_by
```

**Promotion application snapshot.** Each affected billing invoice records: `promotional_discount_id, discount_type_snapshot, discount_value_snapshot, discount_amount_minor, target_scope_snapshot, eligibility_reason, applied_at`. A later promotion edit must not alter a discount already captured on an issued invoice.

**Account-user effects.** Super Administrator creates/schedules/activates/cancels/audits promotions and must see a target preview and count before activation. Merchant Administrator sees promotion name, type, value, dates, current invoice effect, and next-invoice estimate; cannot edit. Finance sees applied discount calculations/snapshots on billing invoices. Branch Manager and Front Office see the resulting amount due on permitted billing screens and cannot change eligibility. HR, Personnel, Audit have no promotion-config authority; Audit reviews creation/targeting/application within scope. **Tests:** PromotionAllMerchantsTarget, PromotionNewMerchantsTarget, PromotionSelectedMerchantsTarget, PromotionSpecificMerchantTarget, PromotionFixedBillingModeTarget, PromotionPercentageBillingModeTarget, PromotionPlanTarget, PromotionSelectionSnapshot, PromotionInvoiceSnapshot, PromotionUnauthorizedEdit. **Acceptance:** structured targeting records; promotions target merchant billing accounts, not individual payers.

# B-C10. Restored and Strengthened Requirements (Contradiction 10)

## B-C10.1 Audit Flagged-Event Status Schema

Flagged audit events use the status model `open, under_review, resolved, dismissed`, recording: `id, merchant_id, branch_id, source_audit_event_id, status, flag_reason, severity, created_by, created_at, assigned_reviewer_id, review_started_at, review_note, resolution_note, resolved_by, resolved_at, dismissed_by, dismissed_at, dismissal_reason, updated_at`. Allowed transitions: `open → under_review`, `open → dismissed`, `under_review → resolved`, `under_review → dismissed`; every transition audited. Audit may mutate only this audit-module metadata, never the underlying business record. Super Administrator sees platform-level flagged events; Merchant Administrator sees authorized merchant-level summaries; other roles only where expressly permitted. **Tests:** AuditFlaggedEventOpen, AuditFlaggedEventUnderReview, AuditFlaggedEventResolve, AuditFlaggedEventDismiss, AuditFlaggedEventTransitionValidation, AuditCannotMutateSourceRecord.

## B-C10.2 Front Office Search

Front Office can search authorized branch records by `client phone number, client name, appointment reference, invoice number, receipt number, queue position`. Search enforces branch scope and tenant scope, returns only authorized fields, paginates, rate-limits abusive requests, avoids cross-tenant inference, and avoids exposing full records through suggestions where not authorized. **Tests:** FrontOfficeSearchByPhone, FrontOfficeSearchByName, FrontOfficeSearchByAppointmentReference, FrontOfficeSearchByInvoiceNumber, FrontOfficeSearchByReceiptNumber, FrontOfficeSearchByQueuePosition, FrontOfficeSearchBranchScope, FrontOfficeSearchTenantIsolation.

## B-C10.3 Estimated-Wait Calculation

The estimated wait considers `active eligible personnel, personnel queue availability, service duration, number and order of waiting queue entries, estimated remaining duration of active sessions, branch operating state, personnel breaks or temporary unavailability`. At minimum, active personnel, service duration, and queue length are mandatory inputs. The value is operational guidance and is labelled an estimate. Front Office sees/communicates it; Branch Manager sees branch wait-time performance; Personnel sees their own relevant queue; Merchant Administrator sees summarized wait reports; Audit can inspect manual overrides; clients receive no login at launch. **Tests:** EstimatedWaitActivePersonnel, EstimatedWaitServiceDuration, EstimatedWaitQueueLength, EstimatedWaitPersonnelUnavailable, EstimatedWaitActiveSessionRemainingTime, EstimatedWaitOverrideAudit.

## B-C10.4 Private-by-Default File Storage

All uploaded and generated files shall be stored in private-by-default object storage. No tenant-owned, personnel, financial, billing, audit, invoice, receipt, report, or export file shall be publicly addressable. Access shall require server-side authorization and shall use short-lived signed URLs or authenticated streaming, enforcing tenant, branch, role, permission, billing-status, and own-scope rules as applicable. Also required: encryption in transit; encryption at rest; unpredictable storage object keys; expiring download access; revocation on access change; audit logging for sensitive exports/downloads; no permanent public bucket policies; no confidential-file indexing by public search engines. Personnel download only their own earnings statements; Audit exports remain branch-scoped, masked, reason-required, download-counted. **Tests:** PrivateObjectStorage, PublicObjectAccessDenied, SignedUrlExpiry, SignedUrlScope, PersonnelOwnStatementDownload, AuditExportSignedUrl, SensitiveDownloadAudited.

## B-C10.5 No Secrets in Frontend

No private credential, API secret, signing key, database/service-account credential, webhook verification secret, storage/SMS/email credential, or privileged integration token shall be embedded in frontend code, browser-accessible environment variables, HTML, source maps, local/session storage, or client-visible API responses. Browser apps contain only values explicitly classified public/publishable. Applies to M-Pesa, SMS, email, object storage, database, authentication, webhook verification, signed-URL generation, and internal service credentials. **Tests/controls:** FrontendSecretScan, SourceMapSecretScan, BrowserStorageSecret, ClientResponseSecretLeak, BuildArtifactSecretScan.

## B-C10.6 No Credentials or Reusable Secrets in Logs

Application, infrastructure, access, audit, error, tracing, and integration logs shall never contain passwords, Magic Link tokens, session tokens, authorization headers, private API keys, signing secrets, database/storage credentials, webhook secrets, complete signed URLs, or other reusable credentials. Sensitive personal, payment, salary, commission, and client-contact data shall be masked, tokenized, truncated, or omitted unless specifically required in an access-controlled audit record. Required: structured log redaction; authorization-header and Magic-link-token removal; M-Pesa credential/phone masking; payment-reference masking where full visibility is unnecessary; signed-URL query-string removal; salary/commission masking outside authorized audit context; automated redaction tests. **Tests:** LogAuthorizationHeaderRedaction, LogMagicLinkTokenRedaction, LogMpesaSecretRedaction, LogSignedUrlRedaction, LogPersonalDataMasking, LogCompensationDataMasking.

## B-C10.7 File-Upload Validation

Every file upload is validated server-side against a purpose-specific allowlist, including maximum size, detected MIME type, extension consistency, file signature, filename sanitization, storage quota, and malicious-content checks. Client-provided filenames are never used as storage object identifiers. Unsupported, executable, scriptable, malformed, oversized, or suspicious files are rejected. Image uploads are decoded and safely re-encoded where practical. Also required: purpose-specific allowlists; upload rate limiting; plan-entitlement-based tenant storage quotas; SVG rejection or sanitization; malware scanning for supported documents; no executable upload types; random server-generated object identifiers; safe content-disposition headers; audit records for sensitive uploads. **Tests:** UploadMimeValidation, UploadExtensionMismatch, UploadFileSignature, UploadOversizeBlocked, UploadExecutableBlocked, UploadSvgPolicy, UploadFilenameSanitization, UploadRandomObjectKey, UploadStorageQuota, UploadMalwareDetection.

## B-C10.8 Responsive-Design Implementation

Responsive behaviour is implemented primarily through standards-based responsive CSS (fluid layouts, media queries, container queries). The application shall not depend on user-agent strings, device names, or JavaScript device classification to determine core layout, permissions, or feature availability. JavaScript feature detection/measurement is used only where CSS cannot deliver the behaviour. A detected device type shall never determine authorization or plan entitlement. **Tests:** ResponsiveCssLayout, NoUserAgentLayoutDependency, NoDeviceBasedAuthorization, MobileNavigation, TabletLayout, DesktopLayout.

## B-C10.9 Browser Zoom and Reflow

The application remains operable and readable at 200% text enlargement and at high-zoom reflow. Content, controls, navigation, dialogs, validation messages, and form actions shall not overlap, clip, disappear, or require two-dimensional page scrolling, except for explicitly permitted data-table/visualisation containers. Applies to every role-specific landing page, get-started page, and workflow. **Tests:** BrowserZoom200Percent, HighZoomNavigation, HighZoomDialog, HighZoomFormValidation, HighZoomPrimaryActionVisibility, HighZoomTableContainer.

## B-C10.10 Horizontal Scrolling

Normal page content (navigation, forms, cards, dashboards, dialogs, alerts, primary actions) fits within the viewport without whole-page horizontal scrolling. Wide data tables, comparison grids, timelines, and similar two-dimensional components may scroll horizontally within a clearly bounded container where responsive column prioritisation cannot preserve meaning; such containers shall not cause whole-page horizontal overflow and shall be keyboard accessible, visually indicate additional columns, preserve row/column context, keep essential actions discoverable, and avoid focus traps. **Tests:** NoWholePageHorizontalOverflow, WideTableContainedScroll, ScrollableTableKeyboardAccess, ScrollableTableVisualIndicator, MobileFormReflow, MobileDialogReflow.

# B14. Account-User Impact Matrix

One row per contradiction; each cell states new visibility, new permitted actions, removed actions, read-only restrictions, approval duties, notifications, and landing/get-started/audit effects.

| # | Super Administrator | Merchant Administrator | Branch Manager | HR | Finance | Front Office | Personnel | Audit | Client-facing |
| - | ------------------- | ---------------------- | -------------- | -- | ------- | ------------ | --------- | ----- | ------------- |
| C1 | New: activate any billing mode; configure rates/tiers; review fee ledgers/disputes/reconciliation. Cannot edit merchant-client invoices or routinely mark merchant payments paid. Landing shows billing-mode + reconciliation exceptions. | New: select tier when percentage active; view fee accrual/invoiced/adjusted/disputed/paid; pay via M-Pesa; raise fee dispute. Cannot change rate/ledger. Landing shows active mode + fees. | New: view branch fee totals; pay billing invoice in recovery. Cannot configure tier/rates. | No fee-config; sees only preferred-fee-in-commission-basis info. | New: view fee reconciliation/liability; review adjustments/disputes; pay billing M-Pesa. Cannot change config/snapshots. | New: see fee line when tier shifts to client; explain with approved wording. Cannot change tier/percentage or remove the line without correction workflow. | See only fee info for own service/commission. | Review fee create/adjust/reverse/dispute/reconcile, masked, branch-scoped; cannot mutate. | Client may see a separate fee component on the invoice/receipt when the active tier shifts an amount to the client. |
| C2/C9 | Configure/schedule/cancel versioned plan prices; inspect history. | See current + scheduled price, effective date, next-invoice estimate (real time). Cannot edit prices. | See merchant amount due only. | None. | See captured price per invoice; future-price notice. | See amount due on permitted billing screen. | None. | Inspect price scheduling/activation/capture. | No direct effect; invoices keep captured price. |
| C3 | Monitors restriction exceptions; never the merchant payer. | Merchant-wide authorized history + billing recovery + pay. | Branch history + recovery + pay. | Historical staff/compensation read; no mutate; no pay by default. | Historical financial read; no new validation; pay via recovery. | Authorized history + simplified billing-payment route. | Own historical work/earnings only. | Branch-scoped historical review + flagged-event metadata only where policy permits during restriction; no new export. | No new client mutations possible while restricted. |
| C4 | None. | Oversight only. | Removed: preferred-personnel selection, queue/appointment transfer, invoice creation, payment recording. Retains catalogue/calendar/day/cash-up-submit + read-only eligibility. | Retains eligibility/assignment authority. | Validates payments (unchanged). | New/confirmed: owns selection, transfer, invoice creation, payment recording. | Assigned via Front Office/HR, not Branch Manager. | Logs Branch Manager mutation attempts. | Client served per Front Office selection. |
| C5 | None. | View Growth cash-up status/summaries; does not routinely approve. | Growth: prepare/submit cash-up; cannot approve own; cannot edit approved. | None. | Growth: one-step approve/reject with reason; no period lock on Growth. | Records payments feeding cash-up. | None. | Review submit/approve/reject/amend history. | None. |
| C6 | None. | Approve/reject high-value payout runs only; no prepare/mark-paid. Notification on high-value pending. | None by default. | Prepare/update-draft/submit/cancel-draft payout runs; cannot approve/mark-paid. | Verify, approve standard, reject, mark-paid; cannot prepare+approve same run. | None by default. | View own payout results/statements; raise queries. | Review all payout events; cannot mutate. | None. |
| C7 | None. | View period status; approve/reject exceptional reopen where configured; no routine locks. Notification on lock exceptions. | View branch period status; cannot lock/reopen/mutate locked records. | Compensation/payout changes into locked periods follow correction workflow. | Owns routine lock/controlled reopen via PeriodLockService with reason; no self-approve where Admin approval required. | Blocked-state message on locked-period mutation. | Locked-period earnings changes follow correction workflow. | Inspect lock creation, attempts, reopen requests/approvals. | None. |
| C8 | Create/schedule/activate/cancel promotions; see target preview + count. | See promotion name/type/value/dates/effect/next estimate; cannot edit. | See resulting amount due. | None. | See applied discount snapshots on invoices. | See resulting amount due. | None. | Review promotion create/target/apply. | Discount affects merchant billing account, not individual payers. |
| C10 | Platform-level flagged events; security/storage/upload governance visibility. | Authorized merchant flagged-event summaries; landing/get-started usable at 200% zoom. | Branch wait-time + flagged info where permitted. | Staff uploads validated; compensation masked in logs. | Finance exports private/signed/audited. | Search by listed fields; communicate estimated wait. | Download only own statements; own served-client SMS. | Flagged-event lifecycle; masked exports; no source mutation. | No client login at launch; client data masked in logs/exports. |

# B15. Plan-Entitlement Impact Matrix

Corrections must not make Starter unusable or collapse plan differentiation; all entitlements are server-enforced.

| Capability | Starter | Growth | Pro Branch | Multi-Branch |
| ---------- | ------- | ------ | ---------- | ------------ |
| Core operations (clients, services within limit, queue, appointments, sessions, invoices, payment recording, validated receipts, basic reports, minimal audit, 1 branch, Starter staff limits) | Yes | Yes | Yes | Yes |
| HR lifecycle, eligibility, availability, basic commissions, role separation, payment validation | — | Yes | Yes | Yes |
| Basic cash-up (submit + single-step Finance approve/reject; record-level closure) | — | Yes | Yes | Yes |
| Standard reports, limited exports | — | Yes | Yes | Yes |
| Full financial-period locks + controlled reopening | — | — | Yes | Yes |
| Multi-stage cash-up governance, advanced cash-up exports | — | — | Yes | Yes |
| Full audit dashboard, sensitive finance exports, advanced commission reconciliation | — | — | Yes | Yes |
| Refund + dispute workflows | — | — | Yes | Yes |
| Payout runs (HR prepare → Finance verify/approve → mark-paid; high-value Merchant Admin approval) | — | Basic | Yes | Yes |
| Percentage-fee visibility surfaces (when percentage mode active) | View own | View | Advanced | Advanced |
| Multiple branches, extra-branch billing, centralized dashboards, branch comparison, multi-branch reports/finance/audit, multi-branch period governance | — | — | — | Yes |

# 18.2d Platform-Fee, Promotion, and Billing-Line Schemas (full)

### `platform_fee_ledger_entries`

```text
id
ulid
merchant_id
branch_id
merchant_client_invoice_id
merchant_client_invoice_item_id nullable
billing_mode_snapshot
service_fee_tier_snapshot
fee_basis_type enum: service_price | invoice_item_total | invoice_subtotal | net_after_discount | validated_paid_amount
fee_basis_amount_minor
percentage_rate_snapshot
gross_platform_fee_minor
client_shifted_amount_minor
merchant_liability_minor
currency
status enum: provisional | billable | aggregated | settled | reversed | adjusted
effective_configuration_id
created_at
billable_at nullable
aggregated_billing_invoice_id nullable
reversed_entry_id nullable
adjustment_reason nullable
```

### `platform_fee_adjustments`

```text
id
original_platform_fee_entry_id
adjustment_type enum: void_reversal | full_refund_reversal | partial_refund_adjustment | correction
adjustment_amount_minor
reason
source_refund_id nullable
source_void_id nullable
created_by
approved_by nullable
created_at
```

### `platform_fee_disputes`

```text
id
merchant_id
branch_id
platform_fee_entry_id
reason
evidence_attachment nullable
status enum: open | under_review | resolved | rejected | escalated
assigned_reviewer_id nullable
resolution_note nullable
resolved_by nullable
resolved_at nullable
created_at
updated_at
```

### `billing_invoice_lines`

```text
id
billing_invoice_id
line_type enum: subscription_plan_charge | percentage_platform_fee | fixed_platform_fee | extra_branch_charge | sms_charge | other_approved_add_on | discount | credit | adjustment
description
amount_minor
currency
source_reference_id nullable
created_at
```

All amounts are integer minor units. `billing_reconciliation_records` link M-Pesa settlements (subscription and platform-fee billing invoices) to cleared liabilities.

# B17. Permission and Policy Amendments (consolidated)

New/changed permissions: billing-mode and platform-fee config, plan-price management, and promotion management (Super Administrator); percentage-fee view + fee-dispute raise (Merchant Administrator, Finance); fee-line read (Front Office); branch fee totals (Branch Manager). Front Office gains `queue.create/assign/transfer`, `appointments.create/assign/transfer`, `preferred_personnel.select`, `invoices.create`, `customer_payments.record`; Branch Manager must not hold these. Payout permissions split per §12.16 (HR create/submit/cancel-draft; Finance verify/approve_standard/reject/mark_paid; Merchant Admin approve_high_value/reject_high_value; Personnel own_payouts.view; Audit compensation.audit.view/export). Period-lock execution permission is Finance-owned; Merchant Administrator holds only an exceptional-reopen-approval permission. Every corrected workflow enforces, server-side: role check, permission check, tenant check, branch-scope check, own-scope check (where applicable), billing-status check, plan-entitlement check, financial-period-lock check (where applicable), validation, idempotency (for financial processing), and audit logging.

# B18. API Amendments (consolidated)

Added/changed endpoints: neutral payout routes `/api/v1/payout-runs/*` with verify/approve-standard/approve-high-value/mark-paid (replacing Finance-namespaced payout routes); versioned plan-price endpoints `/api/v1/platform/billing/plan-prices` (create draft/scheduled, activate-by-scheduler, cancel, history); promotion-target endpoints `/api/v1/platform/promotions/{promotion}/targets` with preview/count; percentage-fee endpoints `/api/v1/platform/billing/platform-fees` and dispute endpoints `/api/v1/billing/platform-fee-disputes` (merchant) / `/api/v1/platform/billing/platform-fee-disputes` (Super Admin); restricted-mode read endpoints and billing-recovery endpoints (§20.2) shared identically by `read_only_grace` and `suspended_billing`; authorized signed-download endpoints for private files. Front Office mutation endpoints (queue/appointment assignment + transfer, invoice creation, payment recording) are policy-protected and denied to Branch Manager with an audit event. M-Pesa webhook routes (§20.3) remain session-less with security validation. All endpoints enforce the §B17 checks.

# B19. Frontend and Landing-Page Amendments (consolidated)

Super Administrator: billing-mode configuration, percentage-rate/tier-allocation config, versioned plan-price scheduling, promotion targeting with preview/count, reconciliation-exception console. Merchant Administrator: real-time pricing/price-schedule notices, tier selection when percentage active, platform-fee dashboard, high-value payout approval, period-reopen approval, M-Pesa billing payment. Branch Manager: removal of preferred-personnel selection and queue/appointment transfer controls; retains catalogue/calendar/day/cash-up-submit and read-only eligibility; Growth cash-up submit with terminal status display. Finance: payout verify/approve-standard/mark-paid, Growth single-step cash-up approval, period-lock/controlled-reopen, fee reconciliation. Front Office: preferred-personnel selection, queue/appointment transfer, invoice creation, payment recording, listed search fields, labelled estimated wait. HR: payout preparation/submission. Personnel: own-payout view, own-statement download (private signed URL), own-served-client SMS. Audit: flagged-event lifecycle (open/under_review/resolved/dismissed), masked branch-scoped exports. All landing and get-started pages reflect these per-role changes, are CSS-responsive, remain usable at 200% zoom, and avoid whole-page horizontal scroll.

# B20. Audit-Event Amendments (consolidated)

Add/confirm audit events for: billing-mode change; platform-fee config change; platform-fee entry create/billable/aggregate/settle/reverse/adjust/dispute; plan-price create/schedule/activate/cancel and invoice price capture; promotion create/target/activate/cancel/apply; payout submit/verify/approve-standard/approve-high-value/reject/mark-paid/cancel/adjust; period lock/controlled-reopen/exceptional-reopen-approval and locked-period mutation attempts; Branch Manager mutation attempts on read-only endpoints; restricted-mode mutation attempts during grace/suspension; flagged-event open/under_review/resolve/dismiss; sensitive file upload/export/download. All records are append-only, tamper-evident (record/chained hash), carry mandatory before/after values on sensitive changes, are branch-scoped for Merchant Audit with field-level masking, and never permit a role to rewrite a source business record via audit-module metadata.

# B21. Background Jobs and Scheduled Processing (consolidated)

Scheduler/queue jobs: subscription invoice generation per cycle (capturing the active versioned price); percentage-fee aggregation into Servana billing invoices; trial-expiry → read-only-grace → suspended-billing transitions; the single shared overdue escalation engine (reminders day 3/7/14, suspension after the configured window — no separate dunning engine); salary accrual per pay period; M-Pesa reconciliation (subscription and platform-fee billing invoices) with exception raising; scheduled-price activation at `effective_from`; SMS cost rollups onto branch subscription billing; automatic billing-only reactivation after validated payment (never for fraud/security/legal/manual/deactivation). No financial value, fee, price, trial day, grace day, or suspension window is hardcoded; all are read from `platform_billing_settings` / `subscription_plan_prices`.

# B22. Migration and Implementation Steps (consolidated)

Phase 1 — Specification cleanup: remove future-only percentage language; canonical status names; corrected role language; corrected plan-entitlement descriptions; restore omitted requirements. Phase 2 — Database migration: create `subscription_plan_prices`; create `platform_fee_ledger_entries`, `platform_fee_adjustments`, `platform_fee_disputes`, `billing_invoice_lines`, `billing_reconciliation_records`; add `promotional_discount_targets` and structured `promotional_discounts.target_scope`; add payout approval/verify fields, `is_high_value`, and expanded status enum; add flagged-audit-event status fields; add file metadata/access-control fields; migrate existing plan prices into versioned active records without duplicating the source of truth. Phase 3 — Backend policies: billing-mode services; fee calculation/reversal; shared overdue engine; billing-status allowlist (grace = suspended parity); payout maker-checker; period-lock ownership; private file-access service; upload validation; log-redaction middleware. Phase 4 — API: neutral payout routes; versioned plan-price endpoints; promotion-target endpoints; percentage-fee/dispute endpoints; restricted-mode + billing-recovery endpoints; authorized signed-download endpoints. Phase 5 — Frontend: per §B19. Phase 6 — Audit/monitoring/security: audit every config/fee/price/promotion/payout/period-lock change; scan frontend bundles and source maps for secrets; test log redaction; verify private storage. Phase 7 — Automated regression: run all new and existing suites; the migration is not complete while any contradictory legacy field, route, permission, or UI control remains active.

# B23. Automated Tests (consolidated)

All suites from §26 plus, at minimum, the C1–C10 suites enumerated in each resolution above: percentage-billing/platform-fee (16); plan-price source-of-truth and scheduled-price (18); allowlist parity and reactivation (11); Front Office vs Branch Manager assignment (7); Growth/Pro/Multi cash-up (7); payout maker-checker (12); period-lock ownership (8); promotion targeting (10); audit flagged-event (6); Front Office search (8); estimated wait (6); private storage (7); frontend-secret scans (5); log-redaction (6); upload validation (10); responsive CSS (6); zoom/reflow (6); horizontal scroll (6). Each suite includes, where applicable, positive, negative, authorization-denial, tenant-isolation, branch-scope, own-scope, validation, billing-read-only, suspended-recovery, and audit-assertion cases. No migration is marked complete while contradictory legacy tests, routes, or fields remain.

# B24. Consolidated Acceptance Criteria (contradiction package)

The package is acceptable only when all 48 hold: (1) fixed, percentage, fixed-plus-percentage are launch-supported; (2) no section describes percentage billing as future-only; (3) every percentage fee links to its source merchant-client invoice; (4) fee basis, rate, tier, monetary allocation are snapshotted; (5) fee reversals and disputes are traceable; (6) platform-fee and subscription invoices share billing and overdue infrastructure; (7) merchant-to-Servana billing invoices are payable through M-Pesa; (8) global billing settings and plan prices have separate authoritative tables; (9) `subscription_plan_prices` is the only plan-price source of truth; (10) scheduled prices are versioned; (11) issued invoices preserve captured prices; (12) scheduled price changes do not prorate current cycles; (13) scheduled price changes appear on Merchant Administrator screens immediately; (14) no automatic grandfathering at launch; (15) `read_only_grace` and `suspended_billing` use the settled historical read-only allowlist; (16) all operational mutations blocked in those statuses; (17) billing-only recovery remains available; (18) non-billing suspensions are not automatically removed; (19) Branch Manager has no preferred-personnel selection or queue-transfer authority; (20) Front Office owns permitted operational assignment and movement; (21) Growth cash-up reaches an approved/rejected terminal state; (22) Growth does not receive Pro's full period-lock system; (23) HR prepares payout runs; (24) Finance verifies and marks approved payout runs paid; (25) Merchant Administrator approves high-value payout runs; (26) maker-checker prevents self-approval; (27) Finance owns routine period locks; (28) Merchant Administrator's period role is limited to exceptional governance approval; (29) promotions have structured targeting records; (30) promotions target merchant billing accounts, not individual payers; (31) audit flagged events have complete statuses and metadata; (32) Front Office search fields are explicitly listed; (33) estimated wait explicitly uses active personnel, service duration, and queue length; (34) files are private by default; (35) file access uses server-side authorization and expiring access; (36) no private secrets in frontend-delivered assets or browser storage; (37) no reusable credentials in logs; (38) all uploads validated server-side; (39) responsive design does not rely on device-name/user-agent classification; (40) the application remains usable at 200% zoom; (41) whole-page horizontal scrolling is prohibited for normal content; (42) contained scrolling permitted only for inherently wide components; (43) every account user has a role-specific landing page; (44) every account user has a role-specific get-started page; (45) every correction is enforced server-side; (46) every correction has positive/negative/authorization/tenant/branch/own-scope/audit tests where applicable; (47) unaffected older-scope safeguards remain present; (48) no contradictory duplicate rule remains anywhere in the document.

# B25. Final Contradiction and Preservation Verification

| Contradiction | Resolved? | Authoritative rule | Conflicting text removed? | Data model updated? | Permissions updated? | APIs updated? | UI updated? | Account impacts documented? | Tests added? | Remaining ambiguity |
| ------------- | --------- | ------------------ | ------------------------- | ------------------- | -------------------- | ------------- | ----------- | --------------------------- | ------------ | ------------------- |
| C1 Percentage launch vs future | Yes | §6A — three launch-active modes, full fee lifecycle | Yes | Yes (§18.2d) | Yes | Yes | Yes | Yes (§B14) | Yes | None |
| C2 Plan-price source of truth | Yes | §6B/§18.2b — `subscription_plan_prices` authoritative | Yes | Yes | Yes | Yes | Yes | Yes | Yes | None |
| C3 Grace/suspension allowlists | Yes | §B-C3 — identical allowlist + block | Yes | n/a (status fields kept separate) | Yes | Yes | Yes | Yes | Yes | None |
| C4 Branch Manager preferred-personnel | Yes | §B-C4/§5.4 — Front Office only | Yes | n/a | Yes | Yes | Yes | Yes | Yes | None |
| C5 Growth cash-up | Yes | §B-C5/§7.5 — basic terminal closure | Yes | n/a | Yes | n/a | Yes | Yes | Yes | None |
| C6 Payout ownership/routes | Yes | §10.5/§B-C6 — neutral routes + maker-checker | Yes | Yes (§12.15) | Yes | Yes | Yes | Yes | Yes | None |
| C7 Period-lock ownership | Yes | §B-C7/§13.10 — Finance routine, Admin exceptional | Yes | n/a | Yes | Yes | Yes | Yes | Yes | None |
| C8 Promotion targeting | Yes | §18.2c/§B-C8 — structured targets | Yes | Yes | Yes | Yes | Yes | Yes | Yes | None |
| C9 Scheduled prices | Yes | §6B — versioned prices, no grandfathering | Yes | Yes | Yes | Yes | Yes | Yes | Yes | None |
| C10 Restored requirements | Yes | §B-C10 — audit/search/wait/storage/secrets/upload/responsive/zoom/scroll | Yes | Yes (flagged-event, file metadata) | Yes | Yes | Yes | Yes | Yes | None |

**Final consistency scan — confirmed absent from the document:** "future percentage fee" / future-only percentage language; duplicate plan-price fields; differing grace vs suspension allowlists; Branch Manager queue/appointment/preferred-personnel mutation; Growth cash-up without a terminal review state; Finance-only payout preparation namespace; Merchant Administrator routine period-lock execution; unstructured promotion `scope` strings; scheduled pricing without price versions; missing security, storage, upload, accessibility, or responsive requirements. **Preservation confirmed:** tenant isolation, branch scope, Personnel own-scope, self-registration-only merchant creation, no manual Super Administrator subscription-payment recording, offline merchant-client payments, no hardcoded financial values, the single shared overdue engine, and no mid-cycle proration all remain intact.

*End of PART B — Contradiction-Resolution Amendment Package.*
