# Servana by Citrus — Production-Grade Software Development Plan

This plan is based on the uploaded implementation manifesto, which requires a real production SaaS plan, not vague advice or a prototype plan, and requires the IDE coding agent to implement step by step with security, testing, and verification evidence.  It also uses Servana’s product scope: a multi-tenant SaaS for service-based SMEs such as barbershops, salons, spas, massage parlours, grooming studios, and beauty parlours. 

---

## 1. Executive Architecture Summary

Servana by Citrus shall be built as a secure, scalable, multi-tenant SaaS web application operated by Citrus Labs Limited for service-based SMEs. Its core function is not merely booking, POS, invoicing, staff management, or CRM. It is a service-operations control system that manages merchants, branches, staff, clients, walk-ins, appointments, queues, service sessions, invoices, offline payment records, receipts, commissions, platform fees, client-contact permissions, reports, notifications, and audit logs.

The platform must support:

1. **Citrus Labs Super Administrator**

   * Owns platform-level governance.
   * Creates, activates, suspends, and deactivates merchants.
   * Configures platform fees, billing cycles, contact-download fees, preferred-personnel fees, feature flags, and internal platform roles.

2. **Merchant Administrator**

   * Owns the merchant tenant.
   * Manages merchant profile, branches, users, roles, services, pricing, commission rules, revenue reports, and platform fee visibility.

3. **Merchant Branch**

   * Represents physical or operational locations.
   * Used as both a data entity and an access scope.

4. **Merchant HR**

   * Manages staff identity, employment records, branch assignment, role history, activation, suspension, and deactivation.

5. **Merchant Finance**

   * Validates offline payments, issues or controls receipts, manages payment disputes, views financial reports, and audits payment truth.

6. **Merchant Front Office**

   * Handles daily client-facing workflows: client registration, walk-ins, appointments, service selection, queue assignment, invoice creation, offline payment capture, and receipt submission where allowed.

7. **Merchant Personnel**

   * Service providers such as barbers, stylists, therapists, beauticians, nail technicians, and grooming specialists.
   * Can view own queue, assigned clients, service history, commissions, and limited contact export access.

8. **Merchant Audit**

   * Read-only oversight account.
   * Views immutable logs, role changes, queue changes, invoice history, payment validations, receipts, exports, and suspicious activity.

9. **Client Records**

   * Initially not full login accounts.
   * Store profiles, contact details, visit history, services consumed, assigned personnel, invoices, receipts, appointments, queue participation, consent records, and communication preferences.

Servana must enforce tenant isolation so one merchant can never access, infer, edit, enumerate, export, or delete another merchant’s data. This is a hard architectural rule, not a UI preference. 

---

## 2. Assumptions and Constraints

### 2.1 Confirmed Source-of-Truth Requirements

The build must follow the uploaded manifesto:

* The agent must not guess.
* Every technical decision must identify the evidence, requirement, failure risk, and verification method.
* Fixes must address proven root causes, not symptoms.
* Testing must include unit, feature, API, authorization, tenant isolation, validation, frontend, browser/E2E, and security regression tests.
* Every completed phase must demonstrate working proof through test results, API examples, database verification, authorization denial examples, tenant isolation proof, edge-case checks, and completion criteria. 

### 2.2 Technical Assumptions

Use this fixed implementation stack:

| Layer            | Decision                                                                   |
| ---------------- | -------------------------------------------------------------------------- |
| Backend          | Laravel                                                                    |
| Backend Language | PHP 8.2+                                                                   |
| Frontend         | Vue.js + TypeScript                                                        |
| Styling          | Tailwind CSS                                                               |
| Database         | PostgreSQL                                                                 |
| Auth             | Laravel Sanctum + Magic Link flow                                          |
| API              | REST, `/api/v1`                                                            |
| Build Tool       | Vite                                                                       |
| Cache            | Redis                                                                      |
| Queues           | Redis-backed Laravel queues                                                |
| Storage          | S3-compatible object storage                                               |
| Search           | Meilisearch for MVP, upgradeable later                                     |
| Deployment       | Dockerized deployment with CI/CD                                           |
| Testing          | PHPUnit/Pest, Laravel feature tests, API tests, Playwright/Cypress, Vitest |

This aligns with the Servana technical architecture, which requires Laravel, PHP 8.2+, Vue or React, TypeScript preferred, PostgreSQL/MySQL, Laravel Sanctum, Redis queues/cache, S3-compatible storage, search, and Dockerized CI/CD deployment. 

### 2.3 Product Constraints

Servana payments are **offline/off-platform**. The platform records payment method, amount, reference, status, and validation details, but it must not process client-to-merchant payments inside the platform. Supported offline methods include cash, M-Pesa, bank transfer, card terminal, voucher, split payment, and merchant-defined offline methods. 

This means:

* No embedded card processing in MVP.
* No direct M-Pesa STK Push in MVP.
* No payment gateway settlement logic in MVP.
* Payment truth is validated by Finance or merchant-configured approval rules.
* Receipts are generated only after payment is valid.

### 2.4 Realistic Delivery Projection

For a solo founder/developer using an IDE-based AI coding agent:

| Build Level                                                                                                   | Likely Timeline | Probability |
| ------------------------------------------------------------------------------------------------------------- | --------------: | ----------: |
| Secure technical foundation only                                                                              |       3–5 weeks |         70% |
| MVP operational core                                                                                          |     10–16 weeks |         60% |
| Production-ready launch with tests, CI/CD, monitoring, and hardened security                                  |     20–32 weeks |         55% |
| Full scope with all dashboards, reports, exports, billing, commissions, audit, notifications, and polished UX |     32–52 weeks |         50% |

Biggest delay risks:

* Underestimating tenant and branch isolation.
* Overbuilding client portal features too early.
* Weak payment-validation workflow.
* Insufficient authorization tests.
* Complex commission and platform fee edge cases.
* Poorly planned audit logging.

---

## 3. Non-Negotiable Security Rules

1. **Every merchant-owned table must include `merchant_id`.**
2. **Every branch-owned table must include `branch_id` where branch scoping applies.**
3. **Every protected API route must use authentication middleware.**
4. **Every tenant-owned resource must be authorized server-side.**
5. **Frontend permission checks are UX only, never security.**
6. **Sequential database IDs must not be exposed in public APIs. Use UUIDs or ULIDs.**
7. **Magic Links must be one-time-use, hashed in storage, expire quickly, and be rate-limited.**
8. **A user cannot log in merely because their email exists. Login must verify merchant membership, active status, active role, no suspension, branch assignment where applicable, and valid unused token.** 
9. **Super Admin must not perform normal merchant operations except through controlled governance workflows.**
10. **Receipt generation must be blocked until payment is received or validated according to merchant configuration.** 
11. **Merchant Audit role must remain read-only.**
12. **Personnel must never export the merchant-wide client database.**
13. **Payment records, receipt records, platform fee records, commission records, exports, and role changes must be audit logged.**
14. **Sensitive data must never be logged: passwords, tokens, API keys, payment references where sensitive, signed URLs, session tokens, or secrets.**
15. **All file downloads containing private merchant data must use signed URLs.**

---

## 4. System Architecture

### 4.1 High-Level Architecture

```text
Browser / Client UI
  ↓
Vue.js + TypeScript SPA
  ↓
Laravel API Layer
  ↓
Sanctum Session/Auth Guard
  ↓
Magic Link Authentication
  ↓
Tenant Resolver Middleware
  ↓
Branch Scope Middleware
  ↓
RBAC + Permission Policies
  ↓
Domain Services
  ↓
PostgreSQL
  ↓
Redis Cache / Redis Queues
  ↓
S3-Compatible Storage
  ↓
Email Provider / Monitoring / Logs / Backups
```

This matches Servana’s recommended architecture: browser client, Vue/React SPA, Laravel API, Magic Link authentication, RBAC + permission + tenant + branch authorization, domain services, database, Redis queues/cache, email, storage, monitoring, and error tracking. 

### 4.2 Core Backend Domains

Create these Laravel bounded domains under `app/Domain`:

```text
app/
  Domain/
    Platform/
    Merchants/
    Branches/
    Auth/
    AccessControl/
    Staff/
    Services/
    Clients/
    Appointments/
    Queue/
    ServiceSessions/
    Invoices/
    Payments/
    Receipts/
    Billing/
    Commissions/
    ContactExports/
    Reports/
    Notifications/
    Audit/
    Files/
```

Each domain must contain:

```text
Models/
Actions/
Services/
Policies/
Data/
Enums/
Events/
Listeners/
Jobs/
Queries/
```

Example:

```text
app/Domain/Invoices/
  Models/Invoice.php
  Models/InvoiceItem.php
  Actions/CreateInvoiceAction.php
  Actions/VoidInvoiceAction.php
  Services/InvoiceNumberService.php
  Services/InvoiceTotalService.php
  Policies/InvoicePolicy.php
  Data/CreateInvoiceData.php
  Enums/InvoiceStatus.php
  Events/InvoiceCreated.php
  Jobs/GenerateInvoicePdfJob.php
  Queries/InvoiceQuery.php
```

### 4.3 Tenant Strategy

Use **single database, shared schema, tenant-scoped rows**.

Reason: Servana targets many SMEs. Separate databases per merchant would increase operational complexity too early. Shared schema with strict `merchant_id` scoping is realistic, cheaper, and maintainable for MVP and early scale.

Mitigation: strict middleware, global query scopes where safe, policies, service-layer tenant assertions, database indexes, and tenant isolation tests.

---

## 5. Backend Architecture

### 5.1 Laravel Structure

Recommended backend structure:

```text
app/
  Http/
    Controllers/Api/V1/
    Middleware/
    Requests/
    Resources/
  Domain/
  Support/
    Tenant/
    Branch/
    Money/
    Audit/
    Security/
  Providers/
  Console/
  Jobs/
  Events/
  Listeners/
  Policies/
routes/
  api.php
  auth.php
database/
  migrations/
  seeders/
  factories/
tests/
  Feature/
  Unit/
  Api/
  Authorization/
  TenantIsolation/
```

### 5.2 Core Backend Patterns

Use these implementation patterns:

| Pattern              | Use                                                  |
| -------------------- | ---------------------------------------------------- |
| Form Request classes | Validate all mutating input                          |
| Policies             | Enforce model-level authorization                    |
| Actions              | Execute one business use case                        |
| Services             | Shared business rules                                |
| Query classes        | Filter, sort, and paginate collections               |
| API Resources        | Shape consistent JSON                                |
| Events/Listens       | Audit logs, notifications, background side effects   |
| Jobs                 | Emails, reports, exports, receipt PDFs, invoice PDFs |
| Enums                | Status fields, role codes, payment methods           |
| DTO/Data classes     | Move validated payloads safely                       |
| Transactions         | Multi-write financial workflows                      |

### 5.3 Required Backend Packages

Minimum package set:

```bash
composer require laravel/sanctum
composer require spatie/laravel-permission
composer require spatie/laravel-activitylog
composer require laravel/horizon
composer require league/flysystem-aws-s3-v3
composer require meilisearch/meilisearch-php
composer require barryvdh/laravel-dompdf
composer require pestphp/pest --dev
composer require pestphp/pest-plugin-laravel --dev
```

Use Spatie Permission carefully with tenant/team support. Roles must be scoped per merchant, not globally granted across all merchants.

### 5.4 Domain Service Rules

Every write action must follow this sequence:

```text
1. Resolve authenticated user.
2. Resolve active merchant context.
3. Resolve branch context where required.
4. Authorize user through policy.
5. Validate payload through Form Request.
6. Start database transaction for multi-table writes.
7. Create/update records with explicit merchant_id and branch_id.
8. Create audit log.
9. Dispatch events/jobs after commit.
10. Return API Resource.
```

Never allow controllers to contain large business logic.

---

## 6. Frontend Architecture

### 6.1 Frontend Decision

Use **Vue 3 + TypeScript + Vite + Tailwind CSS**.

Reason:

* Laravel-friendly.
* Strong component architecture.
* TypeScript improves maintainability.
* Tailwind supports fast implementation of responsive SaaS layouts.
* Works cleanly with Laravel Sanctum SPA authentication.

### 6.2 Frontend Folder Structure

```text
resources/js/
  app.ts
  router/
    index.ts
    guards.ts
  layouts/
    AuthLayout.vue
    PlatformAdminLayout.vue
    MerchantLayout.vue
    BranchLayout.vue
    FrontOfficeLayout.vue
    FinanceLayout.vue
    PersonnelLayout.vue
    AuditLayout.vue
  pages/
    auth/
    platform/
    merchant/
    branches/
    hr/
    services/
    clients/
    appointments/
    queue/
    service-sessions/
    invoices/
    payments/
    receipts/
    billing/
    commissions/
    contact-exports/
    reports/
    audit/
    settings/
  components/
    ui/
    forms/
    tables/
    modals/
    dashboards/
    queue/
    invoices/
    receipts/
    reports/
  stores/
    authStore.ts
    tenantStore.ts
    branchStore.ts
    permissionStore.ts
    themeStore.ts
  services/
    apiClient.ts
    authService.ts
    tenantService.ts
    permissionService.ts
  types/
  utils/
```

This follows the recommended Servana frontend structure containing role-specific layouts, pages, components, API client, auth service, tenant context, permission service, and stores. 

### 6.3 Frontend Security Rules

The frontend must never contain:

* Secret keys.
* Payment secrets.
* Authorization truth.
* Platform fee calculation authority.
* Commission calculation authority.
* Receipt issuance authority.
* Tenant access decisions.
* Branch access decisions.

The frontend can hide UI actions based on permissions, but the backend must still deny unauthorized requests.

### 6.4 Global UI States

Every list, dashboard, and form must handle:

```text
loading
empty
success
error
unauthorized
merchant_suspended
inactive_user
no_branch_access
no_permission
pending_payment_validation
network_error
validation_error
```

---

## 7. Database Architecture

### 7.1 Identifier Strategy

Use internal `id` as big integer primary key for database joins and `ulid` as public identifier.

Pattern:

```php
$table->id();
$table->ulid('public_id')->unique();
```

Public APIs must use `public_id`, not sequential `id`.

### 7.2 Minimum Core Tables

Servana’s source scope lists required entities including users, merchants, branches, merchant users, roles, permissions, magic login tokens, services, clients, appointments, queue entries, service sessions, invoices, payment records, receipts, platform fee ledger, commission records, contact export requests, notifications, and audit logs. 

Implement at minimum:

```text
users
merchants
merchant_branches
merchant_users
roles
permissions
role_permission
magic_login_tokens
services
service_categories
personnel_service_eligibilities
clients
appointments
queue_entries
service_sessions
preferred_personnel_fee_rules
invoices
invoice_items
payment_records
receipts
platform_fee_ledger
commission_rules
commission_ledger
contact_export_requests
notification_logs
audit_logs
uploaded_files
merchant_settings
branch_settings
failed_jobs
job_batches
personal_access_tokens
```

### 7.3 Key Table Designs

#### `users`

Purpose: global identity record.

Columns:

```text
id BIGINT PK
public_id ULID UNIQUE
name VARCHAR(160)
email VARCHAR(255) UNIQUE
phone VARCHAR(32) NULL
profile_photo_path VARCHAR(500) NULL
email_verified_at TIMESTAMP NULL
last_login_at TIMESTAMP NULL
status ENUM(active, inactive, suspended)
theme_preference ENUM(light, dark, system) DEFAULT light
created_at TIMESTAMP
updated_at TIMESTAMP
deleted_at TIMESTAMP NULL
```

Indexes:

```text
UNIQUE(email)
INDEX(status)
INDEX(last_login_at)
```

Security:

* No tenant data stored directly here.
* User access to merchant data only through `merchant_users`.

#### `merchants`

Purpose: tenant root.

```text
id BIGINT PK
public_id ULID UNIQUE
name VARCHAR(180)
legal_name VARCHAR(220) NULL
business_category VARCHAR(120)
registration_number VARCHAR(100) NULL
kra_pin VARCHAR(50) NULL
primary_email VARCHAR(255)
primary_phone VARCHAR(32)
status ENUM(pending, active, suspended, deactivated)
onboarding_status ENUM(draft, submitted, approved, rejected, active)
account_opening_fee_status ENUM(not_required, pending, paid, waived)
created_by BIGINT FK users.id
activated_at TIMESTAMP NULL
suspended_at TIMESTAMP NULL
deactivated_at TIMESTAMP NULL
created_at TIMESTAMP
updated_at TIMESTAMP
deleted_at TIMESTAMP NULL
```

Indexes:

```text
INDEX(status)
INDEX(business_category)
INDEX(onboarding_status)
UNIQUE(public_id)
```

#### `merchant_branches`

Purpose: physical or operational branch.

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
name VARCHAR(180)
code VARCHAR(50)
phone VARCHAR(32) NULL
email VARCHAR(255) NULL
address TEXT NULL
city VARCHAR(100) NULL
status ENUM(active, inactive, suspended, closed)
timezone VARCHAR(100) DEFAULT Africa/Nairobi
operating_hours JSONB NULL
created_by BIGINT FK users.id
created_at TIMESTAMP
updated_at TIMESTAMP
deleted_at TIMESTAMP NULL
```

Constraints:

```text
UNIQUE(merchant_id, code)
INDEX(merchant_id, status)
```

#### `merchant_users`

Purpose: user membership under a merchant and optional branch scope.

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
user_id BIGINT FK users.id
role_id BIGINT FK roles.id
primary_branch_id BIGINT NULL FK merchant_branches.id
employment_status ENUM(active, inactive, suspended, terminated)
access_status ENUM(pending_activation, active, suspended, revoked)
activated_by BIGINT NULL FK users.id
activated_at TIMESTAMP NULL
suspended_at TIMESTAMP NULL
last_role_changed_at TIMESTAMP NULL
created_at TIMESTAMP
updated_at TIMESTAMP
deleted_at TIMESTAMP NULL
```

Constraints:

```text
UNIQUE(merchant_id, user_id)
INDEX(merchant_id, role_id)
INDEX(merchant_id, access_status)
INDEX(user_id)
```

#### `merchant_user_branches`

Purpose: many-to-many branch assignment.

```text
id BIGINT PK
merchant_user_id BIGINT FK merchant_users.id
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
assigned_by BIGINT FK users.id
created_at TIMESTAMP
```

Constraints:

```text
UNIQUE(merchant_user_id, branch_id)
INDEX(merchant_id, branch_id)
```

#### `roles`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT NULL FK merchants.id
name VARCHAR(80)
code VARCHAR(80)
scope ENUM(platform, merchant)
is_system BOOLEAN DEFAULT false
is_active BOOLEAN DEFAULT true
created_at
updated_at
```

Default roles:

```text
super_admin
merchant_owner
merchant_admin
merchant_hr
merchant_finance
merchant_front_office
merchant_personnel
merchant_audit
```

#### `permissions`

```text
id BIGINT PK
code VARCHAR(120) UNIQUE
name VARCHAR(160)
description TEXT NULL
scope ENUM(platform, merchant, branch)
created_at
updated_at
```

#### `role_permission`

```text
role_id BIGINT FK roles.id
permission_id BIGINT FK permissions.id
created_at
PRIMARY KEY(role_id, permission_id)
```

#### `magic_login_tokens`

Purpose: one-time Magic Link authentication.

```text
id BIGINT PK
public_id ULID UNIQUE
user_id BIGINT FK users.id
merchant_id BIGINT NULL FK merchants.id
email VARCHAR(255)
token_hash CHAR(64)
purpose ENUM(login, email_verification, invitation_acceptance)
expires_at TIMESTAMP
used_at TIMESTAMP NULL
ip_address INET NULL
user_agent TEXT NULL
created_at TIMESTAMP
```

Constraints:

```text
UNIQUE(token_hash)
INDEX(email, purpose)
INDEX(user_id, expires_at)
INDEX(merchant_id, email)
```

Security:

* Store hash only.
* Never store raw token.
* Token must expire.
* Token must be single-use.
* Rate-limit requests.

#### `service_categories`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT NULL FK merchant_branches.id
name VARCHAR(140)
description TEXT NULL
status ENUM(active, inactive, archived)
created_by BIGINT FK users.id
created_at
updated_at
deleted_at NULL
```

#### `services`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
service_category_id BIGINT FK service_categories.id
name VARCHAR(160)
description TEXT NULL
base_price DECIMAL(12,2)
estimated_duration_minutes INTEGER
is_discountable BOOLEAN DEFAULT true
preferred_personnel_fee_eligible BOOLEAN DEFAULT true
status ENUM(active, inactive, archived)
created_by BIGINT FK users.id
created_at
updated_at
deleted_at NULL
```

Indexes:

```text
INDEX(merchant_id, status)
INDEX(merchant_id, service_category_id)
```

#### `branch_services`

```text
id BIGINT PK
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
service_id BIGINT FK services.id
is_available BOOLEAN DEFAULT true
created_at
updated_at
UNIQUE(branch_id, service_id)
```

#### `personnel_service_eligibilities`

```text
id BIGINT PK
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
personnel_merchant_user_id BIGINT FK merchant_users.id
service_id BIGINT FK services.id
is_active BOOLEAN DEFAULT true
created_by BIGINT FK users.id
created_at
updated_at
UNIQUE(branch_id, personnel_merchant_user_id, service_id)
```

#### `clients`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT NULL FK merchant_branches.id
full_name VARCHAR(180)
phone VARCHAR(32)
email VARCHAR(255) NULL
gender VARCHAR(40) NULL
notes TEXT NULL
consent_status ENUM(unknown, granted, denied, withdrawn)
communication_preferences JSONB NULL
created_by BIGINT FK users.id
created_at
updated_at
deleted_at NULL
```

Constraints:

```text
UNIQUE(merchant_id, phone)
INDEX(merchant_id, full_name)
INDEX(merchant_id, branch_id)
```

#### `appointments`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
client_id BIGINT FK clients.id
service_id BIGINT FK services.id
preferred_personnel_user_id BIGINT NULL FK merchant_users.id
assigned_personnel_user_id BIGINT NULL FK merchant_users.id
scheduled_start_at TIMESTAMP
scheduled_end_at TIMESTAMP NULL
status ENUM(scheduled, checked_in, rescheduled, cancelled, no_show, completed)
cancellation_reason TEXT NULL
created_by BIGINT FK users.id
created_at
updated_at
deleted_at NULL
```

#### `queue_entries`

Purpose: walk-in and queue board.

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
client_id BIGINT FK clients.id
appointment_id BIGINT NULL FK appointments.id
service_id BIGINT FK services.id
assignment_type ENUM(next_available, preferred_personnel)
preferred_personnel_user_id BIGINT NULL FK merchant_users.id
assigned_personnel_user_id BIGINT NULL FK merchant_users.id
estimated_wait_minutes INTEGER NULL
status ENUM(waiting, assigned, in_service, completed, cancelled, no_show)
position INTEGER
override_reason TEXT NULL
created_by BIGINT FK users.id
created_at
updated_at
```

Indexes:

```text
INDEX(merchant_id, branch_id, status)
INDEX(merchant_id, assigned_personnel_user_id, status)
```

#### `service_sessions`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
queue_entry_id BIGINT NULL FK queue_entries.id
appointment_id BIGINT NULL FK appointments.id
client_id BIGINT FK clients.id
service_id BIGINT FK services.id
assigned_personnel_user_id BIGINT FK merchant_users.id
status ENUM(draft, waiting, assigned, in_progress, completed, cancelled, invoiced, paid)
started_at TIMESTAMP NULL
ended_at TIMESTAMP NULL
service_notes TEXT NULL
cancellation_reason TEXT NULL
created_by BIGINT FK users.id
created_at
updated_at
```

#### `preferred_personnel_fee_rules`

```text
id BIGINT PK
public_id ULID UNIQUE
scope ENUM(platform, merchant, branch, service_category, personnel_tier)
merchant_id BIGINT NULL FK merchants.id
branch_id BIGINT NULL FK merchant_branches.id
service_category_id BIGINT NULL FK service_categories.id
fee_type ENUM(fixed, percentage)
fixed_amount DECIMAL(12,2) NULL
percentage DECIMAL(5,2) NULL
applies_to_platform_fee BOOLEAN DEFAULT true
applies_to_commission BOOLEAN DEFAULT false
status ENUM(active, inactive)
created_by BIGINT FK users.id
created_at
updated_at
```

Launch should support fixed and percentage fee models only, while keeping the schema flexible for later category and personnel-tier rules, as specified in the scope. 

#### `invoices`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
client_id BIGINT FK clients.id
service_session_id BIGINT NULL FK service_sessions.id
invoice_number VARCHAR(80)
subtotal DECIMAL(12,2)
discount_total DECIMAL(12,2) DEFAULT 0
preferred_personnel_fee_total DECIMAL(12,2) DEFAULT 0
tax_total DECIMAL(12,2) DEFAULT 0
grand_total DECIMAL(12,2)
amount_paid DECIMAL(12,2) DEFAULT 0
balance_due DECIMAL(12,2)
payment_status ENUM(unpaid, partially_paid, paid, pending_validation, rejected, voided, refunded_externally, disputed)
status ENUM(draft, issued, voided)
void_reason TEXT NULL
created_by BIGINT FK users.id
voided_by BIGINT NULL FK users.id
voided_at TIMESTAMP NULL
created_at
updated_at
```

Constraints:

```text
UNIQUE(merchant_id, invoice_number)
INDEX(merchant_id, branch_id, payment_status)
```

#### `invoice_items`

```text
id BIGINT PK
invoice_id BIGINT FK invoices.id
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
item_type ENUM(service, preferred_personnel_fee, discount, adjustment)
description VARCHAR(255)
quantity INTEGER DEFAULT 1
unit_price DECIMAL(12,2)
line_total DECIMAL(12,2)
service_id BIGINT NULL FK services.id
personnel_user_id BIGINT NULL FK merchant_users.id
created_at
updated_at
```

#### `payment_records`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
invoice_id BIGINT FK invoices.id
method ENUM(cash, mpesa, bank_transfer, card_terminal, voucher, split_payment, other)
amount DECIMAL(12,2)
reference VARCHAR(160) NULL
payment_datetime TIMESTAMP
note TEXT NULL
validation_status ENUM(pending_validation, approved, rejected, disputed)
recorded_by BIGINT FK users.id
validated_by BIGINT NULL FK users.id
validated_at TIMESTAMP NULL
rejection_reason TEXT NULL
created_at
updated_at
```

#### `receipts`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
invoice_id BIGINT FK invoices.id
payment_record_id BIGINT FK payment_records.id
receipt_number VARCHAR(80)
paid_amount DECIMAL(12,2)
issued_by BIGINT FK users.id
issued_at TIMESTAMP
pdf_path VARCHAR(500) NULL
created_at
updated_at
```

Constraints:

```text
UNIQUE(merchant_id, receipt_number)
UNIQUE(payment_record_id)
```

#### `platform_fee_ledger`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT NULL FK merchant_branches.id
invoice_id BIGINT NULL FK invoices.id
fee_type ENUM(account_opening, platform_service_fee, contact_download_fee, preferred_personnel_fee_share, adjustment)
basis_amount DECIMAL(12,2)
fee_amount DECIMAL(12,2)
status ENUM(accrued, invoiced, paid, waived, disputed, overdue)
settlement_cycle_start DATE
settlement_cycle_end DATE
created_by BIGINT NULL FK users.id
created_at
updated_at
```

#### `commission_rules`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT NULL FK merchant_branches.id
personnel_user_id BIGINT NULL FK merchant_users.id
service_id BIGINT NULL FK services.id
commission_type ENUM(fixed, percentage)
fixed_amount DECIMAL(12,2) NULL
percentage DECIMAL(5,2) NULL
applies_to_preferred_fee BOOLEAN DEFAULT false
status ENUM(active, inactive)
created_by BIGINT FK users.id
created_at
updated_at
```

#### `commission_ledger`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT FK merchant_branches.id
invoice_id BIGINT FK invoices.id
invoice_item_id BIGINT NULL FK invoice_items.id
personnel_user_id BIGINT FK merchant_users.id
commission_amount DECIMAL(12,2)
status ENUM(pending, earned, reversed, paid)
earned_at TIMESTAMP NULL
reversed_at TIMESTAMP NULL
paid_at TIMESTAMP NULL
created_at
updated_at
```

Commission must be calculated only after invoice payment is confirmed, per Servana’s launch module requirements. 

#### `contact_export_requests`

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT FK merchants.id
branch_id BIGINT NULL FK merchant_branches.id
personnel_user_id BIGINT FK merchant_users.id
requested_by BIGINT FK users.id
status ENUM(requested, pending_payment, approved, rejected, expired, downloaded)
fee_amount DECIMAL(12,2)
approved_by BIGINT NULL FK users.id
approved_at TIMESTAMP NULL
expires_at TIMESTAMP NULL
downloaded_at TIMESTAMP NULL
file_path VARCHAR(500) NULL
created_at
updated_at
```

#### `audit_logs`

```text
id BIGINT PK
public_id ULID UNIQUE
actor_user_id BIGINT NULL FK users.id
actor_merchant_user_id BIGINT NULL FK merchant_users.id
actor_role VARCHAR(100) NULL
merchant_id BIGINT NULL FK merchants.id
branch_id BIGINT NULL FK merchant_branches.id
action VARCHAR(160)
target_entity_type VARCHAR(160)
target_entity_id VARCHAR(80)
old_values JSONB NULL
new_values JSONB NULL
metadata JSONB NULL
ip_address INET NULL
user_agent TEXT NULL
created_at TIMESTAMP
```

Make audit logs append-only. No update/delete routes.

---

## 8. Multi-Tenancy and Data Isolation Model

### 8.1 Tenant Resolution

Tenant context is resolved in this order:

```text
1. Authenticated user session/token.
2. Selected merchant context stored server-side or in signed session state.
3. Merchant membership verification in merchant_users.
4. Branch context verification where branch-specific screens are used.
5. Policy authorization for requested model/action.
```

### 8.2 Middleware Stack

Protected tenant routes:

```php
Route::middleware([
    'auth:sanctum',
    'verified',
    'resolve.active.merchant',
    'ensure.merchant.user.active',
    'ensure.branch.access',
])->group(function () {
    // tenant routes
});
```

### 8.3 Tenant Context Object

Create:

```php
app/Support/Tenant/TenantContext.php
```

Properties:

```php
class TenantContext
{
    public function __construct(
        public readonly User $user,
        public readonly Merchant $merchant,
        public readonly MerchantUser $membership,
        public readonly ?MerchantBranch $branch = null,
    ) {}
}
```

Bind it into Laravel container per request.

### 8.4 Tenant-Aware Queries

Every query touching tenant data must include:

```php
->where('merchant_id', $tenantContext->merchant->id)
```

Branch-scoped queries must also include:

```php
->where('branch_id', $tenantContext->branch->id)
```

### 8.5 Denied Cases to Test

Build tests for:

1. Account A user attempts to view Account B invoice.

   * Expected: `403`.

2. Valid public ID belongs to another merchant.

   * Expected: `404` or `403`, preferably `404` to avoid enumeration.

3. Branch user accesses another branch queue.

   * Expected: `403`.

4. Merchant Personnel exports merchant-wide clients.

   * Expected: `403`.

5. Background job runs without tenant context.

   * Expected: job fails safely and logs security error.

6. Export endpoint requests all clients without merchant filter.

   * Expected: test failure; implementation rejected.

### 8.6 Background Job Tenant Context

All tenant jobs must carry:

```text
merchant_id
branch_id nullable
actor_user_id nullable
target_public_id
```

Never pass raw Eloquent models into long-running jobs. Re-query inside the job with tenant scope.

---

## 9. Authentication Model

### 9.1 Authentication Type

Merchant users log in by Magic Link sent to email after the system verifies the email is active under the correct Merchant Administrator Account. This is explicit in Servana’s scope. 

### 9.2 Magic Link Flow

```text
1. User enters email.
2. User optionally selects merchant if email belongs to multiple merchants.
3. Backend checks:
   - user exists
   - merchant exists
   - merchant status active
   - merchant_user exists
   - merchant_user access_status active
   - role is active
   - user not suspended
   - branch access exists where required
4. Backend generates random token.
5. Backend hashes token and stores only hash.
6. Backend sends email with signed login URL.
7. User clicks link.
8. Backend hashes incoming token.
9. Backend finds unused, unexpired token.
10. Backend marks token used.
11. Backend creates Sanctum-authenticated session.
12. Backend logs login audit event.
13. Frontend loads `/api/v1/me`.
```

### 9.3 Token Rules

```text
Expiry: 10 minutes
Reuse: blocked
Hashing: SHA-256 or Laravel Hash
Rate limit by email + IP
Max requests: 5 per 15 minutes
Max failed token attempts: 10 per hour/IP
```

### 9.4 Super Admin Authentication

Super Admin may use:

```text
email + password + MFA
or Magic Link + MFA
```

Recommendation: password + MFA for platform owner accounts because Super Admin has platform-wide privileges.

### 9.5 Session Rules

```text
Session timeout: configurable, default 8 hours
High-privilege re-authentication: required before fee settings, suspensions, exports, role changes
Secure cookies: production only HTTPS
SameSite: Lax or Strict depending deployment
CSRF: enabled for browser routes
```

---

## 10. Authorization, Roles, and Permissions Model

### 10.1 Role Hierarchy

Platform-level:

```text
Super Administrator
Platform Support Admin
Platform Auditor
```

Merchant-level:

```text
Merchant Owner
Merchant Administrator
Merchant HR
Merchant Finance
Merchant Front Office
Merchant Personnel
Merchant Audit
```

### 10.2 Role Rules

| Role                   | Scope           | Can Mutate? | Notes                                             |
| ---------------------- | --------------- | ----------: | ------------------------------------------------- |
| Super Administrator    | Platform        |         Yes | Cannot perform normal merchant service operations |
| Merchant Owner         | Merchant        |         Yes | Highest merchant role                             |
| Merchant Administrator | Merchant        |         Yes | Manages users, branches, services, rules          |
| Merchant HR            | Merchant/Branch |         Yes | Staff lifecycle only                              |
| Merchant Finance       | Merchant/Branch |         Yes | Payment validation, receipts, financial reports   |
| Merchant Front Office  | Branch          |     Limited | Client/session/invoice/payment capture            |
| Merchant Personnel     | Own records     |     Limited | Own queue, service sessions, commissions          |
| Merchant Audit         | Merchant/Branch |          No | Read-only                                         |

### 10.3 Permission Matrix

Use explicit permissions:

```text
platform.merchants.view
platform.merchants.create
platform.merchants.activate
platform.merchants.suspend
platform.settings.manage
platform.fees.manage
platform.audit.view

merchant.profile.view
merchant.profile.update
merchant.branches.create
merchant.branches.update
merchant.branches.suspend

merchant.users.view
merchant.users.create
merchant.users.activate
merchant.users.suspend
merchant.users.assign_roles

services.view
services.create
services.update
services.archive

clients.view
clients.create
clients.update
clients.export

appointments.view
appointments.create
appointments.reschedule
appointments.cancel

queue.view
queue.create
queue.assign
queue.override_preferred_personnel
queue.cancel

service_sessions.view
service_sessions.create
service_sessions.start
service_sessions.complete
service_sessions.cancel

invoices.view
invoices.create
invoices.void

payments.view
payments.record
payments.validate
payments.reject
payments.dispute

receipts.view
receipts.issue
receipts.download

billing.view
billing.manage

commissions.view
commissions.manage
commissions.mark_paid

contact_exports.request
contact_exports.approve
contact_exports.download

reports.view
reports.export

audit.view
```

### 10.4 Policy Pattern

Example `InvoicePolicy`:

```php
public function view(User $user, Invoice $invoice): bool
{
    $context = app(TenantContext::class);

    return $invoice->merchant_id === $context->merchant->id
        && $this->membershipCan($context->membership, 'invoices.view')
        && $this->branchAllowed($context, $invoice->branch_id);
}
```

### 10.5 Frontend Permission UX

Frontend uses permissions to:

* Hide unavailable buttons.
* Disable unauthorized actions.
* Show “No permission” states.
* Route users to their role dashboards.

Backend remains final authority.

---

## 11. API Design

### 11.1 API Versioning

All API routes use:

```text
/api/v1
```

The Servana source explicitly recommends `/api/v1` route groups for auth, platform, merchants, branches, staff, services, clients, appointments, queue, sessions, invoices, payments, receipts, billing, commissions, exports, reports, audit logs, and notifications. 

### 11.2 Route Groups

```php
Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(...);
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/me', MeController::class);

        Route::prefix('platform')->middleware('platform.admin')->group(...);

        Route::middleware([
            'resolve.active.merchant',
            'ensure.merchant.user.active',
        ])->group(function () {
            Route::apiResource('merchants', MerchantController::class);
            Route::apiResource('branches', BranchController::class);
            Route::apiResource('merchant-users', MerchantUserController::class);
            Route::apiResource('services', ServiceController::class);
            Route::apiResource('clients', ClientController::class);
            Route::apiResource('appointments', AppointmentController::class);
            Route::apiResource('queue', QueueEntryController::class);
            Route::apiResource('service-sessions', ServiceSessionController::class);
            Route::apiResource('invoices', InvoiceController::class);
            Route::apiResource('payments', PaymentRecordController::class);
            Route::apiResource('receipts', ReceiptController::class);
            Route::apiResource('billing', BillingController::class);
            Route::apiResource('commissions', CommissionController::class);
            Route::apiResource('contact-exports', ContactExportController::class);
            Route::apiResource('reports', ReportController::class);
            Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);
            Route::apiResource('notifications', NotificationController::class);
        });
    });
});
```

### 11.3 Response Format

Success:

```json
{
  "data": {},
  "meta": {
    "request_id": "01HX...",
    "timestamp": "2026-06-08T13:00:00+03:00"
  }
}
```

Validation error:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "amount": ["Amount must be greater than zero."]
  },
  "meta": {
    "request_id": "01HX..."
  }
}
```

Authorization error:

```json
{
  "message": "You do not have permission to perform this action.",
  "code": "FORBIDDEN"
}
```

### 11.4 Pagination

All collection endpoints must paginate:

```text
?page=1&per_page=25&sort=-created_at&filter[status]=active
```

Maximum `per_page`: 100.

### 11.5 Critical API Workflows

#### Create Walk-In

```text
POST /api/v1/queue
```

Payload:

```json
{
  "branch_id": "01HX...",
  "client_id": "01HX...",
  "service_id": "01HX...",
  "assignment_type": "preferred_personnel",
  "preferred_personnel_user_id": "01HX..."
}
```

Backend must:

1. Verify client belongs to merchant.
2. Verify service belongs to merchant.
3. Verify service available at branch.
4. Verify personnel belongs to merchant.
5. Verify personnel assigned to branch.
6. Verify personnel eligible for service.
7. Calculate preferred personnel fee if selected.
8. Create queue entry.
9. Audit log event.

#### Create Invoice

```text
POST /api/v1/invoices
```

Backend must:

1. Verify session completed or billable.
2. Pull service price from server.
3. Add preferred personnel fee line if applicable.
4. Apply discount only if user has permission.
5. Calculate totals server-side.
6. Create invoice and invoice items transactionally.
7. Update session status to `invoiced`.
8. Create platform fee ledger entry if applicable.
9. Audit log.

#### Record Offline Payment

```text
POST /api/v1/payments
```

Backend must:

1. Verify invoice belongs to tenant and branch.
2. Validate method, amount, reference, date.
3. Prevent overpayment unless merchant setting allows credit.
4. Set `pending_validation` or `approved` based on role/config.
5. Update invoice payment status.
6. Audit log.
7. Notify Finance if validation required.

#### Issue Receipt

```text
POST /api/v1/receipts
```

Backend must:

1. Verify payment approved or merchant allows immediate receipt.
2. Prevent duplicate receipt for same payment.
3. Generate receipt number.
4. Create receipt.
5. Queue PDF generation.
6. Audit log.

---

## 12. UI/UX Design System

### 12.1 Brand Direction

Servana should feel warm, human, professional, organized, trustworthy, African-rooted, practical, and modern. The uploaded brand identity positions Servana as “service operations made clear, trusted, and manageable for African SMEs,” and the primary tagline is “Serve Better. Run Smarter. Grow Steadily.” 

### 12.2 UI Personality

The app should not feel like a cold admin system. It should feel like a dependable operating partner for African service businesses. 

### 12.3 Design Tokens

Use CSS variables:

```css
:root {
  --color-primary: #C86B2A;
  --color-primary-hover: #A95722;
  --color-accent: #D9A441;
  --color-success: #2F855A;
  --color-warning: #D69E2E;
  --color-danger: #C53030;
  --color-info: #2B6CB0;

  --color-bg: #FFFDF7;
  --color-surface: #FFFFFF;
  --color-surface-muted: #F7F4ED;
  --color-border: #E2DED4;
  --color-text: #1F2933;
  --color-text-muted: #667085;

  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 16px;

  --shadow-card: 0 8px 24px rgba(15, 23, 42, 0.08);
}
```

### 12.4 Core Components

Implement:

```text
Button
IconButton
Input
Textarea
Select
DatePicker
TimePicker
MoneyInput
PhoneInput
Checkbox
Radio
Switch
Badge
StatusPill
Card
MetricCard
Table
MobileCardList
Modal
Drawer
Dropdown
Toast
Alert
Tabs
Breadcrumbs
Pagination
EmptyState
SkeletonLoader
PermissionGate
ConfirmDialog
```

### 12.5 Dashboard Layouts

Each role gets a focused dashboard:

* **Super Admin:** merchants, fees, suspicious activity, platform revenue, overdue merchants.
* **Merchant Admin:** sales, branch performance, staff performance, platform fees, commissions.
* **Finance:** pending validations, paid/unpaid invoices, disputes, receipts, balances.
* **Front Office:** today’s appointments, walk-ins, active queue, unpaid invoices.
* **Personnel:** assigned clients, own queue, own commissions, preferred requests.
* **Audit:** immutable activity feed, filters, suspicious flags.

---

## 13. Responsive Layout Strategy

Required breakpoints:

```text
Desktop: >= 1025px
Tablet: 768px–1024px
Mobile: <= 767px
```

Implementation rules:

1. Use CSS media queries only.
2. No JavaScript device detection.
3. No horizontal scrolling for normal content.
4. Tables collapse into mobile cards.
5. Queue screens must work well on tablets.
6. Front Office flows must work on mobile.
7. Touch targets minimum 44px.

### Desktop

```text
Fixed sidebar + top header + content area.
Data tables visible.
Dashboard cards in 3–4 columns.
Queue board in multi-column layout.
```

### Tablet

```text
Collapsible sidebar.
Dashboard cards in 2 columns.
Queue board scrolls vertically by lane.
Tables use condensed columns.
```

### Mobile

```text
Bottom navigation or drawer navigation.
Single-column forms.
Tables become cards.
Invoice creation becomes stepper flow.
Queue board becomes grouped list.
```

---

## 14. Dark Mode Strategy

### 14.1 Requirements

* Light mode default.
* Dark mode toggle in user profile/settings.
* Preference stored per user.
* No hidden borders, validation errors, focus states, or low-contrast text.

### 14.2 Implementation

Frontend:

```ts
themeStore.setTheme('light' | 'dark' | 'system')
```

Backend:

```text
users.theme_preference
```

CSS:

```css
[data-theme="dark"] {
  --color-bg: #111827;
  --color-surface: #1F2937;
  --color-border: #374151;
  --color-text: #F9FAFB;
  --color-text-muted: #D1D5DB;
}
```

### 14.3 Flash Prevention

Inline a small script in the base layout before app mount:

```html
<script>
  const theme = localStorage.getItem('theme') || 'light';
  document.documentElement.dataset.theme = theme;
</script>
```

Then sync with backend after authentication.

---

## 15. Accessibility Strategy

Minimum practical WCAG-aligned requirements:

1. All inputs have visible labels.
2. Placeholder text never replaces labels.
3. Error messages are programmatically associated with fields.
4. Buttons have accessible names.
5. Modals trap focus.
6. Dropdowns close on Escape.
7. Keyboard navigation works across all menus.
8. Focus states are visible in both light and dark mode.
9. Color is not the only status indicator.
10. Touch targets are at least 44px.
11. Reduced motion is respected.
12. Browser zoom must not break layouts.

Verification:

```text
- Keyboard-only walkthrough.
- Screen reader smoke test.
- Contrast check.
- Browser zoom 200%.
- Mobile viewport test.
- Reduced motion test.
```

---

## 16. Forms and Input Behavior Strategy

### 16.1 Form Rules

Every form must include:

```text
label
helper text where useful
required marker
validation error area
loading/submitting state
success state
server error state
duplicate-submit protection
```

### 16.2 Server Validation Mapping

Backend returns:

```json
{
  "errors": {
    "phone": ["This phone number already exists for this merchant."]
  }
}
```

Frontend maps it directly to the field.

### 16.3 Sensitive Workflows

Require confirmation modals for:

```text
merchant suspension
branch suspension
user suspension
role changes
invoice voiding
payment rejection
receipt issuance
preferred personnel override
contact export approval
commission reversal
platform fee waiver
```

### 16.4 Money Inputs

Use integer cents internally where possible or decimal with strict precision.

For PostgreSQL:

```text
DECIMAL(12,2)
```

Frontend must:

* Display KES formatting.
* Submit numeric value.
* Prevent negative values unless adjustment workflow allows it.

---

## 17. User Profile and Account UI Strategy

### 17.1 Profile Unit

The top-right profile area must show:

```text
profile photo
user name
current merchant
current branch where applicable
role badge
dropdown trigger
```

### 17.2 Profile Dropdown

Dropdown options:

```text
My Profile
Switch Merchant
Switch Branch
Notification Preferences
Theme: Light/Dark
Security
Logout
```

### 17.3 Account Switcher

User can switch only to merchants where:

```text
merchant_users.user_id = authenticated_user.id
merchant_users.access_status = active
merchant.status = active
role.is_active = true
```

Branch switcher shows only assigned branches.

---

## 18. Billing and Plan Enforcement Strategy

### 18.1 Billing Scope

The Citrus Billing Engine calculates and tracks what merchants owe Citrus Labs Limited, including account-opening fees, platform service fees, preferred-personnel fee treatment, contact-download fee rules, settlement cycles, platform fee ledger, merchant balance, overdue balances, suspension triggers, exemptions, and billing audit logs. 

### 18.2 Fee Types

```text
account_opening_fee
platform_service_fee
preferred_personnel_fee_share
contact_download_fee
adjustment
waiver
```

### 18.3 Fee Calculation Triggers

| Trigger                            | Ledger Entry                      |
| ---------------------------------- | --------------------------------- |
| Merchant activation                | Account-opening fee if applicable |
| Invoice paid                       | Platform service fee              |
| Preferred personnel fee charged    | Platform fee if configured        |
| Contact export approved/downloaded | Contact-download fee              |
| Manual waiver                      | Waiver ledger entry               |
| Overdue cycle                      | Overdue status update             |

### 18.4 Plan Enforcement

Create tables:

```text
plans
plan_features
merchant_subscriptions
feature_usage
```

Feature examples:

```text
max_branches
max_users
max_monthly_invoices
contact_exports_enabled
advanced_reports_enabled
audit_export_enabled
```

MVP can launch with one default plan, but the schema must support plan enforcement later.

---

## 19. File Upload and Storage Strategy

### 19.1 Upload Types

Allowed files:

```text
merchant documents: PDF, JPG, PNG
profile photos: JPG, PNG, WEBP
receipts/invoice PDFs: generated by system
exports: CSV/XLSX generated by system
```

### 19.2 Storage Rules

* Store private files outside public web root.
* Use S3-compatible storage.
* Store metadata in `uploaded_files`.
* Downloads use signed temporary URLs.
* Every file row includes `merchant_id`.
* Branch files include `branch_id` where relevant.
* Contact exports expire.

### 19.3 `uploaded_files` Table

```text
id BIGINT PK
public_id ULID UNIQUE
merchant_id BIGINT NULL
branch_id BIGINT NULL
uploaded_by BIGINT FK users.id
disk VARCHAR(80)
path VARCHAR(500)
original_name VARCHAR(255)
mime_type VARCHAR(120)
extension VARCHAR(20)
size_bytes BIGINT
visibility ENUM(private, public)
purpose ENUM(merchant_document, profile_photo, invoice_pdf, receipt_pdf, contact_export, report_export)
checksum VARCHAR(128) NULL
expires_at TIMESTAMP NULL
created_at
updated_at
deleted_at NULL
```

### 19.4 File Security Tests

Test:

```text
wrong MIME rejected
oversized file rejected
private file direct URL blocked
cross-tenant file download blocked
expired export blocked
malicious extension rejected
```

---

## 20. Queue, Jobs, Notifications, and Scheduled Task Strategy

### 20.1 Queue Driver

Use Redis queues and Laravel Horizon.

Queues:

```text
default
emails
reports
exports
billing
receipts
audit
notifications
```

### 20.2 Jobs

```text
SendMagicLoginLinkJob
SendStaffActivationEmailJob
SendAppointmentConfirmationJob
SendAppointmentCancellationJob
SendQueueUpdateNotificationJob
SendPreferredPersonnelConfirmationJob
SendPaymentValidationNoticeJob
GenerateReceiptPdfJob
GenerateInvoicePdfJob
GenerateContactExportJob
GenerateMerchantReportJob
CalculatePlatformFeesJob
CalculateCommissionLedgerJob
SendOverdueMerchantWarningJob
```

### 20.3 Notifications

Required notification types include Magic Link login email, staff activation email, appointment confirmation/cancellation, queue update, preferred-personnel wait confirmation, payment validation notice, receipt availability, merchant suspension warning, and platform fee overdue warning. 

Launch channels:

```text
Email: required
SMS/WhatsApp: phase 2
```

### 20.4 Scheduled Tasks

Laravel Scheduler:

```text
every 5 minutes: expire unused magic login tokens
hourly: expire contact export links
daily: calculate overdue platform fee balances
daily: send merchant suspension warnings
daily: backup verification check
weekly: merchant summary reports
monthly: settlement cycle closure
```

---

## 21. Search Strategy

### 21.1 MVP Search Engine

Use PostgreSQL indexed search for MVP where possible. Add Meilisearch for higher-scale search.

Searchable entities:

```text
merchants
branches
staff
clients
services
appointments
queue_entries
invoices
payment_records
receipts
audit_logs
```

### 21.2 Search Rules

* Search results must be tenant-scoped.
* Branch users see only assigned branch data.
* Personnel search is limited to personally served clients.
* Audit search is read-only.
* Platform search is Super Admin only.

### 21.3 Indexing

Indexes:

```text
clients: merchant_id + phone
clients: merchant_id + full_name
invoices: merchant_id + invoice_number
receipts: merchant_id + receipt_number
payment_records: merchant_id + reference
queue_entries: merchant_id + branch_id + status
appointments: merchant_id + branch_id + scheduled_start_at
audit_logs: merchant_id + action + created_at
```

---

## 22. Observability and Audit Logging Strategy

### 22.1 Observability

Implement:

```text
request IDs
structured JSON logs
centralized logs
error tracking
queue failure monitoring
health checks
slow query monitoring
API latency tracking
failed login tracking
export tracking
receipt generation tracking
```

### 22.2 Health Checks

Endpoints:

```text
GET /health
GET /health/database
GET /health/redis
GET /health/storage
GET /health/queue
```

### 22.3 Audit Events

Audit logs must capture merchant creation, activation, suspension, branch creation, user creation, role changes, Magic Link login, service changes, client changes, queue changes, preferred personnel selection/override, invoice creation, payment recording/validation, receipt generation, commission changes, contact export request/download, and platform fee setting changes. 

Audit log payload:

```json
{
  "actor_user_id": 1,
  "actor_role": "merchant_finance",
  "merchant_id": 7,
  "branch_id": 3,
  "action": "payment.approved",
  "target_entity_type": "payment_record",
  "target_entity_id": "01HX...",
  "old_values": {},
  "new_values": {},
  "ip_address": "x.x.x.x",
  "user_agent": "..."
}
```

---

## 23. Performance and Scalability Plan

### 23.1 Expected Bottlenecks

| Bottleneck                          | Likelihood | Mitigation                                |
| ----------------------------------- | ---------: | ----------------------------------------- |
| Large invoice/payment tables        |        70% | indexes, pagination, date filters         |
| Audit logs growing fast             |        80% | partitioning later, retention policy      |
| Queue board refresh load            |        50% | polling interval, cache, later WebSockets |
| Report generation slow              |        65% | queued reports, cached aggregates         |
| Contact exports abused              |        35% | approval, fees, throttling, signed URLs   |
| Search slow across clients/invoices |        55% | indexes, Meilisearch                      |

### 23.2 Required Controls

```text
pagination everywhere
database indexes
lazy frontend modules
queued report generation
queued PDF generation
cache dashboard metrics
cache invalidation after writes
rate limits for login and exports
CDN for static assets
object storage for PDFs/exports
```

### 23.3 Dashboard Caching

Cache keys:

```text
merchant:{merchant_id}:dashboard:{date}
branch:{branch_id}:dashboard:{date}
finance:{merchant_id}:pending-validations
personnel:{merchant_user_id}:today
```

Invalidate after:

```text
invoice created
payment approved/rejected
receipt issued
service session completed
queue status changed
commission created/reversed
```

---

## 24. Security Threat Model

| Threat                             | Risk     | Mitigation                                       | Test                                      |
| ---------------------------------- | -------- | ------------------------------------------------ | ----------------------------------------- |
| Cross-tenant data leakage          | Critical | tenant scopes, policies, UUID/ULID, tests        | Account A cannot access Account B invoice |
| Broken branch isolation            | High     | branch middleware, branch assignment table       | Branch user denied other branch queue     |
| Magic Link theft/reuse             | High     | short expiry, hashed token, one-time use         | reused token rejected                     |
| Front Office payment manipulation  | High     | Finance validation, receipt permission, audit    | receipt blocked before approval           |
| Personnel exports full client list | High     | personally served filter, export approval, audit | personnel export excludes other clients   |
| ID enumeration                     | High     | public ULIDs, 404 on foreign IDs                 | sequential ID inaccessible                |
| SQL injection                      | High     | Eloquent bindings, validation                    | malicious filter rejected                 |
| XSS                                | High     | escape content, sanitize notes where needed      | script content rendered safely            |
| CSRF                               | Medium   | Sanctum CSRF protection                          | forged request rejected                   |
| File upload abuse                  | High     | MIME validation, private storage, size limits    | malicious file rejected                   |
| Role escalation                    | Critical | policies, audited role changes                   | user cannot self-upgrade                  |
| Sensitive log leakage              | High     | redaction middleware                             | token not present in logs                 |

The uploaded risk assessment already identifies cross-tenant data leakage as critical and reducible to under 2% with strong controls, while Front Office payment manipulation and staff misuse of contact exports remain high-risk without proper controls. 

---

## 25. Testing Strategy

### 25.1 Backend Tests

Required:

```text
tests/Feature/Auth/MagicLinkTest.php
tests/Feature/TenantIsolation/InvoiceIsolationTest.php
tests/Feature/TenantIsolation/ClientIsolationTest.php
tests/Feature/BranchIsolation/QueueBranchIsolationTest.php
tests/Feature/Authorization/RolePermissionTest.php
tests/Feature/Payments/OfflinePaymentValidationTest.php
tests/Feature/Receipts/ReceiptGenerationTest.php
tests/Feature/Invoices/InvoiceCalculationTest.php
tests/Feature/Commissions/CommissionLedgerTest.php
tests/Feature/Billing/PlatformFeeLedgerTest.php
tests/Feature/ContactExports/PersonnelContactExportTest.php
tests/Feature/Audit/AuditLogTest.php
```

The uploaded scope requires automated coverage for authentication, merchant activation, authorization, tenant isolation, branch isolation, staff activation, queue, preferred personnel fee, invoices, payments, receipts, commissions, contact exports, audit logs, API validation, and frontend workflows. 

### 25.2 Example Tenant Isolation Test

```php
it('prevents a merchant user from viewing another merchant invoice', function () {
    $merchantA = Merchant::factory()->create();
    $merchantB = Merchant::factory()->create();

    $userA = User::factory()->create();
    MerchantUser::factory()->for($merchantA)->for($userA)->active()->create();

    $invoiceB = Invoice::factory()->for($merchantB)->create();

    actingAs($userA)
        ->getJson("/api/v1/invoices/{$invoiceB->public_id}")
        ->assertForbidden();
});
```

### 25.3 Frontend Tests

Use Vitest + Vue Testing Library for components:

```text
PermissionGate
InvoiceForm
PaymentRecordForm
ReceiptIssueButton
QueueBoard
ClientSearch
MerchantSwitcher
BranchSwitcher
ProfileDropdown
```

Use Playwright/Cypress for:

```text
Magic Link login
Merchant onboarding
Branch creation
Staff activation
Create walk-in
Preferred personnel selection
Invoice creation
Payment validation
Receipt generation
Personnel contact export denial
Audit read-only workflow
```

---

## 26. Deployment and CI/CD Strategy

### 26.1 Environments

```text
local
testing
staging
production
```

### 26.2 Docker Services

```text
app
nginx
postgres
redis
queue-worker
scheduler
meilisearch
mailpit for local
```

### 26.3 CI Pipeline

On pull request:

```text
composer install
npm ci
php artisan test
npm run typecheck
npm run test
npm run build
phpstan/larastan
pint
eslint
dependency vulnerability scan
```

On production deploy:

```text
backup database
pull image
run migrations
cache config/routes/views
restart queue workers
run smoke tests
verify health checks
monitor logs
rollback on failure
```

### 26.4 Production Requirements

The source requires Dockerized deployment, CI/CD, automated tests before deployment, HTTPS, queue workers, Laravel Scheduler, Redis, backups, rollback procedure, centralized logging, monitoring, health checks, vulnerability scanning, secure object storage, production mail provider, and staging environment. 

---

## 27. Step-by-Step Development Roadmap

## Phase 1 — Repository and Foundation

Build:

```text
Laravel app
Vue + TypeScript + Vite
Tailwind CSS
PostgreSQL
Redis
Docker Compose
Sanctum
Base API structure
Testing framework
CI workflow
```

Why:

* Required for all later implementation.

Failure if omitted:

* The agent will build features on unstable foundations.

Verification:

```text
php artisan test passes
npm run build passes
Docker stack boots
/health returns OK
CI passes
```

---

## Phase 2 — Core Identity, Tenant, and Access Model

Build:

```text
users
merchants
merchant_branches
merchant_users
roles
permissions
role_permission
merchant_user_branches
TenantContext
tenant resolver middleware
branch access middleware
base policies
```

Why:

* Servana is multi-tenant and branch-scoped.

Failure if omitted:

* Cross-tenant and cross-branch leakage becomes likely.

Verification:

```text
tenant isolation tests
branch isolation tests
role permission tests
403 examples
database rows confirm merchant_id and branch_id
```

---

## Phase 3 — Magic Link Authentication

Build:

```text
magic_login_tokens
request Magic Link endpoint
consume Magic Link endpoint
email notification
token hashing
expiry
reuse prevention
rate limiting
login audit log
```

Why:

* Merchant users must log in through Magic Link only after activation.

Failure if omitted:

* Inactive or unauthorized users could access merchant data.

Verification:

```text
valid token logs in
expired token rejected
used token rejected
inactive user blocked
inactive merchant blocked
wrong merchant membership blocked
```

---

## Phase 4 — Merchant and Branch Management

Build:

```text
merchant onboarding
merchant activation/suspension/deactivation
branch CRUD
operating hours
branch status
branch user assignment
audit logs
```

Why:

* Merchant and branch setup are launch-critical.

Failure if omitted:

* No tenant can operate safely.

Verification:

```text
merchant created
branch created
suspended merchant blocked
branch-scoped user sees only assigned branch
audit logs created
```

---

## Phase 5 — Staff, Roles, and Personnel Eligibility

Build:

```text
staff creation
activation
suspension
deactivation
role assignment
branch assignment
personnel profiles
service eligibility
employment status
permission history
```

Why:

* Servana depends on controlled staff and personnel operations.

Failure if omitted:

* Users may act without correct authority.

Verification:

```text
HR can create staff
Front Office cannot assign roles
Personnel cannot view HR screens
role changes audited
```

---

## Phase 6 — Service Catalogue

Build:

```text
service categories
services
branch service availability
service pricing
duration
discount eligibility
preferred personnel fee eligibility
personnel-service eligibility
```

Why:

* Queue, appointments, sessions, invoices, and commissions depend on services.

Failure if omitted:

* The system cannot price or assign work accurately.

Verification:

```text
service created
service available only in selected branch
ineligible personnel cannot be assigned
archived service cannot be selected
```

---

## Phase 7 — Client Records

Build:

```text
client creation
client search
client profile
contact details
visit history
service history
consent records
communication preferences
notes
```

Why:

* Front Office and Personnel workflows depend on client records.

Failure if omitted:

* No reliable client history or audit trail.

Verification:

```text
client unique per merchant phone
same phone allowed across different merchants
Personnel cannot export merchant-wide clients
```

---

## Phase 8 — Appointments, Walk-Ins, and Queue

Build:

```text
appointments
walk-ins
queue entries
branch queue board
personnel queue
next available assignment
preferred personnel assignment
estimated wait time
queue status transitions
override reason
queue audit logs
```

Why:

* This is the operational heart of Servana.

Failure if omitted:

* Servana becomes only a database, not an operations system.

Verification:

```text
walk-in created
appointment rescheduled
preferred personnel fee displayed
override requires permission and reason
queue status transitions audited
```

---

## Phase 9 — Service Sessions

Build:

```text
service session creation
session start/end
status transitions
cancellation
notes
invoice trigger
audit trail
```

Why:

* Sessions connect queue/appointments to invoices and commissions.

Failure if omitted:

* Invoices and commissions lose operational proof.

Verification:

```text
session starts from queue entry
completed session becomes billable
cancelled session requires reason
session cannot cross tenant/branch
```

---

## Phase 10 — Invoices and Invoice Items

Build:

```text
invoice numbering
invoice totals
invoice items
service price line
preferred personnel fee line
discount line
void workflow
payment status
PDF generation job
audit logs
```

Why:

* Invoice records are central to revenue, platform fees, commissions, receipts, and auditability.

Failure if omitted:

* Financial workflows become unreliable.

Verification:

```text
invoice totals correct
preferred fee appears as separate line
void requires permission and reason
invoice cannot be edited after payment except controlled adjustment
```

---

## Phase 11 — Offline Payments and Finance Validation

Build:

```text
payment records
payment methods
partial payment
split payment
reference capture
pending validation
approve/reject/dispute
invoice payment status updates
Finance dashboard
audit logs
```

Why:

* Payments are offline but must be recorded and validated.

Failure if omitted:

* Front Office can manipulate revenue truth.

Verification:

```text
Front Office records payment
Finance approves payment
rejected payment does not create receipt
partial payment updates balance
overpayment blocked or handled by rule
```

---

## Phase 12 — Receipts

Build:

```text
receipt numbering
receipt issuance rules
receipt PDF
download signed URL
receipt audit log
duplicate receipt prevention
```

Why:

* Receipts prove validated payment.

Failure if omitted:

* Merchants lose trustworthy client payment records.

Verification:

```text
receipt blocked before validation
receipt generated after approval
duplicate receipt rejected
receipt PDF downloadable through signed URL only
```

---

## Phase 13 — Citrus Billing Engine

Build:

```text
platform fee settings
account-opening fee tracking
service fee calculation
preferred personnel fee treatment
contact download fee rules
platform fee ledger
merchant balance
overdue tracking
suspension triggers
waivers
billing audit logs
```

Why:

* Citrus Labs needs traceable platform revenue.

Failure if omitted:

* Servana cannot monetize reliably.

Verification:

```text
invoice payment creates platform fee
waiver audited
overdue merchant appears on Super Admin dashboard
suspension trigger tested
```

---

## Phase 14 — Commission Tracking

Build:

```text
commission rules
commission ledger
commission calculation after confirmed payment
pending/earned/paid statuses
reversal on voided invoice
preferred fee commission setting
```

Why:

* Personnel visibility and merchant accountability depend on commissions.

Failure if omitted:

* Staff payment disputes increase.

Verification:

```text
unpaid invoice creates no earned commission
paid invoice creates earned commission
voided invoice reverses commission
```

---

## Phase 15 — Contact Export Controls

Build:

```text
personnel export request
personally served client filter
contact download fee
approval/payment requirement
export generation job
signed expiring download
audit logs
```

Why:

* Personnel access to client contacts is sensitive and high-risk.

Failure if omitted:

* Staff can misuse merchant client data.

Verification:

```text
personnel sees only personally served clients
export excludes payment details and internal notes
download expires
all export activity audited
```

---

## Phase 16 — Dashboards and Reports

Build:

```text
Super Admin dashboard
Merchant Admin dashboard
Finance dashboard
Front Office dashboard
Personnel dashboard
Audit dashboard
report filters
queued exports
CSV/PDF reports
```

Why:

* Stakeholders need role-specific visibility.

Failure if omitted:

* Platform value becomes harder to prove.

Verification:

```text
dashboard metrics match database records
branch filters work
reports are tenant-scoped
large report runs in queue
```

---

## Phase 17 — Notifications

Build:

```text
Magic Link emails
staff activation emails
appointment confirmation/cancellation
queue update
preferred personnel confirmation
payment validation notice
receipt availability
suspension warning
overdue warning
notification logs
```

Why:

* Operational workflows need timely communication.

Failure if omitted:

* Users miss status changes and validation tasks.

Verification:

```text
notifications dispatched
logs stored
failed jobs retried
inactive merchant notifications blocked where appropriate
```

---

## Phase 18 — Audit, Security Hardening, Observability

Build:

```text
immutable audit logs
security event logs
request IDs
health checks
centralized logs
error tracking
slow query logs
backup verification
security regression tests
```

Why:

* Production readiness requires proof, traceability, and recovery.

Failure if omitted:

* Incidents become hard to diagnose or prove.

Verification:

```text
audit logs immutable
sensitive data redacted
health checks pass
queue failures visible
backup restore tested
```

---

## Phase 19 — Production Deployment

Build:

```text
Docker production image
Nginx config
queue workers
scheduler
CI/CD deployment
staging environment
database backups
rollback procedure
monitoring
production mail
object storage
```

Why:

* A SaaS app must be repeatably deployable.

Failure if omitted:

* Manual deployment becomes fragile and risky.

Verification:

```text
staging deploy succeeds
production smoke tests pass
rollback tested
health checks pass
monitoring alerts configured
```

---

## 28. IDE Agent Execution Instructions

The IDE coding agent must follow this execution protocol for every task.

### 28.1 Before Implementation

For each task, document:

```text
Requirement:
Source:
Files to inspect:
Existing behavior:
Expected behavior:
Security concern:
Tenant concern:
Branch concern:
Tests to add:
```

### 28.2 During Implementation

Rules:

1. Inspect existing code before changing behavior.
2. Add or update migrations first for data changes.
3. Add models, enums, factories, seeders.
4. Add Form Requests.
5. Add Policies.
6. Add Actions/Services.
7. Add Controllers.
8. Add API Resources.
9. Add routes.
10. Add tests.
11. Add frontend only after backend contract is stable.
12. Run tests before moving to next task.

### 28.3 Bug Fix Protocol

For any defect:

```text
1. Reproduce the bug.
2. Identify root cause.
3. Identify affected files/functions/routes/database rows.
4. Write failing test.
5. Implement precise fix.
6. Run targeted tests.
7. Run related regression tests.
8. Document verification.
```

### 28.4 Completion Proof Required

Each phase must show:

```text
test command run
test result
API sample
database proof where relevant
authorization denial proof
tenant isolation proof
edge-case proof
remaining risks
```

---

## 29. Acceptance Criteria

Servana is acceptable for production launch only when:

1. Magic Link login works securely.
2. Inactive users cannot log in.
3. Suspended merchants cannot operate.
4. Tenant isolation tests pass.
5. Branch isolation tests pass.
6. Backend authorization is enforced for all protected actions.
7. Frontend permission checks are not treated as security.
8. Merchant onboarding works.
9. Branch management works.
10. Staff activation and suspension work.
11. Service catalogue works.
12. Client records work.
13. Walk-ins work.
14. Appointments work.
15. Queue management works.
16. Preferred personnel fee is calculated and shown before confirmation.
17. Service sessions work.
18. Invoices generate correct totals.
19. Offline payment recording works.
20. Finance validation works.
21. Receipts are blocked until payment is valid.
22. Commission ledger works after payment confirmation.
23. Platform fee ledger works.
24. Contact exports are restricted and audited.
25. Dashboards show accurate tenant-scoped metrics.
26. Audit logs capture sensitive actions.
27. Notifications are logged and queued.
28. File downloads use signed URLs.
29. CI/CD runs tests before deployment.
30. Dockerized staging deployment works.
31. Production monitoring, backups, and rollback are configured.

---

## 30. Risk Register with Mitigation Steps

| Risk                                    |                                            Likelihood |   Impact | Mitigation                                                                                        |
| --------------------------------------- | ----------------------------------------------------: | -------: | ------------------------------------------------------------------------------------------------- |
| Merchants under-record invoices         |                                               60%–75% |     High | Make invoices required for commissions, receipts, queue history, and owner reports.               |
| Staff misuse contact exports            |       35%–50% without controls; 15%–25% with controls |     High | Restrict to personally served clients, require payment/approval, audit every export.              |
| Cross-tenant data leakage               | 8%–15% if poorly built; under 2% with strong controls | Critical | Tenant scopes, policies, UUID/ULID public IDs, isolation tests, code review.                      |
| Front Office manipulates payment status |                                               30%–45% |     High | Finance validation, immutable logs, restricted receipt permissions.                               |
| Preferred personnel disputes            |                                               25%–40% |   Medium | Show wait time, show fee, require confirmation, audit overrides.                                  |
| Merchant user login abuse               |                                               25%–40% |   Medium | Magic Link expiry, active-email verification, rate limits, session logs.                          |
| Product complexity overwhelms SMEs      |                                               35%–50% |     High | Keep role dashboards workflow-driven and hide advanced features by permission.                    |
| Launch delay from overbuilding          |                                               40%–60% |     High | Prioritize operational core before client portal, loyalty, inventory, AI, and advanced analytics. |
| Weak audit log discipline               |                                                   35% |     High | Centralized `AuditLogger` service and tests for sensitive workflows.                              |
| Commission disputes                     |                                               30%–45% |   Medium | Calculate only after payment confirmation; expose commission ledger to personnel/admin.           |
| Platform fee disputes                   |                                               25%–40% |     High | Transparent platform fee ledger, billing cycles, waivers, and audit logs.                         |
| Slow reports                            |                                               45%–65% |   Medium | Queue reports and cache dashboard aggregates.                                                     |
| Poor mobile usability for Front Office  |                                               40%–55% |     High | Mobile-first workflow tests for client registration, queue, invoice, and payment recording.       |

---

## 31. Final Verification Checklist

### Architecture

* [ ] Laravel backend implemented.
* [ ] Vue + TypeScript frontend implemented.
* [ ] PostgreSQL schema migrated.
* [ ] Redis cache and queues configured.
* [ ] S3-compatible storage configured.
* [ ] Docker stack works.
* [ ] CI/CD pipeline works.

### Security

* [ ] Magic Links hashed.
* [ ] Magic Links expire.
* [ ] Magic Links are one-time-use.
* [ ] Login rate limiting implemented.
* [ ] Tenant middleware implemented.
* [ ] Branch middleware implemented.
* [ ] Policies implemented.
* [ ] No frontend-only authorization.
* [ ] No sequential IDs exposed.
* [ ] Sensitive logs redacted.
* [ ] Private files use signed URLs.

### Product Core

* [ ] Merchant onboarding.
* [ ] Merchant activation/suspension.
* [ ] Branch management.
* [ ] Staff management.
* [ ] Role and permission assignment.
* [ ] Service catalogue.
* [ ] Personnel eligibility.
* [ ] Client records.
* [ ] Walk-ins.
* [ ] Appointments.
* [ ] Queue board.
* [ ] Preferred personnel selection.
* [ ] Service sessions.
* [ ] Invoices.
* [ ] Offline payment recording.
* [ ] Finance validation.
* [ ] Receipts.
* [ ] Platform fee ledger.
* [ ] Commission ledger.
* [ ] Contact exports.
* [ ] Reports.
* [ ] Dashboards.
* [ ] Notifications.
* [ ] Audit logs.

### Testing

* [ ] Unit tests pass.
* [ ] Feature tests pass.
* [ ] API tests pass.
* [ ] Authorization tests pass.
* [ ] Tenant isolation tests pass.
* [ ] Branch isolation tests pass.
* [ ] Validation tests pass.
* [ ] Frontend component tests pass.
* [ ] Browser/E2E tests pass.
* [ ] Security regression tests pass.

### Production

* [ ] Staging environment deployed.
* [ ] HTTPS configured.
* [ ] Queue workers running.
* [ ] Scheduler running.
* [ ] Backups configured.
* [ ] Restore tested.
* [ ] Monitoring configured.
* [ ] Error tracking configured.
* [ ] Health checks pass.
* [ ] Rollback procedure tested.
* [ ] Production smoke tests pass.
