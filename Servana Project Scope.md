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
15. Client contact access control.
16. Platform fee calculation.
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

The Super Administrator is the Citrus Labs Limited platform-owner role. This account governs the entire SaaS ecosystem across all merchants.

### Core Functionalities

* Create, activate, suspend, and deactivate merchant accounts.
* Configure platform-wide settings.
* Configure the platform service fee.
* Configure billing cycles.
* Configure client-contact download fees.
* Configure the extra fee for preferred merchant personnel waiting.
* View all merchants.
* View platform-wide reports.
* View audit logs.
* Monitor suspicious usage.
* Manage internal platform roles.
* Control platform-level feature flags.
* Review merchant compliance status.

### Must Not Do

The Super Administrator must not directly perform merchant operations such as serving clients, assigning service sessions, manipulating branch queues, or editing merchant receipts outside controlled governance workflows.

---

## 3.2 Merchant Administrator Account

### Purpose

The Merchant Administrator is the business owner, manager, or authorized operator of a merchant business.

### Core Functionalities

* Create and manage the merchant profile.
* Verify and activate merchant account users.
* Create and manage branch accounts.
* Create or approve Merchant Human Resource users.
* Create or approve Merchant Finance users.
* Create or approve Merchant Front Office users.
* Create or approve Merchant Audit users.
* Link Merchant Personnel users.
* Configure services.
* Configure pricing.
* Configure personnel assignment rules.
* Configure commission rules.
* View all invoices under the merchant.
* View all branches under the merchant.
* View merchant-level revenue reports.
* View merchant-level platform fee records.
* View staff performance.
* Suspend inactive or non-compliant merchant users.
* Control whether Front Office users can record payments, issue receipts, or only submit payment records for Finance validation.

### Authentication Rule

Merchant Administrator logs in via Magic Link sent to email.

---

## 3.3 Merchant Branch Account

### Purpose

A Merchant Branch represents a physical or operational business location.

Examples:

* Westlands branch
* Kilimani salon branch
* CBD barbershop branch
* Spa branch inside a hotel
* Massage parlour branch

### Core Functionalities

* Manage branch profile.
* Manage branch operating hours.
* Manage branch service availability.
* Manage branch personnel assignments.
* View branch queue.
* View branch appointments.
* View branch revenue.
* View branch invoices.
* View branch receipts.
* View branch payment records.
* View branch-level audit logs.

### Implementation Note

A branch should be implemented as both:

1. A **branch entity** in the data model.
2. A **branch access scope** for merchant users.

This avoids confusing a branch location with a human user while still allowing branch-specific login permissions.

---

## 3.4 Merchant Human Resource Account

### Purpose

The Merchant Human Resource Account manages staff identity, employment status, role assignment, and access control under the merchant.

### Core Functionalities

* Add staff users.
* Edit staff users.
* Deactivate staff users.
* Assign roles.
* Assign users to branches.
* Maintain staff employment records.
* Control staff availability.
* Maintain personnel profiles.
* Manage account activation status.
* Maintain role history.
* Request Merchant Administrator approval where required.

### Authentication Rule

HR users log in via Magic Link only after the Merchant Administrator has marked their email as active.

---

## 3.5 Merchant Finance Account

### Purpose

The Merchant Finance Account manages offline payment validation, financial control, invoice payment status, receipts, and financial reports.

### Core Functionalities

* View invoices.
* Validate offline payments.
* Record payment references.
* Approve or reject payment records.
* Generate receipts after payment validation.
* View outstanding balances.
* View voided invoices.
* View refunds recorded externally.
* View platform fees owed.
* View commission liabilities.
* Export finance reports.
* Lock financial periods, where required.
* Review payment disputes.
* Audit payment activity.

### Critical Rule

Finance should validate payment truth. Front Office may record payment details, but Finance should approve high-risk or merchant-configured payment states.

---

## 3.6 Merchant Front Office Account

### Purpose

The Merchant Front Office Account handles client-facing operations.

### Core Functionalities

* Register clients.
* Retrieve existing clients.
* Create walk-in sessions.
* Create appointment records.
* Select services.
* Assign next available personnel.
* Allow the client to select preferred personnel at extra cost.
* Manage queue status.
* Create service sessions.
* Generate invoices.
* Record offline payment method.
* Submit payment details for Finance validation.
* Generate receipts only where permitted.
* View daily branch activity.
* View current appointments and walk-ins.
* Notify clients of queue or service status.

### Authentication Rule

Front Office users log in via Magic Link only after their email has been marked active by the Merchant Administrator.

---

## 3.7 Merchant Personnel Account

### Purpose

Merchant Personnel are the service providers.

Examples:

* Barber
* Hairdresser
* Stylist
* Massage therapist
* Nail technician
* Beautician
* Facial therapist
* Grooming specialist

### Core Functionalities

* View own dashboard.
* View assigned clients.
* View own service queue.
* View own appointments.
* View clients personally served.
* View own service history.
* View revenue personally generated.
* View commission earned.
* View commission pending.
* View preferred-personnel requests.
* Request client contact downloads for personally served clients.
* Access only allowed client contact data.

### Strict Data Rule

Merchant Personnel must not export the merchant’s full client database.

They may only access client contacts for clients they personally served, subject to system permissions, consent rules, payment rules, and audit logs.

---

## 3.8 Merchant Audit Account

### Purpose

The Merchant Audit Account provides read-only operational and financial oversight.

### Core Functionalities

* View immutable audit logs.
* View role changes.
* View branch changes.
* View invoice history.
* View payment validation logs.
* View receipt logs.
* View queue reassignment logs.
* View preferred-personnel fee logs.
* View contact export logs.
* Export audit reports.
* Flag suspicious activity.

### Access Rule

Merchant Audit must be read-only. It must not edit clients, services, invoices, payments, receipts, users, queues, or commissions.

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

### Optional Future Login

A full General End-User portal can later allow clients to:

* View appointments.
* View receipts.
* View service history.
* Join a queue remotely.
* Choose preferred personnel.
* Update profile details.

---

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
| Personnel commission    | Disabled by default unless merchant enables it             |
| Receipt visibility      | Visible as separate receipt line item                      |
| Audit visibility        | Visible to Super Admin, Merchant Admin, Finance, and Audit |

---

# 5. Core Modules Required for Product Launch

## 5.1 Merchant Onboarding Module

### Purpose

Create, verify, activate, suspend, and manage service-based SME tenants.

### Required Features

* Merchant registration.
* Merchant profile.
* Business category.
* Branch/location setup.
* Merchant Administrator setup.
* Account-opening fee status, where applicable.
* Merchant activation workflow.
* Merchant suspension workflow.
* Merchant deactivation workflow.
* Merchant status history.
* Document upload, where required.
* Audit logs.

---

## 5.2 Authentication and Access Control Module

### Purpose

Secure account access and prevent unauthorized merchant or branch data exposure.

### Required Features

* Magic Link login.
* One-time-use tokens.
* Token expiry.
* Email verification.
* Active email verification from Merchant Administrator Account.
* Role-based access control.
* Permission-based access control.
* Tenant-based access control.
* Branch-based access control.
* Login rate limiting.
* Session timeout.
* Login audit logs.
* Optional MFA for high-privilege roles.

### Merchant Login Rule

All Merchant users shall log in through Magic Link sent to email only after their email is verified as active under the relevant Merchant Administrator Account.

---

## 5.3 Merchant and Branch Management Module

### Required Features

* Merchant profile.
* Branch creation.
* Branch profile.
* Branch operating hours.
* Branch status.
* Branch service availability.
* Branch personnel assignment.
* Branch reports.
* Branch audit logs.

---

## 5.4 Staff and Role Management Module

### Required Features

* Staff creation.
* Staff activation.
* Staff suspension.
* Staff deactivation.
* Role assignment.
* Branch assignment.
* Personnel service eligibility.
* Permission history.
* Employment status.
* Audit logs.

---

## 5.5 Service Catalogue Module

### Required Features

* Service creation.
* Service editing.
* Service archiving.
* Service category.
* Price.
* Estimated duration.
* Eligible personnel.
* Branch availability.
* Active/inactive status.
* Discount support.
* Preferred personnel fee eligibility.

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

---

## 5.7 Appointment and Walk-In Module

### Required Features

* Walk-in creation.
* Appointment creation.
* Appointment rescheduling.
* Appointment cancellation.
* No-show marking.
* Client check-in.
* Service selection.
* Personnel assignment.
* Preferred personnel selection.
* Appointment status history.
* Notification triggers.

---

## 5.8 Queue Management Module

### Required Features

* Branch queue board.
* Personnel-specific queue.
* Next available personnel assignment.
* Preferred personnel assignment.
* Estimated wait time.
* Queue status:

  * Waiting
  * Assigned
  * In service
  * Completed
  * Cancelled
  * No-show
* Queue reorder permission.
* Preferred personnel override reason.
* Queue audit logs.

---

## 5.9 Service Session Module

### Required Features

* Client selected.
* Service selected.
* Branch selected.
* Personnel assigned.
* Session status.
* Start timestamp.
* End timestamp.
* Service notes.
* Cancellation reason.
* Invoice trigger.
* Audit trail.

Recommended statuses:

* Draft
* Waiting
* Assigned
* In progress
* Completed
* Cancelled
* Invoiced
* Paid

---

## 5.10 Invoice Module

### Required Features

* Unique invoice number.
* Merchant.
* Branch.
* Client.
* Service.
* Assigned personnel.
* Invoice line items.
* Service price.
* Discount.
* Preferred personnel fee.
* Final invoice amount.
* Payment status.
* Created by.
* Timestamp.
* Void workflow.
* Audit log.

---

## 5.11 Offline Payment Recording Module

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
* External refund record.
* Payment dispute flag.

Recommended payment statuses:

* Unpaid
* Partially paid
* Paid
* Pending validation
* Rejected
* Voided
* Refunded externally
* Disputed

---

## 5.12 Receipt Module

### Required Features

* Receipt number.
* Linked invoice.
* Linked payment record.
* Payment method.
* Final paid amount.
* Issued by.
* Issued timestamp.
* Downloadable receipt.
* Receipt audit log.

### Critical Rule

A receipt must not be generated before payment is marked as received or validated, depending on merchant configuration.

---

## 5.13 Citrus Billing Engine

### Purpose

The Citrus Billing Engine calculates and tracks what merchants owe Citrus Labs Limited.

### Required Features

* Account-opening fee tracking.
* Platform service fee calculation.
* Preferred personnel fee treatment rules.
* Personnel contact-download fee rules.
* Settlement cycle tracking.
* Platform fee ledger.
* Merchant balance.
* Overdue balance tracking.
* Suspension triggers.
* Fee exemption rules.
* Billing audit logs.

---

## 5.14 Commission Tracking Module

### Required Features

* Commission rule per personnel.
* Commission rule per service.
* Optional branch-level commission rules.
* Commission calculated only after invoice payment is confirmed.
* Commission pending status.
* Commission earned status.
* Commission paid status, optional.
* Reversal on voided invoice.
* Preferred personnel surcharge commission setting.

---

## 5.15 Personnel Client Contact Download Module

### Required Features

* Personnel can view personally served clients.
* Personnel can request export.
* System calculates contact download fee.
* Fee is set by Super Administrator.
* Export is blocked until requirements are satisfied.
* Export includes only allowed client contacts.
* Export expires after configured time.
* Audit log records export request and download.

Exportable fields:

* Client name.
* Phone number.
* Email, where available.
* Last service date.
* Service category.

Non-exportable fields:

* Merchant-wide client list.
* Other personnel’s clients.
* Merchant revenue.
* Payment details.
* Private internal notes.

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
* Contact download fees.
* Preferred personnel fee totals.
* Overdue merchants.
* Audit alerts.

### Merchant Administrator Dashboard

* Today’s sales.
* Weekly sales.
* Monthly sales.
* Branch performance.
* Personnel performance.
* Services completed.
* Clients served.
* Repeat clients.
* Invoices.
* Payment methods.
* Platform fees owed.
* Commission liabilities.
* Preferred personnel demand.

### Merchant Finance Dashboard

* Pending payment validations.
* Paid invoices.
* Unpaid invoices.
* Partial payments.
* Receipts issued.
* Voided invoices.
* Outstanding balances.
* Commission obligations.
* Platform fee obligations.

### Merchant Front Office Dashboard

* Today’s appointments.
* Walk-ins.
* Active queue.
* Clients waiting.
* Clients in service.
* Completed sessions.
* Paid/unpaid invoices.
* Receipts issued.

### Merchant Personnel Dashboard

* Assigned clients.
* Own queue.
* Own appointments.
* Clients served.
* Services completed.
* Revenue generated.
* Commission earned.
* Commission pending.
* Preferred personnel requests.
* Eligible contact downloads.

---

## 5.17 Notifications Module

Required notification types:

* Magic Link login email.
* Staff activation email.
* Appointment confirmation.
* Appointment cancellation.
* Queue update.
* Preferred personnel wait confirmation.
* Payment validation notice.
* Receipt availability.
* Merchant suspension warning.
* Platform fee overdue warning.

Launch channels:

* Email: required.
* SMS/WhatsApp: recommended but can be phased.

---

## 5.18 Audit Logging Module

Audit logs must capture:

* Merchant creation.
* Merchant activation/suspension/deactivation.
* Branch creation.
* User creation.
* User role changes.
* Magic Link login events.
* Service creation/editing.
* Client record changes.
* Queue changes.
* Preferred personnel selection.
* Preferred personnel override.
* Invoice creation.
* Payment recording.
* Payment validation.
* Receipt generation.
* Commission rule changes.
* Contact export request.
* Contact export download.
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
ip_address
user_agent
created_at
```

---

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
  - Contact Exports
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

| Entity                            | Purpose                                    |
| --------------------------------- | ------------------------------------------ |
| `users`                           | Global identity records                    |
| `merchants`                       | Merchant tenants                           |
| `merchant_branches`               | Branches under merchants                   |
| `merchant_users`                  | User-to-merchant-role mapping              |
| `roles`                           | Role records                               |
| `permissions`                     | Permission records                         |
| `magic_login_tokens`              | Magic Link tokens                          |
| `services`                        | Service catalogue                          |
| `service_categories`              | Service grouping                           |
| `personnel_service_eligibilities` | Which personnel can perform which services |
| `clients`                         | Client records                             |
| `appointments`                    | Scheduled service bookings                 |
| `queue_entries`                   | Walk-in and queue records                  |
| `service_sessions`                | Actual service delivery                    |
| `preferred_personnel_fee_rules`   | Super Admin fee configuration              |
| `invoices`                        | Invoice headers                            |
| `invoice_items`                   | Invoice line items                         |
| `payment_records`                 | Offline payment records                    |
| `receipts`                        | Receipt records                            |
| `platform_fee_ledger`             | Citrus fee records                         |
| `commission_rules`                | Commission configuration                   |
| `commission_ledger`               | Personnel commission records               |
| `contact_export_requests`         | Contact download requests                  |
| `notification_logs`               | Notification tracking                      |
| `audit_logs`                      | Immutable sensitive activity records       |

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

---

# 8. API Route Structure

All API routes should use `/api/v1`.

Recommended route groups:

```text
/api/v1/auth
/api/v1/me
/api/v1/platform
/api/v1/platform/merchants
/api/v1/platform/settings
/api/v1/merchants
/api/v1/branches
/api/v1/merchant-users
/api/v1/hr/staff
/api/v1/services
/api/v1/clients
/api/v1/appointments
/api/v1/queue
/api/v1/service-sessions
/api/v1/invoices
/api/v1/payments
/api/v1/receipts
/api/v1/billing
/api/v1/commissions
/api/v1/contact-exports
/api/v1/reports
/api/v1/audit-logs
/api/v1/notifications
```

API rules:

* Authenticate protected routes.
* Authorize every tenant-owned resource.
* Use UUIDs or ULIDs externally.
* Validate every request.
* Rate-limit sensitive endpoints.
* Paginate large responses.
* Return consistent JSON.
* Never expose internal IDs unnecessarily.
* Never rely on frontend authorization.

---

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

| Test Area               | Required Coverage                                                     |
| ----------------------- | --------------------------------------------------------------------- |
| Authentication          | Magic Link request, expiry, reuse prevention, invalid token rejection |
| Merchant activation     | Inactive merchants blocked from live operations                       |
| Authorization           | Role and permission enforcement                                       |
| Tenant isolation        | Merchant cannot access another merchant’s data                        |
| Branch isolation        | Branch user cannot access unassigned branch records                   |
| Staff activation        | Only active merchant users can log in                                 |
| Queue                   | Next available and preferred personnel assignment                     |
| Preferred personnel fee | Correct surcharge calculation and invoice line item                   |
| Invoices                | Totals, discounts, voids, invoice numbering                           |
| Payments                | Offline payment recording and validation                              |
| Receipts                | Receipt blocked until payment is valid                                |
| Commissions             | Commission created only after payment confirmation                    |
| Contact exports         | Personnel exports only personally served clients                      |
| Audit logs              | Sensitive events are logged immutably                                 |
| API validation          | Invalid requests rejected                                             |
| Frontend workflows      | Critical UI flows tested                                              |

---

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

| Feature                                            | Priority |
| -------------------------------------------------- | -------- |
| Magic Link authentication                          | Critical |
| Merchant Administrator account                     | Critical |
| Merchant user activation by Merchant Administrator | Critical |
| Tenant isolation                                   | Critical |
| Branch isolation                                   | Critical |
| Role and permission management                     | Critical |
| Merchant onboarding                                | Critical |
| Branch management                                  | Critical |
| Staff management                                   | Critical |
| Service catalogue                                  | Critical |
| Client records                                     | Critical |
| Walk-in handling                                   | Critical |
| Appointment handling                               | Critical |
| Queue management                                   | Critical |
| Preferred personnel waiting fee                    | Critical |
| Service sessions                                   | Critical |
| Invoice generation                                 | Critical |
| Offline payment recording                          | Critical |
| Payment validation                                 | Critical |
| Receipt generation                                 | Critical |
| Citrus Billing Engine                              | Critical |
| Commission tracking                                | Critical |
| Contact download controls                          | High     |
| Dashboards                                         | High     |
| Reports                                            | High     |
| Audit logs                                         | Critical |
| Notifications                                      | High     |
| Responsive UI                                      | Critical |
| Accessibility                                      | Critical |
| Automated tests                                    | Critical |
| Production monitoring                              | Critical |

---

# 16. Risk Assessment

| Risk                                    |                                       Likelihood |   Impact | Mitigation                                                                                   |
| --------------------------------------- | -----------------------------------------------: | -------: | -------------------------------------------------------------------------------------------- |
| Merchants under-record invoices         |                                          60%–75% |     High | Make invoices necessary for commissions, receipts, queue history, and owner reporting.       |
| Staff misuse client contact exports     |  35%–50% without controls; 15%–25% with controls |     High | Restrict exports to personally served clients, require payment/approval, audit every export. |
| Cross-tenant data leakage               | 8%–15% if poorly built; <2% with strong controls | Critical | Tenant scopes, policies, UUIDs, access tests, code review.                                   |
| Front Office manipulates payment status |                                          30%–45% |     High | Finance validation, immutable logs, restricted receipt permissions.                          |
| Preferred personnel disputes            |                                          25%–40% |   Medium | Show wait time, show fee, require confirmation, audit overrides.                             |
| Merchant user login abuse               |                                          25%–40% |   Medium | Magic Link expiry, active-email verification, rate limiting, session logs.                   |
| Product becomes too complex for SMEs    |                                          35%–50% |     High | Keep role dashboards simple and workflow-driven.                                             |
| Launch delay from overbuilding          |                                          40%–60% |     High | Prioritize operational core before advanced customer portal, loyalty, AI, and inventory.     |

---

# 17. Final Scope Statement

**Servana by Citrus shall be a production-ready, secure, scalable, multi-tenant SaaS web platform created and operated by Citrus Labs Limited for service-based SMEs. The platform shall support merchant onboarding, Merchant Administrator-controlled user activation, Magic Link login, branch operations, staff and personnel management, client records, walk-ins, appointments, queue management, client selection of next available or preferred merchant personnel at a Super Administrator-configured extra cost, service sessions, invoices, offline payment recording, payment validation, receipts, commissions, platform fee tracking, contact export controls, dashboards, reports, notifications, audit logs, and deployment-grade security, testing, observability, and scalability.**
