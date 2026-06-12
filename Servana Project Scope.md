# Servana by Citrus — Platform Project Scope

**Product:** Servana by Citrus
**Owner/Operator:** Citrus Labs Limited
**Platform Type:** Multi-tenant SaaS web platform
**Target Market:** Service-based SMEs including barbershops, salons, massage parlours, spas, grooming studios, beauty parlours, and similar appointment/walk-in businesses.

This scope is based on the uploaded **Servana Platform Overview**, which defines the product as a multi-tenant SaaS for service-based SMEs and positions it as a lightweight operating system where client visits, services rendered, invoices, staff commissions, and platform fees are traceable in real time.  It also follows the **Product Technical Details v.2** requirement for a production-ready, scalable, secure, multi-user SaaS application with strong separation of concerns, secure authentication, predictable authorization, responsive UI behavior, and long-term scalability. 

---

# 1. Platform Purpose

Servana by Citrus exists to help service-based SMEs digitize, control, and audit their day-to-day operations.

The SaaS web app shall enable merchants to manage:

1. Merchant onboarding.
2. Merchant branches.
3. Staff and personnel access.
4. Front-office operations.
5. Client records.
6. Walk-ins.
7. Appointments.
8. Queue management.
9. Service delivery tracking.
10. Client choice of next available personnel or preferred personnel.
11. Invoice generation.
12. Offline payment recording.
13. Receipt generation.
14. Personnel commission visibility.
15. Client data protection and contact access control.
16. Platform fee calculation and service fee tier invoice pricing.
17. Merchant financial visibility.
18. Audit-ready operational and financial records.

The platform shall not be treated as only a booking app, POS system, or invoicing tool.

**Core positioning:**

> **Servana by Citrus is a service-operations SaaS platform that helps service-based SMEs run clients, staff, services, queues, invoices, commissions, and audit records from one secure web dashboard.**

---

# 2. Core Product Principles

## 2.1 Multi-Tenant SaaS Principle

Each merchant operates as an isolated tenant.

A merchant’s data must never be accessible, inferable, editable, exportable, or enumerable by another merchant.

## 2.2 Offline Payment Principle

Payments are made **offline/off-platform**.

Servana records payment method, amount, reference, status, and validation details, but does not process client-to-merchant payments inside the platform.

Supported offline payment methods:

* Cash
* M-Pesa
* Bank transfer
* Card terminal
* Voucher
* Split payment
* Other merchant-defined offline method

## 2.3 Merchant Access Principle

All Merchant account users log in by **Magic Link sent to email** after the system verifies that the email address is active under that user’s respective **Merchant Administrator Account**.

A merchant user must not log in merely because their email exists. The login must check:

1. The user email exists.
2. The user belongs to the correct merchant tenant.
3. The user account is active.
4. The user role is active.
5. The user has not been suspended.
6. The user is assigned to the correct branch, where branch access applies.
7. The Magic Link is valid, unused, and unexpired.

---

# 3. Platform Users and Account Types

## 3.1 Super Administrator Account

### Purpose

The Super Administrator is the Citrus Labs Limited platform-owner role. This account governs the SaaS ecosystem across all merchants at platform level.

The Super Administrator does **not** create the merchant business account and does **not** create the first Merchant Administrator. The Merchant Administrator self-registers the business account, the system creates the merchant tenant, and the registering user becomes the **Merchant Owner / Merchant Administrator**.

### Core Functionalities

* Configure platform-wide settings.
* Configure the platform service fee rules.
* Configure billing cycles.
* Configure the extra fee for preferred merchant personnel waiting.
* View all merchants.
* View platform-wide reports.
* View platform fee ledgers.
* View platform-level audit logs.
* Monitor suspicious usage.
* Manage internal Citrus Labs Limited platform roles.
* Control platform-level feature flags.
* Govern merchant suspension, deactivation, billing enforcement, abuse response, and platform-policy enforcement.
* Govern platform-level activation/status rules without manually approving Merchant Administrator self-registration.

### Explicit Exclusions

The Super Administrator must not:

* Create merchant tenants on behalf of merchants.
* Create the first Merchant Administrator.
* Approve Merchant Administrator self-registration before dashboard access.
* Require merchant compliance submission, KYC submission, or activation documents as part of Merchant Administrator self-registration.
* Review merchant compliance status as a Super Administrator workflow.
* Perform merchant operations such as serving clients, assigning service sessions, manipulating branch queues, configuring branch services, editing merchant invoices, validating merchant payments, or editing merchant receipts outside controlled governance workflows.

### Governance Note

Where this scope refers to platform compliance, it means automated platform-policy enforcement, billing compliance, abuse controls, and suspension rules. It does not mean merchant KYC/compliance submission or Super Administrator approval of merchant registration.

---

## 3.2 Merchant Administrator Account / Merchant Owner Account

### Purpose

The Merchant Administrator and Merchant Owner are the same account type. They are not split.

The Merchant Administrator is the registering business owner, manager, or authorized operator of a merchant business. The Merchant Administrator self-registers the business account, after which the system creates the merchant tenant and assigns that registering user as **Merchant Owner / Merchant Administrator**.

### Self-Registration Rule

The onboarding architecture shall follow this corrected rule:

```text
Merchant Administrator self-registers the business account.
The system creates the merchant tenant.
The system assigns the registering user as Merchant Owner / Merchant Administrator.
Super Administrator governs suspension, billing, platform fees, abuse controls, and platform-level oversight.
Merchant Administrator dashboard access is not held pending Super Administrator manual activation.
```

### First-Time Setup Before Dashboard Access

After Merchant Administrator account creation, the Merchant Administrator shall complete first-time setup before accessing the Merchant Administrator dashboard.

Required first-time setup steps:

1. Select the merchant service fee tier.
2. Complete and manage the merchant profile.
3. Create at least one Merchant Branch account and complete the Merchant Branch profile.
4. Add initial staff email addresses for:
   * Merchant Branch account user.
   * Merchant Human Resource account user.
5. Select the specific Merchant Branch of operation for each initial staff email address. Where only one Merchant Branch exists, the platform shall auto-select that branch.
6. Trigger welcome emails explaining Magic Link login for the added Merchant Branch account user and Merchant Human Resource account user.
7. After completion, redirect the Merchant Administrator to the Merchant Administrator dashboard.

### Merchant Service Fee Tier Selection

The service fee tier affects **merchant-client invoice pricing only**. Servana does not process merchant-client-to-merchant payments. The platform shall still generate the Citrus platform fee invoice during the Super Administrator-defined invoice generation period using the normal platform fee amount. The selected tier does not reduce the merchant’s platform fee liability.

Assumption used in examples:

```text
Service price set by Merchant Branch account user: KES 500
Platform service fee set by Super Administrator: KES 70
```

| Service Fee Tier | Merchant-client invoice amount | Merchant platform fee liability | Rule |
| ---------------- | ------------------------------ | -------------------------------- | ---- |
| Customer Centric | KES 500 | KES 70 | The client pays only the service price. The merchant absorbs the full Citrus platform service fee. |
| Split Tier | KES 535 | KES 70 | The client invoice includes 50% of the platform fee. The merchant remains liable for the full platform service fee invoice. |
| Business Centric | KES 570 | KES 70 | The client invoice includes the full platform fee. The merchant remains liable for the full platform service fee invoice. |

### Core Functionalities

* Self-register the merchant business account.
* Complete and manage the merchant profile.
* Select and update the merchant service fee tier where allowed.
* Create Merchant Branch accounts.
* Complete and manage Merchant Branch profiles.
* Add only the Merchant Branch account user email address and Merchant Human Resource account user email address during Merchant Administrator-controlled staff setup.
* View all Merchant Branch accounts created under that Merchant Administrator.
* View all merchant staff under each created Merchant Branch account.
* View, activate, suspend, remove, and delete merchant staff account users within the Merchant Administrator’s own merchant tenant, subject to branch debt and historical-record protection rules.
* View, activate, suspend, and delete any Merchant Branch account user only after clearing all platform fee debts for the specific branch first.
* View merchant-level revenue reports in real time across all Branch accounts created by the Merchant Administrator.
* View branch revenue performance for today, this week, last month, and the last 3 months.
* View each Branch’s services and service pricing.
* View each service’s revenue performance within each Branch.
* View staff performance cumulatively per branch and per individual staff.
* View merchant-level platform fee records.
* Receive the daily branch day-close report by email in PDF format.
* Receive the daily branch cash-up and reconciliation report by email in PDF format.
* Lock financial periods where required and where permitted.
* Suspend inactive or abusive merchant users while preserving historical records and audit logs.

### Explicit Exclusions

The Merchant Administrator must not:

* Create or approve account users apart from adding Merchant Branch account user email addresses and Merchant Human Resource account user email addresses in the Merchant Administrator’s own staff setup flow.
* Link Merchant Personnel to any account.
* Configure services or service pricing at Merchant Administrator level.
* Configure branch personnel assignments.
* Configure Merchant Personnel commissions directly.
* Control whether Front Office users can record payments, issue receipts, or only submit payment records for Finance validation.
* Assign personnel to branches. Personnel assignment is handled by the Merchant Human Resource account within its branch scope.

### Inactivity Rule

Inactive Merchant Administrator accounts shall be suspended after 3 months of consecutive no usage.

Inactive Merchant Administrator accounts shall be deleted after 6 months of consecutive no usage, inclusive of all merchant branch accounts and their respective merchant staff accounts within that Merchant Administrator account.

Consecutive no usage means the Merchant Administrator has not made any platform fee payment within the relevant period.

Historical records, financial records, receipts, invoices, audit logs, and legally necessary accounting records must be preserved or archived according to the platform retention policy and must not be silently destroyed.

### Authentication Rule

Merchant Administrator logs in via Magic Link sent to email.

---

## 3.3 Merchant Branch Account

### Purpose

A Merchant Branch represents a physical or operational business location created by the Merchant Administrator.

Examples:

* Westlands branch.
* Kilimani salon branch.
* CBD barbershop branch.
* Spa branch inside a hotel.
* Massage parlour branch.

The Merchant Branch account user manages only the specific Branch account they have been added into. They do not manage external branches and do not create Merchant Branches.

### Core Functionalities

* View branch dashboard.
* Manage the specific branch profile.
* Manage the specific branch operating hours and branch operating calendar.
* Configure services and pricing within the specific Branch account.
* Configure branch service availability.
* View staff activity for the specific branch only.
* View staff performance for the specific branch only, per individual staff.
* View branch queue.
* View branch appointments.
* View branch reports.
* View branch revenue.
* View branch invoices.
* View branch receipts.
* View branch payment records.
* View branch-level audit logs.
* Transfer affected queue entries and appointments from an unavailable Merchant Personnel user to another eligible Merchant Personnel user.
* Handle branch queue configuration.
* Handle branch appointment controls.
* Run branch day opening and day closing workflow.
* Submit branch cash-up and reconciliation records.

### Explicit Exclusions

The Merchant Branch account user must not:

* Create Merchant Branch accounts.
* Manage other branches.
* Assign merchant users to branches.
* Handle branch-scoped access permissions.
* Perform Merchant Human Resource personnel assignment duties.
* Create, activate, suspend, or deactivate merchant staff users.
* Override HR-controlled personnel service eligibility.

### Branch Status Rules

| Branch Status | Operational Effect |
| ------------- | ------------------ |
| Active | The Branch can accept appointments, walk-ins, queues, invoices, payments, and service sessions. |
| Suspended | The Branch cannot accept new walk-ins or appointments. Historical records remain visible. |
| Archived / Closed | The Branch is no longer operational and cannot be closed or archived while live operational records exist. |

A Branch can be suspended because of billing issues, abuse, platform-policy enforcement, or Merchant Administrator action.

### Minimum Branch Profile Fields

| Field | Requirement |
| ----- | ----------- |
| Branch name | Human-readable name, e.g. Kilimani Branch. |
| Branch code | Unique merchant-level code, e.g. KIL-001. |
| Physical address | Required. |
| Town/city/area | Required for reporting and filtering. |
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

### Read-Only HR-Controlled Personnel Visibility

The Merchant Branch account user shall have real-time read-only visibility into the following HR-controlled features for the specific Branch only:

| Feature | Requirement |
| ------- | ----------- |
| Assign personnel to branch | Required as a visible HR-controlled state, not editable by Merchant Branch account user. |
| Personnel availability schedule | Working days, shifts, breaks, unavailable times. |
| Temporary unavailability | Sick leave, emergency leave, no-show, break. |
| Active/inactive for queue | Personnel can be available for appointments but unavailable for walk-ins, or vice versa. |
| Skill/service eligibility | Personnel shown only for services they can perform. |
| Reassignment rules | If personnel becomes unavailable, affected queue entries and appointments must be handled. |

### Operational Reassignment Rule

When a Merchant Personnel user becomes unavailable, the Merchant Branch account user may transfer affected queue entries and appointments to another eligible Merchant Personnel user. This is an operational continuity action and does not grant HR personnel-assignment authority.

### Branch Queue Configuration

| Feature | Requirement |
| ------- | ----------- |
| Queue open/close | Branch can open or close walk-in queue. |
| Queue capacity | Maximum waiting clients per branch or per personnel. |
| Assignment mode | Next available, manual assignment, or preferred personnel. |
| Queue cancellation reason | Required. |
| No-show handling | Required. |
| Estimated wait calculation | Must consider active personnel, service duration, and queue length. |
| Override audit | Every reassignment or preferred-personnel override logged. |

### Branch Appointment Controls

| Feature | Requirement |
| ------- | ----------- |
| Appointment availability | Based on branch hours, service duration, and personnel availability. |
| Cancellation reason | Required. |
| No-show marking | Required. |
| Check-in workflow | Appointment becomes active only after check-in. |
| Conflict prevention | Prevent double-booking personnel. |
| Branch closure protection | No appointments accepted during closure periods. |

### Branch Day Opening and Closing Workflow

Required workflow:

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
Review cash/offline totals
Submit day-close record
```

Required statuses:

* Not opened.
* Open.
* Paused.
* Closed.
* Reopened with reason.

Risk if omitted: **60%–75% likelihood** of weak daily accountability, especially for cash-heavy businesses.

The Merchant Administrator shall receive this report daily by email in PDF format.

### Branch Cash-Up and Reconciliation

Because Servana records offline payments, branch reconciliation is critical.

| Feature | Requirement |
| ------- | ----------- |
| Daily branch payment summary | Cash, M-Pesa, bank transfer, card terminal, voucher, split payment. |
| Expected vs recorded amount | Compare invoices against payment records. |
| Pending validation list | Show payments not yet approved. |
| Discrepancy notes | Required where totals do not match. |
| Finance review | Finance validates or flags branch cash-up. |
| Period lock | Closed branch days cannot be edited without approval. |

Risk if omitted: **65%–80% likelihood** of payment disputes, cash leakage, and unreliable reports.

The Merchant Administrator shall receive this report daily by email in PDF format.

### Branch Invoice and Receipt Numbering Rules

Servana shall use merchant-wide uniqueness with optional branch prefix for readability.

Example:

```text
KIL-INV-000124
KIL-RCT-000124
```

| Feature | Requirement |
| ------- | ----------- |
| No duplicate invoice numbers | Enforced at database level. |
| No duplicate receipt numbers | Enforced at database level. |
| Branch prefix | Optional but recommended. |
| Void preservation | Voided invoices keep their number. |
| Audit trail | Number generation logged for sensitive failures. |

Risk if omitted: **45%–60% likelihood** of finance and audit confusion.

### Branch-Level Dashboard Cards

The Branch dashboard shall include:

* Today’s appointments.
* Today’s walk-ins.
* Active queue.
* Clients waiting.
* Clients in service.
* Completed sessions.
* Unpaid invoices.
* Pending payment validations.
* Receipts issued.
* Today’s revenue.
* Payment method breakdown.
* Personnel currently active.
* Queue delays.
* No-shows.
* Cancelled sessions.

Risk if omitted: **40%–55% likelihood** that branch users depend on scattered screens instead of managing the branch in real time.

### Branch Audit Events

Branch audit logs must capture:

* Branch created.
* Branch profile edited.
* Branch status changed.
* Operating hours changed.
* Special closure added.
* Service enabled/disabled for branch.
* Personnel assigned/removed by authorized HR workflow.
* User granted/removed branch access by authorized HR workflow.
* Queue opened/closed.
* Queue reordered.
* Appointment created/rescheduled/cancelled/no-showed.
* Service session started/completed/cancelled.
* Invoice created/voided.
* Payment recorded/validated/rejected.
* Receipt generated.
* Branch day opened/closed/reopened.
* Cash-up submitted/reviewed.

Risk if omitted: **50%–70% likelihood** of unresolved disputes and weak accountability.

### Branch Closure and Archival Protection

A branch must not be closed or archived while live operational records exist.

Closure or archival must be blocked where any of the following exist:

* Active queue entries.
* In-progress service sessions.
* Unpaid invoices.
* Pending payment validations.
* Unissued receipts for validated payments.
* Pending appointment check-ins.
* Unclosed branch day.
* Unresolved cash-up discrepancy.

Risk if omitted: **45%–65% likelihood** of broken records and abandoned operational workflows.

### Implementation Note

A branch should be implemented as both:

1. A **branch entity** in the data model.
2. A **branch access scope** for merchant users.

This avoids confusing a branch location with a human user while still allowing branch-specific authorization.

---

## 3.4 Merchant Human Resource Account

### Purpose

The Merchant Human Resource Account manages staff identity, employment status, branch-scoped assignment, role assignment, personnel service eligibility, availability, staff invitation, and staff access lifecycle visibility under the specific Branch in which the HR user has been assigned.

A Merchant Human Resource account user cannot assign merchant staff to other Branches, even when the other branches are under the same Merchant Administrator. The Merchant Human Resource account user can only assign other merchant staff to accounts within the same Branch as the Merchant Human Resource account.

### Staff Creation Rule

The Merchant Human Resource account user creates staff by:

1. Adding the staff email address.
2. Selecting the specific Merchant account type:
   * Merchant Personnel.
   * Merchant Front Office.
   * Merchant Finance.
   * Merchant Audit.
3. Selecting the staff member’s specific role within the selected account type, e.g. Barber, Massage Therapist, Hairdresser, Stylist, Beautician, Cosmetologist.
4. Assigning service eligibility where the staff member is Merchant Personnel.
5. Triggering the invite / activation email.
6. Showing pending activation until the account is activated through the permitted lifecycle flow.

The staff member shall receive a welcome email that explains how to log in via Magic Link.

### Core Functionalities

* Add staff users within the same Branch scope.
* Invite staff by email.
* Resend invite.
* Revoke invite.
* Show pending activation.
* Create staff profiles.
* Edit staff profiles.
* Update employment data.
* Assign predefined roles within the HR user’s own Branch scope.
* Assign staff to accounts within the same Branch.
* Assign services that Merchant Personnel members can perform.
* Manage personnel service eligibility.
* Manage availability calendars and shifts.
* Manage working days.
* Manage working hours.
* Manage break/off-duty status.
* Manage unavailable dates.
* Manage emergency unavailable status.
* Maintain staff employment records.
* Maintain role, branch, and status history.
* Search and filter staff.
* Export staff roster only.

### Explicit Exclusions

The Merchant Human Resource account user must not:

* Manage staff in other Branches.
* Assign staff to other Branches.
* Manage Merchant Administrator account activation status.
* Independently activate staff accounts where Merchant Administrator activation is required.
* Request Merchant Administrator approval workflows as a separate HR authority model.
* Export client data.
* Export payment data.
* Self-escalate permissions.
* Assign themselves a higher-risk role.

### Staff Operational Screen

The HR module shall include an operational screen showing:

* Staff name.
* Role.
* Branch.
* Account status.
* Employment status.
* Service eligibility.
* Availability.
* Last login.

### Mandatory Staff Profile Fields

| Field | Requirement |
| ----- | ----------- |
| First name | Required. |
| Last name | Required. |
| Display name | Required. |
| Profile picture | Required where configured by merchant policy. |
| Email | Required and unique for active staff. |
| Phone | Required and unique for active staff. |
| Role | Required. |
| Employment type | Required. |
| Employment status | Required. |
| Primary branch | Required and limited to the HR user’s Branch scope. |
| Start date | Required. |
| Staff invited by | Required. |

### Duplicate Staff Prevention

The platform shall prevent duplicate active staff accounts within the platform by email and phone.

### Suspension and Deactivation Rule

When a merchant staff user is suspended or deactivated:

* Existing active sessions must be invalidated immediately.
* Unused Magic Links must be invalidated immediately.
* Login must be blocked.
* Historical operational records must be preserved.
* Reassignment checks must be triggered for live queues, appointments, and service sessions.

### Search and Export

Search must support:

* Staff name.
* Email.
* Phone.
* Branch.
* Role.
* Status.
* Service eligibility.
* Availability.

Export shall be limited to the staff roster only and must not include client or payment data.

### Production-Ready HR Feature Set

| Screen / Workflow | Must-Have Functions |
| ----------------- | ------------------- |
| HR Dashboard | Staff count, active/suspended/deactivated count, pending invitations, unavailable personnel, branch filter. |
| Staff Roster | List, search, filter, paginate, view profile, status badges, branch, role, availability, service eligibility. |
| Staff Invitation / Activation | Invite, resend invite, revoke invite, show pending activation, create staff profile, assign account type, assign role, block login until active. |
| Edit Staff | Update profile, employment data, phone/email, branch-scoped role, availability, service eligibility, with audit logs. |
| Role Assignment | Assign predefined roles, show permissions, require approval for high-risk roles where configured, prevent self-escalation. |
| Branch Scope | Enforce same-branch staff management only. |
| Service Eligibility | Select services the personnel can perform. |
| Availability Management | Available/unavailable, working hours, breaks, day off, emergency unavailable. |
| Suspension / Deactivation | Require reason, revoke access, block login, preserve historical records, trigger reassignment checks. |
| Role/Branch/Status History | Full history with actor, date, old value, new value, approval status where applicable, and reason. |
| Audit Log View | HR-visible audit events limited to staff/access changes. Full audit remains available to Merchant Audit. |
| Permission Preview | Summary of exactly what the staff member can see and do. |

### Authentication Rule

HR users log in via Magic Link after their email exists as an invited/active user under the relevant merchant tenant and permitted Branch scope.

---

## 3.5 Merchant Finance Account

### Purpose

The Merchant Finance Account manages offline payment validation, financial control, invoice payment status, receipts, financial reports, reconciliation, disputes, external refund records, platform fee visibility, and finance audit activity.

Merchant Finance users must not access other branches externally or even within the same Merchant Administrator except for the Merchant Branch they have been assigned into.

### Core Functionalities

* View invoices within assigned merchant and branch scope.
* Validate offline payments.
* Approve or reject payment records.
* Record and audit payment references.
* Generate receipts automatically after payment validation.
* View outstanding balances.
* View voided invoices.
* View refunds recorded externally.
* View platform fees owed where permitted.
* View commission liabilities where permitted.
* Export finance reports where permitted.
* Review payment disputes.
* Audit payment activity.
* Review cash-up submissions.
* Approve or reject branch cash-ups where permitted.
* Manage finance task inbox items.

### Granular Finance Permissions

Merchant Finance must not launch as one broad permission.

| Permission | Requirement |
| ---------- | ----------- |
| View invoices | Can view invoices within assigned merchant/branch scope. |
| Validate payments | Can approve/reject payment records. |
| Edit payment reference | Controlled and audited. |
| Generate receipts | Only after validation rules are satisfied. |
| Void finance record | Restricted and approval-based. |
| Review disputes | Can open, update, and resolve disputes. |
| Export finance reports in CSV and PDF | Permission-controlled and audited. |
| Lock periods | Merchant Administrator only, unless explicitly delegated by policy. |
| View commissions | Permission-controlled. |
| View platform fees | Permission-controlled. |

Risk if omitted: **55%–75% likelihood** of over-permissioned finance users and internal payment manipulation.

### Payment Validation Workflow

Required payment validation statuses:

* Pending validation.
* Validated.
* Rejected.
* Partially validated.
* Disputed.
* Correction requested.
* Voided.
* Refunded externally.

Required payment validation fields:

```text
invoice_id
payment_record_id
payment_method
payment_amount
payment_reference
recorded_by
validated_by
validation_status
validation_note
rejection_reason
validated_at
branch_id
merchant_id
```

Risk if omitted: **60%–80% likelihood** of messy payment states and unreliable finance reports.

### Payment Reference Rules by Method

| Payment Method | Must-Have Validation Rule |
| -------------- | ------------------------- |
| Cash | Amount, collector, branch/day cash-up reference. |
| M-Pesa | Transaction code/reference required. |
| Bank transfer | Bank reference or deposit slip reference required. |
| Card terminal | Terminal reference required. |
| Voucher | Voucher code and approval status required. |
| Split payment | Each payment leg must have method, amount, and validation status. |
| Other | Merchant-defined reference requirements. |

Risk if omitted: **55%–75% likelihood** of fake, duplicated, or unverifiable payment records.

### Duplicate Payment Reference Detection

| Feature | Requirement |
| ------- | ----------- |
| Duplicate reference check | Detect duplicate M-Pesa, bank, card, voucher, or custom references. |
| Same-merchant detection | Block or warn within same merchant. |
| Same-branch detection | Show branch conflict. |
| Override workflow | Override requires permission and reason. |
| Audit log | Every override must be logged. |

Risk if omitted: **45%–65% likelihood** of double-counted payments or fraud.

### Partial Payment and Split Payment Control

This applies to both Merchant-to-Platform payments and Merchant-client-to-Merchant payment records.

| Feature | Requirement |
| ------- | ----------- |
| Partial payment balance | System calculates remaining balance automatically. |
| Multiple payment records | One invoice can have several payment records. |
| Mixed methods | Cash + M-Pesa + bank/card/voucher supported. |
| Validation per payment leg | Each leg can be pending, validated, rejected, or disputed. |
| Receipt rule | Receipt shows only validated payment amounts. |
| Final paid status | Invoice becomes paid only when validated total equals invoice total. |

Risk if omitted: **50%–70% likelihood** of wrong invoice statuses and receipt disputes.

### Receipt Issuance Controls

| Feature | Requirement |
| ------- | ----------- |
| Receipt generation lock | Block receipt before payment is validated. |
| Receipt numbering | Unique receipt number enforced at database level. |
| Receipt reversal | No deletion; reversal or cancellation must preserve original record. |
| Reissue control | Reissued receipt must reference original receipt. |
| Download log | Every receipt download should be logged. |
| Receipt permissions | Finance-only, Admin-only, or configured role-based issuance. |

Risk if omitted: **55%–75% likelihood** of duplicate receipts, premature receipts, or financial disputes.

### Invoice Adjustment and Void Approval Workflow

| Action | Required Control |
| ------ | ---------------- |
| Void unpaid invoice | Permission + reason. |
| Void paid invoice | Approval required. |
| Adjust invoice after payment | Approval required. |
| Reverse preferred-personnel fee | Reason + audit log. |
| Refund recorded externally | Finance/Admin approval. |
| Reopen paid invoice | Highly restricted. |

Risk if omitted: **50%–70% likelihood** of invoice tampering and audit gaps.

### Daily Branch Cash-Up and Reconciliation

Required workflow:

```text
Review daily invoices
Review recorded payments
Validate pending payments
Compare expected totals vs recorded totals
Break down totals by payment method
Record cash counted
Record M-Pesa/bank/card references
Flag discrepancies
Submit branch cash-up
Finance approves or rejects cash-up
Lock day after approval
```

Required dashboard metrics:

* Expected revenue.
* Validated payments.
* Pending validation.
* Rejected payments.
* Cash total.
* M-Pesa total.
* Bank transfer total.
* Card terminal total.
* Voucher total.
* Split payment total.
* Discrepancy amount.

Risk if omitted: **65%–85% likelihood** of cash leakage, poor reconciliation, and unreliable merchant reports.

### Financial Period Locking

| Feature | Requirement |
| ------- | ----------- |
| Daily lock | Lock a branch day after cash-up approval. |
| Monthly lock | Lock finance period after review. |
| Lock permissions | Senior Finance/Admin only, with Merchant Administrator authority controlling final policy. |
| Post-lock edits | Require reopening workflow with reason and approval. |
| Immutable audit | Every lock, unlock, and edit must be logged. |
| Reporting protection | Locked periods must not change silently. |

Risk if omitted: **50%–70% likelihood** of changing historical reports and commission disputes.

### Finance Dispute Management

Required dispute statuses:

* Open.
* Under review.
* Evidence requested.
* Resolved.
* Rejected.
* Escalated.
* Closed.

Required fields:

```text
dispute_type
invoice_id
payment_record_id
raised_by
assigned_to
amount_in_dispute
reason
evidence_attachment
resolution_note
resolved_by
resolved_at
```

Risk if omitted: **40%–60% likelihood** that disputes are handled through WhatsApp, calls, or manual notes outside Servana.

### External Refund Recording

| Feature | Requirement |
| ------- | ----------- |
| Refund type | Full or partial. |
| Refund amount | Cannot exceed validated amount. |
| Refund method | Cash, M-Pesa, bank, card reversal, voucher, other. |
| Refund reference | Required where applicable. |
| Approval workflow | Required before refund record is finalized. |
| Invoice/payment impact | Invoice status updates appropriately. |
| Audit log | Every refund record and approval logged. |

Risk if omitted: **45%–65% likelihood** of refund abuse or inaccurate revenue.

### Commission Liability Review

| Feature | Requirement |
| ------- | ----------- |
| Commission payable list | Show earned/pending commissions. |
| Payment dependency | Commission becomes earned only after payment validation. |
| Reversal handling | Voided/refunded invoices reverse or adjust commissions. |
| Commission dispute flag | Finance can flag disputed commissions. |
| Commission export | Permission-controlled export. |
| Period-based commission summary | By branch, personnel, service, date range. |

Risk if omitted: **50%–70% likelihood** of staff commission disputes.

### Platform Fee Visibility and Reconciliation

| Feature | Requirement |
| ------- | ----------- |
| Platform fee ledger | Show charges, payments, adjustments, outstanding balance. |
| Fee calculation explanation | Show how Citrus platform fee was calculated. |
| Billing cycle | Show current cycle, due date, overdue status. |
| Preferred personnel fee treatment | Show whether it affected platform fee. |
| Contact-download fees | Disabled for Merchant Personnel at launch; any reserved/internal fee records must be shown only where legally and operationally valid. |
| Dispute platform fee | Finance/Admin can raise a dispute. |
| Download statement | Export merchant-facing platform fee statement. |

Risk if omitted: **55%–75% likelihood** of merchant disputes with Citrus over fee calculations.

### Finance Export Governance

| Feature | Requirement |
| ------- | ----------- |
| Export permission | Only authorized Finance/Admin users. |
| Export reason | Required for sensitive reports. |
| Export scope | Branch, date range, report type. |
| Signed URL | Export file must expire. |
| Download count | Track downloads. |
| Audit log | Record who exported what and when. |
| Sensitive data masking | Hide client contact/payment-sensitive fields unless explicitly permitted. |

Risk if omitted: **45%–65% likelihood** of financial data leakage.

### Finance Audit Dashboard

Must show:

* Payment recorded.
* Payment edited.
* Payment validated.
* Payment rejected.
* Payment disputed.
* Receipt generated.
* Receipt reissued.
* Invoice voided.
* Refund recorded externally.
* Financial period locked.
* Financial period reopened.
* Finance export generated.

Risk if omitted: **45%–60% likelihood** that finance fraud or mistakes are discovered late.

### Finance Notifications and Task Inbox

| Event | Priority |
| ----- | -------- |
| New payment pending validation | High |
| Duplicate payment reference detected | Critical |
| Paid invoice awaiting receipt | Medium |
| Payment dispute opened | High |
| Refund approval requested | High |
| Invoice void approval requested | High |
| Cash-up submitted for review | High |
| Branch cash-up discrepancy | Critical |
| Commission liability generated | Medium |
| Platform fee overdue | Critical |
| Financial period ready for lock | High |

Risk if omitted: **40%–55% likelihood** of missed validations, late receipts, and unresolved discrepancies.

### Final Production-Launch Merchant Finance Navigation

* Finance Overview.
* Pending Validations.
* Invoices.
* Payment Records.
* Receipts.
* Partial & Split Payments.
* Disputes.
* External Refunds.
* Cash-Up & Reconciliation.
* Financial Periods.
* Commission Liabilities.
* Platform Fees.
* Finance Reports.
* Exports.
* Audit Activity.
* Notifications.
* Settings.

### Authentication Rule

Finance users log in via Magic Link after their email exists as an invited/active user under the relevant merchant tenant and assigned Branch scope.

---

## 3.6 Merchant Front Office Account

### Purpose

The Merchant Front Office Account handles client-facing operations for a specific Branch.

### Core Functionalities

* Register clients.
* Retrieve existing clients.
* Prevent duplicate-client registration within the same Branch.
* Create walk-in sessions.
* Create appointment records.
* Convert appointment arrival into active queue/service flow without duplicating appointment, queue, or session records.
* Select services.
* Assign next available personnel.
* Allow the client to select preferred personnel at extra cost.
* Manage queue status.
* Create service sessions.
* Generate invoices.
* Record offline payment method.
* Submit payment details for Finance validation.
* View awaiting-Finance-validation status.
* View ready-for-receipt status.
* View daily branch activity.
* View current appointments and walk-ins.
* Notify clients of queue or service status.
* Search speed-sensitive operational records.

### Actionable Queues

Merchant Front Office must support actionable queues:

* Check in.
* Assign.
* Start service.
* Invoice.
* Record payment.
* Awaiting Finance validation.
* Ready for receipt.

### Walk-In Creation Atomicity

Creating a walk-in must atomically create or attach:

* Client.
* Selected service.
* Branch.
* Queue entry.
* Assignment mode.
* Optional preferred-personnel fee.

### Valid State Transitions

The Front Office workflow shall enforce only valid transitions:

```text
waiting → assigned → in service → completed → invoiced / paid
```

Controlled cancellation and no-show paths must exist.

The platform must avoid double-booking and duplicate service sessions.

### Receipt Rule

Merchant Front Office must not issue a receipt without a linked invoice and valid payment state.

### End-of-Day Branch Activity Summary

Merchant Front Office needs a daily branch close view showing:

* Walk-ins.
* Appointments.
* Completed services.
* Pending services.
* Invoices created.
* Unpaid invoices.
* Payments pending validation.
* Receipts issued.

### Audit Logging

Every Merchant Front Office action must create audit logs for:

* Client create/edit.
* Check-in.
* Appointment change.
* Queue change.
* Assignment.
* Preferred-personnel choice.
* Invoice creation.
* Payment recording.
* Receipt generation.

### Speed-Sensitive Search

Search must support:

* Phone number.
* Name.
* Appointment reference.
* Invoice number.
* Receipt number.
* Queue position.

### Offline Payment UI State

Since payments are offline but recorded online, the UI must clearly show:

* Saved.
* Unsaved.
* Pending validation.

Full offline mode is not launch-critical.

### Production Dashboard Sections

The Merchant Front Office dashboard shall include:

* Next client.
* Waiting.
* In service.
* Completed today.
* Pending commission.
* Preferred requests.

### Authentication Rule

Front Office users log in via Magic Link after their email exists as an invited/active user under the relevant merchant tenant and assigned Branch scope.

---

## 3.7 Merchant Personnel Account

### Purpose

Merchant Personnel are the service providers.

Examples:

* Barber.
* Hairdresser.
* Stylist.
* Massage therapist.
* Nail technician.
* Beautician.
* Facial therapist.
* Grooming specialist.
* Cosmetologist.

### Core Functionalities

* View own dashboard.
* View own assignments.
* View own queue.
* View own appointments.
* View own service history.
* View own commission.
* View allowed personally served clients without export capability.
* View preferred-personnel requests.
* View clients who specifically requested them.
* View estimated wait order.
* View service requested.
* View whether a request is active, cancelled, reassigned, or completed.
* Use mobile-first UI optimized for phones and tablets.

### Server-Side Access Restrictions

Merchant Personnel must only access:

* Own assignments.
* Own queue.
* Own appointments.
* Own service history.
* Own commission.
* Allowed personally served clients.
* Records for assigned branches only.

This must be enforced server-side, not only hidden in the UI.

### Multi-Branch Rule

Merchant Personnel should only see records for assigned branches. Multi-branch personnel need explicit branch assignment.

### Service Eligibility Rule

Merchant Personnel should only receive assignments for services they are eligible to perform. Eligibility must come from `personnel_service_eligibilities`, not manual Front Office judgment.

### Personnel Availability States

Merchant Personnel states:

* Available.
* Busy.
* On break.
* Offline.
* Unavailable.
* Suspended.

Merchant Human Resource and Merchant Administrator control permanent availability. Personnel may only toggle limited operational states where allowed.

### Contact Export Removal

Merchant Personnel must not have a contact export field, client-contact export function, or bulk contact download function.

Merchant Personnel may view only the client information required to complete allowed service operations for clients they personally serve or are assigned to, subject to tenant, branch, role, consent, and audit controls.

### Authentication Rule

Personnel users log in via Magic Link after their email exists as an invited/active user under the relevant merchant tenant and assigned Branch scope.

---

## 3.8 Merchant Audit Account

### Purpose

The Merchant Audit Account provides read-only operational and financial oversight.

### Landing Screen

Merchant Audit users need one landing screen showing:

* High-risk events.
* Recent activity.
* Flagged items.
* Payment issues.
* Role changes.
* Contact export attempts or any legally permitted contact-access/export events.
* Preferred-personnel overrides.

### Core Functionalities

* View immutable audit logs.
* View role changes.
* View branch changes.
* View invoice history.
* View payment validation logs.
* View receipt logs.
* View queue reassignment logs.
* View preferred-personnel fee logs.
* View contact-access/export-attempt logs.
* Export audit reports where permitted.
* Flag suspicious activity.

### Searchable and Filterable Audit Logs

Merchant Audit must support searchable and filterable logs by:

* Date.
* Actor.
* Role.
* Branch.
* Module.
* Action.
* Entity type.
* Severity.
* Event status.

### Access Rule

Merchant Audit must be read-only.

Merchant Audit must have no permission to create, edit, or delete:

* Clients.
* Services.
* Invoices.
* Payments.
* Receipts.
* Users.
* Queues.
* Commissions.

This must be enforced server-side, not only hidden in the UI.

### Before-and-After Values

Every sensitive change must include before-and-after values. The `old_values` and `new_values` fields are mandatory for sensitive state changes.

### Append-Only and Tamper Detection

Merchant Audit logs must be append-only. Merchant users must not update or delete audit records.

Each audit record should have a hash/digest or chained hash for tamper detection.

### Severity Levels

Required severity levels:

* Info.
* Low.
* Medium.
* High.
* Critical.

The following should be high or critical:

* Role changes.
* Payment validation changes.
* Receipt generation.
* Voids.
* Contact-access/export attempts or events.
* Branch access changes.

### Flagged Event Statuses

Flagged events require:

* Status: open, under_review, resolved, dismissed.
* Severity.
* Reason.
* Created by.
* Reviewed by.
* Resolution note.
* Timestamp.

### Sensitive Data Masking

Merchant Audit users should see enough to investigate, but sensitive client contact/payment details should be masked unless permission allows full visibility.

### Unauthorized Access Attempt Logging

The platform must log attempts by users to access unauthorized:

* Branch records.
* Merchant records.
* Invoice records.
* Payment records.
* Queue records.
* Client records.
* Contact export/contact-access records.

---

## 3.9 General End-User / Client Record

### Purpose

The platform shall support clients as service recipients.

For launch readiness, clients should initially exist as **client records** with optional later conversion into full login accounts.

### Core Functionalities

* Client profile.
* Contact details.
* Visit history.
* Services consumed.
* Assigned personnel history.
* Preferred personnel history.
* Invoice and receipt history.
* Appointment history.
* Queue participation.
* Consent records.
* Communication preferences.

### Duplicate-Client Rule

The platform shall prevent duplicate-client registration within the same Branch.

A client may exist in other Branches, including separate Branches under the same Merchant Administrator, but duplicate records in the same Branch should be blocked or merged through controlled workflows.

### Optional Future Login

A full General End-User portal can later allow clients to:

* View appointments.
* View receipts.
* View service history.
* Join a queue remotely.
* Choose preferred personnel.
* Update profile details.

# 4. Preferred Merchant Personnel Waiting Feature

## 4.1 Purpose

The client shall be able to choose between:

1. Waiting for the **next available merchant personnel**, or
2. Waiting for a **specific merchant personnel of their choice** at an extra cost set by the Super Administrator.

This is critical for barbershops, salons, spas, massage parlours, and beauty businesses where clients often prefer a specific barber, stylist, therapist, or beautician.

---

## 4.2 Fee Control

The extra fee shall be configured by the **Super Administrator**.

Supported fee models:

| Fee Model            | Description                                               |
| -------------------- | --------------------------------------------------------- |
| Fixed fee            | Example: KES 100 extra to wait for a preferred personnel  |
| Percentage fee       | Example: 5% of selected service price                     |
| Service-category fee | Different surcharge by service category                   |
| Branch-category fee  | Different surcharge by merchant or branch type            |
| Personnel-tier fee   | Optional future model for senior personnel or specialists |

Recommended launch model:

> Launch with fixed fee and percentage fee only. Keep the database flexible enough to support service-category and personnel-tier fee rules later.

---

## 4.3 Preferred Personnel Workflow

```text
Client arrives or books appointment
Front Office opens client session
Front Office selects service
System displays eligible personnel
Client chooses one of two options:
  1. Next available personnel
  2. Preferred specific personnel

If preferred personnel is selected:
  System displays extra fee
  System displays estimated wait time
  Client confirms acceptance
  Queue entry is attached to selected personnel
  Invoice includes preferred personnel waiting fee
  Payment is recorded offline
  Finance validates payment
  Receipt is generated
  Audit log records the selected personnel, fee, and user who created the session
```

---

## 4.4 Business Rules

* Preferred personnel selection must be optional.
* The fee must be visible before confirmation.
* The preferred personnel fee must appear as a separate invoice line item.
* The queue must lock the client to the chosen personnel.
* Front Office may override the chosen personnel only with permission and reason.
* Overrides must create audit logs.
* If the preferred personnel becomes unavailable, the system must support:

  * Waiting longer,
  * Reassignment,
  * Cancellation,
  * Preferred-fee reversal,
  * Invoice adjustment.
* The system must show whether the preferred personnel fee affects:

  * Merchant revenue,
  * Citrus platform fee,
  * Personnel commission.

---

## 4.5 Recommended Financial Treatment

| Item                    | Recommended Rule                                           |
| ----------------------- | ---------------------------------------------------------- |
| Preferred personnel fee | Treated as merchant revenue                                |
| Citrus service fee      | Applied unless Super Administrator exempts it              |
| Personnel commission    | Controlled through Merchant Human Resource commission settings |
| Receipt visibility      | Visible as separate receipt line item                      |
| Audit visibility        | Visible to Super Admin, Merchant Admin, Finance, and Audit |

---

# 5. Core Modules Required for Product Launch

## 5.1 Merchant Onboarding and First-Time Setup Module

### Purpose

Enable Merchant Administrator self-registration, automatic merchant tenant creation, first-time merchant setup, branch setup, and initial staff invitation without Super Administrator merchant creation or compliance/KYC approval.

### Required Features

* Merchant Administrator self-registration.
* Automatic merchant tenant creation.
* Automatic assignment of registering user as Merchant Owner / Merchant Administrator.
* Merchant profile setup.
* Business category setup.
* Merchant logo upload for invoices and receipts.
* Service fee tier selection:
  * Customer Centric.
  * Split Tier.
  * Business Centric.
* Branch creation by Merchant Administrator only.
* Branch profile setup.
* Initial Merchant Branch account user email invitation.
* Initial Merchant Human Resource account user email invitation.
* Auto-select Merchant Branch where only one branch exists.
* Magic Link welcome email for invited users.
* Merchant suspension workflow.
* Merchant deactivation workflow.
* Merchant status history.
* Platform fee payment status tracking.
* Audit logs.

### Explicit Exclusions

* No Super Administrator merchant registration workflow.
* No Super Administrator creation of the first Merchant Administrator.
* No Merchant Administrator compliance submission, KYC submission, or activation document submission.
* No live operational access block that waits for Super Administrator activation after Merchant Administrator self-registration.

---

## 5.2 Authentication and Access Control Module

### Purpose

Secure account access and prevent unauthorized merchant or branch data exposure.

### Required Features

* Magic Link login for all users.
* One-time-use tokens.
* Token expiry.
* Email verification.
* Active invited-email verification under the correct merchant tenant.
* Role-based access control.
* Permission-based access control.
* Tenant-based access control.
* Branch-based access control.
* Login rate limiting.
* Session timeout.
* Login audit logs.
* Optional MFA for high-privilege roles.

### Universal Login Rule

All users log in to their respective accounts via Magic Link.

A user must not log in merely because the email address exists. The login must check tenant, role, account status, suspension status, branch scope where applicable, and Magic Link validity.

---

## 5.3 Merchant and Branch Management Module

### Required Features

* Merchant profile.
* Merchant service fee tier.
* Merchant Branch creation by Merchant Administrator only.
* Branch profile.
* Branch operating hours.
* Branch operating calendar.
* Branch public holiday exceptions.
* Branch special closures.
* Branch same-day emergency closure.
* Branch status.
* Branch service configuration.
* Branch service pricing.
* Branch queue configuration.
* Branch appointment controls.
* Branch day opening / closing.
* Branch cash-up and reconciliation.
* Branch invoice and receipt numbering.
* Branch dashboard.
* Branch reports.
* Branch audit logs.
* Branch closure and archival protection.

### Final Production-Launch Merchant Branch Navigation

* Branch Overview.
* Branch Profile.
* Operating Hours.
* Calendar Exceptions.
* Services.
* Personnel.
* Users & Access.
* Queue.
* Appointments.
* Service Sessions.
* Invoices.
* Payments.
* Receipts.
* Day Opening / Closing.
* Cash-Up & Reconciliation.
* Reports.
* Audit Logs.
* Settings.

---

## 5.4 Staff, HR, and Role Management Module

### Required Features

* HR-managed staff invitation within same Branch scope.
* Staff email invitation.
* Resend invite.
* Revoke invite.
* Pending activation visibility.
* Staff profile creation.
* Staff profile editing.
* Staff suspension.
* Staff deactivation.
* Magic Link invalidation after suspension/deactivation.
* Active session invalidation after suspension/deactivation.
* Role assignment.
* Branch-scoped assignment enforcement.
* Personnel service eligibility.
* Availability calendar.
* Employment status.
* Staff roster search.
* Staff roster export only.
* Permission preview.
* Role/branch/status history.
* Audit logs.

### Account-Creation Boundary

Merchant Administrator adds only Merchant Branch account user and Merchant Human Resource account user email addresses.

Merchant Human Resource adds Merchant Personnel, Merchant Front Office, Merchant Finance, and Merchant Audit staff users within the HR user’s own Branch scope.

Merchant Human Resource does not manage staff in other Branches and does not export client/payment data.

---

## 5.5 Service Catalogue Module

### Required Features

* Service creation by Merchant Branch account user.
* Service editing by Merchant Branch account user.
* Service archiving by Merchant Branch account user.
* Service category.
* Price.
* Estimated duration.
* Eligible personnel through HR-controlled service eligibility.
* Branch availability.
* Active/inactive status.
* Discount support.
* Preferred personnel fee eligibility.
* Service-level revenue performance reporting.

### Authority Rule

The Merchant Administrator does not configure services or pricing. The Merchant Branch account configures services and pricing within the specific Branch account.

---

## 5.6 Client Records Module

### Required Features

* Client profile.
* Phone number.
* Email, optional.
* Gender, optional.
* Visit history.
* Service history.
* Assigned personnel history.
* Payment history.
* Receipt history.
* Client preferences.
* Preferred personnel history.
* Notes.
* Consent records.
* Same-branch duplicate-client prevention.

---

## 5.7 Appointment and Walk-In Module

### Required Features

* Walk-in creation.
* Appointment creation.
* Appointment rescheduling.
* Appointment cancellation.
* No-show marking.
* Client check-in.
* Appointment-to-active-queue conversion without duplicate records.
* Service selection.
* Personnel assignment based on HR-controlled eligibility.
* Preferred personnel selection.
* Appointment status history.
* Notification triggers.
* Conflict prevention.
* Branch closure protection.

---

## 5.8 Queue Management Module

### Required Features

* Branch queue board.
* Personnel-specific queue.
* Next available personnel assignment.
* Preferred personnel assignment.
* Estimated wait time.
* Queue open/close.
* Queue capacity.
* Assignment mode.
* Queue cancellation reason.
* No-show handling.
* Queue reorder permission.
* Preferred personnel override reason.
* Queue audit logs.
* Reassignment when personnel becomes unavailable.

Required queue statuses:

* Waiting.
* Assigned.
* In service.
* Completed.
* Cancelled.
* No-show.

---

## 5.9 Service Session Module

### Required Features

* Client selected.
* Service selected.
* Branch selected.
* Personnel assigned.
* Service eligibility checked.
* Session status.
* Start timestamp.
* End timestamp.
* Service notes.
* Cancellation reason.
* Invoice trigger.
* Audit trail.
* Double-booking prevention.
* Duplicate service session prevention.

Recommended statuses:

* Draft.
* Waiting.
* Assigned.
* In progress.
* Completed.
* Cancelled.
* Invoiced.
* Paid.

---

## 5.10 Invoice Module

### Required Features

* Unique invoice number.
* Merchant.
* Merchant logo on all merchant-to-client invoices.
* Branch.
* Branch prefix where configured.
* Client.
* Service.
* Assigned personnel.
* Invoice line items.
* Service price.
* Discount.
* Preferred personnel fee.
* Service fee tier effect on merchant-client invoice pricing.
* Final invoice amount.
* Payment status.
* Created by.
* Timestamp.
* Void workflow.
* Adjustment approval workflow.
* Audit log.

### Numbering Rule

Use merchant-wide uniqueness with optional branch prefix for readability. No duplicate invoice numbers are allowed at database level. Voided invoices keep their number.

---

## 5.11 Offline Payment Recording and Validation Module

### Required Features

* Payment method.
* Payment amount.
* Payment reference.
* Payment date/time.
* Payment note.
* Recorded by.
* Validated by.
* Validation status.
* Split payment support.
* Partial payment support.
* Multiple payment records per invoice.
* Payment-leg validation.
* Duplicate payment-reference detection.
* Method-specific reference rules.
* External refund record.
* Payment dispute flag.

Recommended payment statuses:

* Unpaid.
* Partially paid.
* Paid.
* Pending validation.
* Validated.
* Partially validated.
* Rejected.
* Correction requested.
* Voided.
* Refunded externally.
* Disputed.

---

## 5.12 Receipt Module

### Required Features

* Receipt number.
* Merchant logo on all receipts.
* Linked invoice.
* Linked payment record.
* Payment method.
* Validated paid amount only.
* Issued by.
* Issued timestamp.
* Downloadable receipt.
* Receipt reversal without deletion.
* Receipt reissue reference to original receipt.
* Receipt download log.
* Receipt audit log.

### Critical Rule

A receipt must not be generated before payment is validated.

For Merchant Finance users, receipts are generated automatically after payment validation.

---

## 5.13 Citrus Billing Engine

### Purpose

The Citrus Billing Engine calculates and tracks what merchants owe Citrus Labs Limited.

### Required Features

* Account-opening fee tracking where applicable.
* Platform service fee calculation.
* Merchant service fee tier storage.
* Customer Centric invoice pricing behavior.
* Split Tier invoice pricing behavior.
* Business Centric invoice pricing behavior.
* Preferred personnel fee treatment rules.
* Settlement cycle tracking.
* Platform fee ledger.
* Merchant balance.
* Branch-level platform fee debt tracking.
* Overdue balance tracking.
* Suspension triggers.
* Fee exemption rules.
* Billing audit logs.
* Merchant-facing platform fee statement.
* Platform fee dispute workflow.

### Service Fee Tier Rule

Because Servana does not process merchant-client-to-merchant payments, the selected service fee tier only changes the merchant-client invoice amount. Citrus platform fee invoices are generated using the normal platform fee amount according to the Super Administrator invoice generation period.

---

## 5.14 Commission Tracking Module

### Required Features

* Commission rule per personnel.
* Commission rule per role.
* Commission rule per service.
* Optional branch-level commission rules.
* Cumulative role commission setting.
* All-personnel commission setting.
* Individualized commission setting.
* Fixed amount commission.
* Percentage commission.
* Commission calculated only after invoice payment is confirmed and validated.
* Commission pending status.
* Commission earned status.
* Commission paid status, optional.
* Reversal on voided invoice.
* Reversal or adjustment on external refund.
* Preferred personnel surcharge commission setting.
* Commission liability review.
* Commission dispute flag.
* Period-based commission summary.

### Authority Rule

Merchant Personnel commissions are set by the Merchant Human Resource account user, not by the Merchant Administrator and not by Merchant Finance.

---

## 5.15 Client Data Protection and Contact Access Control Module

### Required Features

* Personnel can view only allowed personally served or assigned clients.
* No Merchant Personnel contact export field.
* No Merchant Personnel client-contact download feature.
* No Merchant Personnel bulk contact export.
* Sensitive client contact and payment data masking where permission does not allow full visibility.
* Unauthorized contact-access attempt logging.
* Consent record tracking.
* Signed URLs for any non-personnel export that is explicitly permitted by policy.
* Audit log for any contact-access or legally permitted export event.

### Removed Launch Capability

The earlier Personnel Client Contact Download capability is removed from the Merchant Personnel account for launch. Contact export must not be exposed to Merchant Personnel.

---

## 5.16 Reports and Dashboards Module

### Super Administrator Dashboard

* Total merchants.
* Active merchants.
* Suspended merchants.
* Total branches.
* Invoice volume.
* Gross invoice value.
* Platform fees accrued.
* Platform fees paid/recorded.
* Preferred personnel fee totals.
* Overdue merchants.
* Audit alerts.

### Merchant Administrator Dashboard

* Today’s sales.
* Weekly sales.
* Monthly sales.
* Branch performance.
* Branch revenue for today, this week, last month, and last 3 months.
* Service revenue performance per Branch.
* Personnel performance per Branch and per individual staff.
* Services completed.
* Clients served.
* Repeat clients.
* Invoices.
* Payment methods.
* Platform fees owed.
* Commission liabilities.
* Preferred personnel demand.
* Daily branch day-close PDF reports.
* Daily branch cash-up PDF reports.

### Merchant Branch Dashboard

* Today’s appointments.
* Today’s walk-ins.
* Active queue.
* Clients waiting.
* Clients in service.
* Completed sessions.
* Unpaid invoices.
* Pending payment validations.
* Receipts issued.
* Today’s revenue.
* Payment method breakdown.
* Personnel currently active.
* Queue delays.
* No-shows.
* Cancelled sessions.
* Staff performance for the specific Branch only.

### Merchant Finance Dashboard

* Pending payment validations.
* Paid invoices.
* Unpaid invoices.
* Partial payments.
* Split payments.
* Receipts issued.
* Voided invoices.
* Outstanding balances.
* Commission obligations.
* Platform fee obligations.
* Disputes.
* External refunds.
* Cash-up reviews.
* Financial periods.
* Audit activity.
* Finance notifications.

### Merchant Front Office Dashboard

* Next client.
* Waiting.
* In service.
* Completed today.
* Pending commission.
* Preferred requests.
* Today’s appointments.
* Walk-ins.
* Active queue.
* Clients waiting.
* Clients in service.
* Paid/unpaid invoices.
* Payments pending validation.
* Receipts issued.

### Merchant Personnel Dashboard

* Assigned clients.
* Own queue.
* Own appointments.
* Clients served.
* Services completed.
* Commission earned.
* Commission pending.
* Preferred personnel requests.
* Clients who specifically requested them.
* Estimated wait order.
* Service requested.
* Request state: active, cancelled, reassigned, or completed.

### Merchant Audit Dashboard

* High-risk events.
* Recent activity.
* Flagged items.
* Payment issues.
* Role changes.
* Contact-access/export-attempt events.
* Preferred-personnel overrides.

---

## 5.17 Notifications Module

Required notification types:

* Magic Link login email.
* Staff welcome email.
* Staff activation email.
* Staff invitation email.
* Staff invitation resend.
* Staff invitation revocation.
* Appointment confirmation.
* Appointment cancellation.
* Queue update.
* Preferred personnel wait confirmation.
* Payment validation notice.
* Receipt availability.
* Merchant suspension warning.
* Platform fee overdue warning.
* Duplicate payment reference alert.
* Cash-up submitted for review.
* Branch cash-up discrepancy alert.
* Refund approval requested.
* Invoice void approval requested.
* Financial period ready for lock.

Launch channels:

* Email: required.
* SMS/WhatsApp: recommended but can be phased.

---

## 5.18 Audit Logging Module

Audit logs must capture:

* Merchant self-registration.
* Merchant tenant creation.
* Merchant profile changes.
* Merchant suspension/deactivation.
* Branch creation.
* Branch profile edited.
* Branch status changed.
* Operating hours changed.
* Special closure added.
* Service enabled/disabled for branch.
* User invitation.
* User creation.
* User role changes.
* User granted/removed branch access.
* Staff suspension/deactivation.
* Magic Link login events.
* Unauthorized access attempts.
* Service creation/editing.
* Client record changes.
* Duplicate-client prevention events.
* Queue opened/closed.
* Queue reordered.
* Queue changes.
* Preferred personnel selection.
* Preferred personnel override.
* Appointment created/rescheduled/cancelled/no-showed.
* Service session started/completed/cancelled.
* Invoice creation.
* Invoice adjustment.
* Invoice void.
* Payment recording.
* Payment editing.
* Payment validation.
* Payment rejection.
* Payment dispute.
* Duplicate payment-reference override.
* Receipt generation.
* Receipt reissue.
* Refund recorded externally.
* Commission rule changes.
* Financial period locked.
* Financial period reopened.
* Cash-up submitted/reviewed.
* Finance export generated.
* Contact-access/export attempts or legally permitted export events.
* Platform fee setting changes.

Audit records should include:

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

Sensitive changes must include before-and-after values. Audit records must be append-only and tamper-evident.

# 6. Recommended Technical Architecture

Required stack:

| Layer             | Required Technology                      |
| ----------------- | ---------------------------------------- |
| Backend           | Laravel                                  |
| Backend Language  | PHP 8.2+                                 |
| Frontend          | Vue.js or React.js                       |
| Frontend Language | TypeScript preferred                     |
| Styling           | Tailwind CSS or Bootstrap 5              |
| Database          | PostgreSQL preferred; MySQL acceptable   |
| Authentication    | Laravel Sanctum for SPA/API auth         |
| API Style         | REST by default                          |
| Build Tooling     | Vite                                     |
| Queues            | Redis-backed Laravel Queues              |
| Cache             | Redis                                    |
| Storage           | S3-compatible object storage             |
| Search            | Meilisearch, Typesense, or Elasticsearch |
| Deployment        | Dockerized deployment with CI/CD         |

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
Domain Services
  - Merchant Onboarding
  - Branch Management
  - HR and Staff Access
  - Service Catalogue
  - Queue Management
  - Appointments
  - Service Sessions
  - Invoicing
  - Offline Payment Recording
  - Receipts
  - Citrus Billing Engine
  - Commissions
  - Client Data Protection and Contact Access Controls
  - Reports
  - Audit Logs
        ↓
PostgreSQL / MySQL
        ↓
Redis Queues / Cache
        ↓
Email, Storage, Monitoring, Error Tracking
```

---

# 7. Multi-Tenant Data Model

Minimum core entities:

| Entity | Purpose |
| ------ | ------- |
| `users` | Global identity records. |
| `merchants` | Merchant tenants created through Merchant Administrator self-registration. |
| `merchant_profiles` | Merchant profile, logo, business category, service fee tier, and owner-managed profile data. |
| `merchant_branches` | Branches under merchants. |
| `branch_operating_hours` | Weekly operating hours. |
| `branch_calendar_exceptions` | Public holiday exceptions, special closures, break periods, emergency closures. |
| `branch_day_records` | Branch opening, paused, closing, reopening, and day-close records. |
| `branch_cash_ups` | Daily branch cash-up and reconciliation submissions. |
| `merchant_users` | User-to-merchant-role mapping. |
| `branch_user_assignments` | Branch-scoped user access records. |
| `staff_profiles` | Staff identity, employment data, account status, role, branch, phone, email, start date, inviter. |
| `staff_invitations` | Invite, resend, revoke, pending activation, and welcome email tracking. |
| `roles` | Role records. |
| `permissions` | Permission records. |
| `role_permission_assignments` | Permission mapping per role. |
| `magic_login_tokens` | Magic Link tokens. |
| `services` | Branch-controlled service catalogue. |
| `service_categories` | Service grouping. |
| `personnel_service_eligibilities` | Which personnel can perform which services. |
| `personnel_availability_schedules` | Working days, shifts, breaks, unavailable times, emergency unavailability. |
| `clients` | Branch-scoped client records. |
| `appointments` | Scheduled service bookings. |
| `queue_entries` | Walk-in and queue records. |
| `service_sessions` | Actual service delivery. |
| `preferred_personnel_fee_rules` | Super Administrator fee configuration. |
| `invoices` | Invoice headers. |
| `invoice_items` | Invoice line items. |
| `invoice_number_sequences` | Merchant-wide unique numbering with optional branch prefix. |
| `payment_records` | Offline payment records. |
| `payment_validation_events` | Validation status history, validator, notes, rejection reason. |
| `payment_reference_checks` | Duplicate reference check records and overrides. |
| `receipts` | Receipt records. |
| `receipt_number_sequences` | Merchant-wide unique receipt numbering with optional branch prefix. |
| `receipt_reissues` | Receipt reissue tracking referencing original receipts. |
| `external_refunds` | External refund records and approvals. |
| `finance_disputes` | Payment and finance disputes. |
| `platform_fee_ledger` | Citrus fee records. |
| `platform_fee_disputes` | Merchant disputes against platform fee calculations. |
| `commission_rules` | HR-controlled commission configuration. |
| `commission_ledger` | Personnel commission records. |
| `finance_exports` | Finance export requests, signed URL expiry, download count, and audit metadata. |
| `notification_logs` | Notification tracking. |
| `audit_logs` | Immutable sensitive activity records with tamper-evident hash fields. |
| `flagged_audit_events` | Audit event review status and resolution workflow. |

Required tenant-scoped columns:

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

Required constraints:

* Unique active staff email across the platform.
* Unique active staff phone across the platform.
* Unique client identity within the same Branch according to duplicate-client prevention rules.
* Unique invoice number at database level.
* Unique receipt number at database level.
* Branch closure blocked where active operational or financial records exist.
* Payment reference duplicate detection by merchant and branch.
* Server-side tenant and branch authorization on every tenant-owned resource.
* Merchant Personnel contact export disabled at schema/API/UI level for launch.
* Audit records append-only with record hash or chained hash.

# 8. API Route Structure

All API routes should use `/api/v1`.

Recommended route groups:

```text
/api/v1/auth
/api/v1/auth/magic-link
/api/v1/auth/magic-link/verify
/api/v1/me
/api/v1/platform
/api/v1/platform/settings
/api/v1/platform/merchants
/api/v1/platform/billing
/api/v1/platform/audit-logs
/api/v1/merchant-registration
/api/v1/merchant-registration/self-register
/api/v1/merchant-registration/first-time-setup
/api/v1/merchants
/api/v1/merchants/profile
/api/v1/merchants/service-fee-tier
/api/v1/branches
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
/api/v1/invoices
/api/v1/invoices/voids
/api/v1/invoices/adjustments
/api/v1/payments
/api/v1/payments/validation
/api/v1/payments/duplicates
/api/v1/payments/partial-split
/api/v1/receipts
/api/v1/receipts/reissue
/api/v1/refunds
/api/v1/disputes
/api/v1/billing
/api/v1/billing/platform-fees
/api/v1/billing/platform-fee-disputes
/api/v1/commissions
/api/v1/reports
/api/v1/finance/exports
/api/v1/finance/audit
/api/v1/audit-logs
/api/v1/audit-logs/flagged-events
/api/v1/notifications
```

API rules:

* Authenticate protected routes.
* Authorize every tenant-owned resource server-side.
* Authorize every branch-owned resource server-side.
* Use UUIDs or ULIDs externally.
* Validate every request.
* Rate-limit sensitive endpoints.
* Paginate large responses.
* Return consistent JSON.
* Never expose internal IDs unnecessarily.
* Never rely on frontend authorization.
* Enforce same-Branch access for Merchant Branch, HR, Finance, Front Office, Audit, and Personnel users according to their permitted scope.
* Block Merchant Personnel contact export endpoints entirely for launch.
* Log unauthorized access attempts.

# 9. Frontend Structure

Recommended structure:

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
    audit/

  services/
    apiClient.ts
    authService.ts
    tenantContext.ts
    permissionService.ts

  stores/
    authStore.ts
    merchantStore.ts
    branchStore.ts
    permissionStore.ts

  types/
  utils/
```

Required UI states:

* Loading
* Empty
* Success
* Error
* Unauthorized
* Suspended merchant
* Inactive user
* Pending payment validation
* Pending staff activation
* Suspended branch
* Branch closed
* Pending cash-up review
* Financial period locked
* Duplicate payment reference
* Duplicate client warning
* No branch access
* No permission

---

# 10. Responsive Layout Strategy

Required breakpoints:

| View Mode | Width            |
| --------- | ---------------- |
| Desktop   | `>= 1025px`      |
| Tablet    | `768px – 1024px` |
| Mobile    | `<= 767px`       |

Rules:

* Use CSS media queries.
* Do not use JavaScript device detection.
* No fixed layouts that break on mobile.
* No horizontal scrolling on normal content.
* Tables must collapse into mobile cards.
* Queue screens must be usable on tablets.
* Front Office workflows must be mobile-friendly.
* Touch targets should be at least 44px.

---

# 11. Security Requirements

Required controls:

* Magic Link token expiry.
* One-time-use Magic Links.
* Rate limiting.
* CSRF protection.
* Secure cookies.
* HTTPS in production.
* Tenant-scoped queries.
* Branch-scoped authorization.
* Role-based access control.
* Permission-based access control.
* Signed URLs for exports.
* Server-side branch authorization for every Branch-scoped action.
* Merchant Personnel contact export endpoints disabled.
* Tamper-evident append-only audit logs.
* Private file storage.
* No secrets in frontend.
* No credentials in logs.
* Input validation.
* File upload validation.
* Audit logs.
* Session timeout.
* Optional MFA for Super Admin, Merchant Administrator, Finance, and Audit roles.

---

# 12. Accessibility and UI Requirements

The interface must be:

* Clean.
* Professional.
* Responsive.
* Accessible.
* Suitable for daily merchant operations.

Minimum accessibility requirements:

* Keyboard navigation.
* Visible focus states.
* Proper form labels.
* Associated error messages.
* WCAG AA-aligned contrast where possible.
* Touch-friendly controls.
* Browser zoom support.
* Reduced-motion respect.
* Clear button names.
* Clear empty states.
* Clear validation messages.

---

# 13. Testing Strategy

Required automated tests:

| Test Area | Required Coverage |
| --------- | ----------------- |
| Authentication | Magic Link request, expiry, reuse prevention, invalid token rejection, rate limiting. |
| Merchant self-registration | Merchant Administrator self-registers, tenant is created, user becomes Merchant Owner / Merchant Administrator. |
| First-time setup | Service fee tier, merchant profile, branch profile, Branch user invite, HR invite, dashboard redirect. |
| No Super Admin merchant creation | Super Admin cannot create first Merchant Administrator or require KYC/compliance submission. |
| Authorization | Role and permission enforcement. |
| Tenant isolation | Merchant cannot access another merchant’s data. |
| Branch isolation | Branch-scoped users cannot access unassigned branch records. |
| Staff invitation | HR invite/resend/revoke/pending activation. |
| Staff duplicate prevention | Duplicate active staff email and phone blocked. |
| Staff suspension/deactivation | Active sessions and unused Magic Links invalidated immediately. |
| HR branch scope | HR cannot assign or manage staff outside own Branch. |
| Branch profile | Required fields enforced. |
| Branch operating calendar | Weekly hours, public holidays, closures, emergency closure, closure reason, audit trail. |
| Branch day open/close | Required statuses and day-close record generation. |
| Branch cash-up | Expected vs recorded totals, discrepancy notes, Finance review, period lock. |
| Branch closure protection | Closure blocked when live operational/financial records exist. |
| Service catalogue | Branch user configures services/pricing; Merchant Admin cannot configure services/pricing. |
| Client duplicate prevention | Duplicate client blocked within same Branch. |
| Queue | Next available and preferred personnel assignment; valid status transitions; reassignment on unavailability. |
| Appointments | Check-in workflow, conflict prevention, branch closure protection, no duplicate queue/session. |
| Preferred personnel fee | Correct surcharge calculation and invoice line item. |
| Invoices | Totals, discounts, voids, invoice numbering, branch prefix, merchant logo. |
| Payment validation | Required statuses, fields, method-specific references, duplicate reference detection. |
| Partial/split payments | Payment legs, validated totals, final paid status, receipt amount rules. |
| Receipts | Receipt blocked until payment validation; auto-generation after validation; numbering; reissue; reversal. |
| External refunds | Approval, limits, method/reference, invoice/payment impact. |
| Finance disputes | Dispute statuses, fields, evidence, resolution. |
| Cash-up reconciliation | Finance approval/rejection and lock behavior. |
| Financial period locks | Lock/unlock/edit audit trail and reporting protection. |
| Commissions | HR-configured commissions; earned only after payment validation; reversal after void/refund. |
| Platform fees | Ledger, statement, fee calculation explanation, dispute workflow, service fee tier invoice pricing. |
| Finance exports | Permission, reason, signed URL expiry, download count, masking, audit log. |
| Merchant Personnel access | Own assignments/queue/appointments/service history/commission only, server-side. |
| Merchant Personnel contact export removal | No export field, no export endpoint, no bulk contact download. |
| Front Office | Atomic walk-in creation, valid transitions, payment UI states, speed search. |
| Audit logs | Sensitive events are append-only, include before-and-after values, severity, event status, hash/chained hash. |
| Unauthorized access logging | Attempts against branch, merchant, invoice, payment, queue, client, and contact-access records logged. |
| API validation | Invalid requests rejected. |
| Frontend workflows | Critical UI flows tested.

# 14. Deployment and Production Readiness

Production requirements:

* Dockerized deployment.
* CI/CD pipeline.
* Automated test execution before deployment.
* Environment-specific configuration.
* HTTPS.
* Queue workers.
* Laravel Scheduler.
* Redis.
* Database backups.
* Rollback procedure.
* Centralized logging.
* Error monitoring.
* Uptime monitoring.
* Health checks.
* Dependency vulnerability scanning.
* Secure object storage.
* Production mail provider.
* Staging environment.

---

# 15. Product-Launch-Ready Feature Checklist

| Feature | Priority |
| ------- | -------- |
| Magic Link authentication for all users | Critical |
| Merchant Administrator self-registration | Critical |
| Automatic merchant tenant creation | Critical |
| Merchant Owner / Merchant Administrator single account model | Critical |
| First-time Merchant Administrator setup | Critical |
| Service fee tier selection | Critical |
| Merchant profile management | Critical |
| Merchant logo on invoices and receipts | Critical |
| No Super Admin merchant creation / first-admin creation workflow | Critical |
| No compliance/KYC submission for Merchant Administrator self-registration | Critical |
| Tenant isolation | Critical |
| Branch isolation | Critical |
| Role and permission management | Critical |
| Branch creation by Merchant Administrator only | Critical |
| Branch profile required fields | Critical |
| Branch operating calendar | Critical |
| Branch queue configuration | Critical |
| Branch appointment controls | Critical |
| Branch day opening / closing | Critical |
| Branch cash-up and reconciliation | Critical |
| Branch invoice and receipt numbering | Critical |
| Branch closure and archival protection | Critical |
| Branch dashboard | High |
| Staff invitation and activation visibility | Critical |
| HR same-Branch staff management | Critical |
| Staff duplicate prevention by email and phone | Critical |
| Staff availability calendar | Critical |
| Personnel service eligibility | Critical |
| Staff suspension/deactivation session and Magic Link invalidation | Critical |
| Service catalogue controlled by Merchant Branch account | Critical |
| Client records | Critical |
| Same-Branch duplicate-client prevention | Critical |
| Walk-in handling | Critical |
| Atomic walk-in creation | Critical |
| Appointment handling | Critical |
| Appointment check-in workflow | Critical |
| Queue management | Critical |
| Preferred personnel waiting fee | Critical |
| Service sessions | Critical |
| Double-booking and duplicate-session prevention | Critical |
| Invoice generation | Critical |
| Invoice adjustment and void approval workflow | Critical |
| Offline payment recording | Critical |
| Payment validation workflow | Critical |
| Method-specific payment reference rules | Critical |
| Duplicate payment-reference detection | Critical |
| Partial and split payment control | Critical |
| Receipt generation only after payment validation | Critical |
| Automatic receipts after Finance validation | Critical |
| Receipt reversal/reissue controls | Critical |
| Citrus Billing Engine | Critical |
| Platform fee ledger and reconciliation | Critical |
| Commission tracking | Critical |
| HR-controlled commission rules | Critical |
| Finance disputes | High |
| External refund recording | High |
| Finance export governance | High |
| Finance notifications and task inbox | High |
| Merchant Personnel server-side access restriction | Critical |
| Merchant Personnel mobile-first UI | High |
| Remove Merchant Personnel contact export | Critical |
| Dashboards | High |
| Reports | High |
| Audit logs | Critical |
| Append-only tamper-evident audit logs | Critical |
| Audit event severity and flagged statuses | Critical |
| Unauthorized access attempt logging | Critical |
| Notifications | High |
| Responsive UI | Critical |
| Accessibility | Critical |
| Automated tests | Critical |
| Production monitoring | Critical |

# 16. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
| ---- | ---------: | -----: | ---------- |
| Merchants under-record invoices | 60%–75% | High | Make invoices necessary for commissions, receipts, queue history, branch cash-up, and owner reporting. |
| Weak daily branch accountability | 60%–75% if day open/close is omitted | High | Require branch day opening, closing, review, statuses, and PDF report to Merchant Administrator. |
| Payment disputes and cash leakage | 65%–85% if cash-up/reconciliation is omitted | High | Require daily cash-up, expected-vs-recorded totals, Finance review, discrepancy notes, and period locks. |
| Finance and audit confusion from numbering | 45%–60% if branch numbering is undefined | Medium | Enforce unique invoice and receipt numbers at database level with optional branch prefix. |
| Branch users managing operations through scattered screens | 40%–55% if branch dashboard is omitted | Medium | Provide one operational dashboard with appointments, walk-ins, queue, revenue, payments, no-shows, and personnel activity. |
| Unresolved disputes and weak accountability | 50%–70% if branch audit detail is omitted | High | Log exact branch events including schedule, queue, appointment, session, invoice, payment, receipt, day-close, and cash-up changes. |
| Broken branch records during closure | 45%–65% if closure protection is omitted | High | Block branch closure/archive while active queue entries, sessions, unpaid invoices, pending validations, unclosed days, or discrepancies exist. |
| Cross-tenant data leakage | 8%–15% if poorly built; <2% with strong controls | Critical | Tenant scopes, policies, UUIDs, access tests, code review. |
| Cross-branch data leakage | 15%–25% if branch authorization is weak; <3% with strong controls | Critical | Branch-scoped policies, server-side authorization, explicit branch assignment, and tests. |
| Over-permissioned Finance users | 55%–75% if Finance is launched as one broad permission | High | Use granular Finance permissions and audit high-risk finance actions. |
| Messy payment states and unreliable finance reports | 60%–80% if validation workflow is incomplete | High | Use defined validation statuses, required fields, and Finance workflow. |
| Fake, duplicated, or unverifiable payment records | 55%–75% if method-specific references are omitted | High | Require payment-reference rules per payment method and duplicate-reference detection. |
| Double-counted payments or fraud | 45%–65% if duplicate-reference detection is omitted | High | Detect duplicate M-Pesa, bank, card, voucher, or custom references; log overrides. |
| Wrong invoice statuses and receipt disputes | 50%–70% if partial/split controls are weak | High | Validate each payment leg and mark invoice paid only when validated total equals invoice total. |
| Duplicate receipts or premature receipts | 55%–75% if receipt controls are weak | High | Block receipts before validation, enforce unique receipt numbers, preserve reversals, and log downloads. |
| Invoice tampering and audit gaps | 50%–70% if void/adjustment approval is omitted | High | Require approval, reason, and immutable audit logs for high-risk invoice changes. |
| Changing historical reports and commission disputes | 50%–70% if financial periods are not locked | High | Lock days/months after review; require reason and approval to reopen. |
| Disputes handled outside Servana | 40%–60% if finance dispute object is omitted | Medium | Use formal dispute statuses, fields, evidence, assignment, and resolution notes. |
| Refund abuse or inaccurate revenue | 45%–65% if external refund controls are omitted | High | Require refund type, amount cap, method, reference, approval, and audit log. |
| Staff commission disputes | 50%–70% if commission review is weak | High | Show earned/pending commissions, payment dependency, reversals, dispute flags, and period summaries. |
| Merchant disputes with Citrus over fees | 55%–75% if fee visibility is weak | High | Provide platform fee ledger, calculation explanation, billing cycle, due dates, and fee dispute workflow. |
| Financial data leakage | 45%–65% if export governance is weak | High | Require export permission, reason, scope, signed URL expiry, download tracking, masking, and audit logs. |
| Finance fraud discovered late | 45%–60% if Finance audit dashboard is omitted | High | Provide dedicated Finance audit activity dashboard. |
| Missed validations and late receipts | 40%–55% if Finance task inbox is omitted | Medium | Notify Finance about pending validations, duplicate references, disputes, refunds, voids, cash-up discrepancies, and period locks. |
| Merchant user login abuse | 25%–40% | Medium | Magic Link expiry, active-email verification, rate limiting, session logs. |
| Duplicate staff accounts | 35%–50% without controls | Medium | Enforce active staff uniqueness by email and phone. |
| Duplicate client records within branch | 35%–50% without controls | Medium | Prevent or merge duplicate branch-level client records through controlled workflows. |
| Personnel sees data beyond own scope | 35%–55% without server-side controls | Critical | Enforce own-assignment, own-queue, assigned-branch, and service-eligibility restrictions server-side. |
| Client contact exposure by Merchant Personnel | 35%–50% if contact export exists | High | Remove Merchant Personnel contact export field and endpoints; allow only limited operational contact visibility. |
| Front Office manipulates payment status | 30%–45% | High | Finance validation, immutable logs, restricted receipt permissions. |
| Preferred personnel disputes | 25%–40% | Medium | Show wait time, show fee, require confirmation, audit overrides, support reassignment/cancellation/reversal. |
| Product becomes too complex for SMEs | 35%–50% | High | Keep role dashboards simple and workflow-driven. |
| Launch delay from overbuilding | 40%–60% | High | Prioritize operational core before advanced customer portal, loyalty, AI, and inventory. |

# 17. Final Scope Statement

**Servana by Citrus shall be a production-ready, secure, scalable, multi-tenant SaaS web platform created and operated by Citrus Labs Limited for service-based SMEs. The platform shall support Merchant Administrator self-registration, automatic tenant creation, Merchant Owner / Merchant Administrator single-account ownership, first-time merchant setup, service fee tier selection, Magic Link login for all users, branch creation by Merchant Administrator, branch-level service and pricing configuration, branch operations, HR-controlled staff invitation and same-Branch staff management, Finance-controlled offline payment validation, Front Office client-facing workflows, Merchant Personnel own-scope service work, client records, walk-ins, appointments, queue management, client selection of next available or preferred merchant personnel at a Super Administrator-configured extra cost, service sessions, invoices with merchant logo, offline payment recording, payment validation, receipt generation after validation, commissions configured by Merchant Human Resource, platform fee tracking, dashboards, reports, notifications, tamper-evident audit logs, and deployment-grade security, testing, observability, and scalability.**

**The platform shall not use a Super Administrator-created merchant onboarding model, shall not require merchant KYC/compliance submission for Merchant Administrator self-registration, shall not split Merchant Owner from Merchant Administrator, shall not give Merchant Administrator broad user/service/pricing/personnel-assignment authority beyond the expressly permitted functions, and shall not provide Merchant Personnel with contact export functionality.**
