# Servana by Citrus — Production SaaS Development Plan

**Product:** Servana by Citrus (service-operations SaaS for African service-based SMEs)
**Operator:** Citrus Labs Limited
**Document type:** Implementation-ready software development plan for an IDE-based AI coding agent
**Source of truth:** `SERVANA_COMBINED.txt` (Project Scope, Brand Identity, Product Technical Details v.2)
**Plan version:** 1.0 — 2026-06-12

> **How to use this document:** Execute the phases in §27 in order. Every phase references the design sections (§1–§26) it implements. Follow the IDE Agent Execution Rules in §28 for every change. Do not skip verification steps. Do not mark a phase complete until its acceptance criteria pass.

---

## 1. Executive Architecture Summary

Servana is a **single-application, single-database, row-level multi-tenant SaaS** built on Laravel 11 (PHP 8.3) with a Vue 3 + TypeScript SPA, PostgreSQL 16, Redis 7, Meilisearch, S3-compatible object storage, and Dockerized deployment with CI/CD.

**Core architectural decisions (each is justified, evidenced against the scope, and binding):**

| # | Decision | Rationale (evidence in scope) | Failure if omitted |
|---|----------|-------------------------------|--------------------|
| A1 | Row-level multi-tenancy: every tenant-owned table carries `merchant_id`; branch-owned tables also carry `branch_id`. Enforced via Eloquent global scopes + policies + DB constraints. | Scope §2.1, §7: "A merchant's data must never be accessible… by another merchant"; required tenant-scoped columns listed. Single-DB row tenancy fits thousands of SME tenants with low per-tenant data volume far better than DB-per-tenant (operational cost, migrations, backups). | Cross-tenant leakage (scope risk register: Critical, 8–15% likelihood if poorly built). |
| A2 | **Branch is both an entity and an access scope** (`merchant_branches` + `branch_user_assignments`). | Scope §3.3 Implementation Note mandates exactly this. | Cross-branch leakage (Critical, 15–25%). |
| A3 | **Magic Link is the only login method for all users**, layered on Laravel Sanctum SPA cookie sessions. No passwords are stored for any user. | Scope §2.3, §5.2 Universal Login Rule: "All users log in… via Magic Link." This is the documented exception to the generic "email/password" default in Product Technical Details §4.1 ("Specific login method depends on the project scope"). | Violates the product's access model; password handling adds attack surface the product explicitly avoids. |
| A4 | Authorization = **role × permission × merchant scope × branch scope**, enforced server-side via Laravel Policies + a permission registry (custom tables per scope §7, modeled after Spatie semantics). | Scope §5.2, §8 API rules, §11 security. | Broken access control / IDOR. |
| A5 | **ULIDs as public identifiers** on every externally exposed resource; internal `bigint` PKs never leave the API. | Scope §7 required columns (`uuid_or_ulid`), §8 "Use UUIDs or ULIDs externally." | Cross-account enumeration. |
| A6 | Financial integrity is **database-enforced**: unique invoice/receipt numbers, sequence tables with row locks, append-only hash-chained audit logs, state machines for queue/session/payment/receipt transitions, period locks. | Scope §3.3 numbering rules, §3.8 append-only audit, §5.10–5.12, risk register. | Finance/audit confusion (45–60%), invoice tampering (50–70%), cash leakage (65–85%). |
| A7 | All slow work (PDF reports, emails, exports, billing generation, search indexing, day-close reports) runs on **Redis-backed queues** with tenant context serialized into every job. | Scope §6 stack, Product Technical Details §17. | Request latency, lost tenant context in jobs. |
| A8 | **Vue 3 + TypeScript + Pinia + Vue Router + Tailwind CSS**, role-based layouts per scope §9 frontend structure. | Scope §6 stack table; TypeScript preferred; component architecture mandated. | Unmaintainable frontend; violates non-negotiables. |
| A9 | Citrus Billing Engine is a **ledger-first domain module**: every validated service invoice accrues a platform-fee ledger entry; a scheduled cycle job rolls entries into a Citrus platform-fee invoice per merchant. Service-fee tier affects merchant-client invoice pricing only, never the platform-fee liability. | Scope §3.2 tier table, §5.13. | Merchant fee disputes (55–75%). |
| A10 | Observability: structured JSON logs, Sentry error tracking, Laravel Horizon for queues, health endpoints, uptime checks, dependency scanning (composer audit / npm audit / Dependabot), nightly encrypted DB backups with tested restores. | Product Technical Details §18, §20. | Undetected production failures. |

**System shape:**

```text
Browser (Vue 3 SPA, Tailwind, light/dark themes)
   │  HTTPS, Sanctum session cookie, CSRF
   ▼
Nginx ──► Laravel 11 API (/api/v1)
            │  middleware: auth → tenant → branch-scope → throttle → policy
            ▼
   Domain Services (Onboarding, Branch Ops, HR, Catalogue, Queue,
   Appointments, Sessions, Invoicing, Payments, Receipts, Billing Engine,
   Commissions, Client Protection, Reports, Audit)
            ▼
   PostgreSQL 16 (row-level tenancy, constraints, sequences)
   Redis 7 (cache, queues, rate limits)   Meilisearch (scoped indexes)
   S3 (private files, signed URLs)        SMTP provider (SES/Mailgun)
            ▼
   Horizon workers + Laravel Scheduler (billing cycles, day-close PDFs,
   inactivity sweeps, backups, index sync)
```

---

## 2. Assumptions and Constraints

Each assumption is documented so the IDE agent never silently guesses. If an assumption is invalidated by the product owner, raise it before implementing the affected phase.

| ID | Assumption / Constraint | Evidence / Justification |
|----|--------------------------|--------------------------|
| AS-1 | **Stack pinned:** Laravel 11.x, PHP 8.3, PostgreSQL 16, Redis 7, Vue 3.4+, TypeScript 5.x, Tailwind CSS 3.4+, Vite 5, Meilisearch 1.x, Docker Compose for dev, GitHub Actions for CI/CD. | Scope §6 mandates the layers; exact versions chosen as current LTS-grade releases satisfying "PHP 8.2+", "PostgreSQL preferred". |
| AS-2 | **Auth exception is documented:** Magic Link replaces email/password for all users (scope §2.3). Password reset flows are therefore N/A; email verification is intrinsic to Magic Link possession plus an explicit `email_verified_at` set on first successful login. MFA (TOTP) is an optional add-on for Super Admin, Merchant Admin, Finance, Audit roles (scope §11). | Required by §8 of the prompt ("unless a different login method is specified") and scope Universal Login Rule. |
| AS-3 | Currency is **KES**, stored as `bigint` minor units (cents) with a `currency` char(3) column for forward compatibility. Timezone: `Africa/Nairobi` for merchant-facing day boundaries; all timestamps stored UTC (`timestamptz`). | Scope examples use KES; Kenyan market focus. Money as integers prevents float drift in invoices/commissions/fees. |
| AS-4 | Clients are **records, not login accounts** at launch (scope §3.9). The schema must not block a future client portal (clients table keeps optional `user_id` nullable FK). | Scope §3.9 "optional later conversion". |
| AS-5 | Payments are **offline**; Servana records and validates them but never moves money. No PSP integration at launch. | Scope §2.2. |
| AS-6 | Launch notification channel is **email only**; SMS/WhatsApp are phased (notification abstraction must allow adding channels without schema change). | Scope §5.17. |
| AS-7 | Preferred-personnel fee launches with **fixed and percentage models only**; schema supports category/tier models via a `rules jsonb` column. | Scope §4.2 recommended launch model. |
| AS-8 | Search launches with **Meilisearch** (clients, staff, invoices, receipts, appointments) — adequate for SME-scale data, simplest ops. Elasticsearch only if a documented scale trigger occurs (>50M searchable docs). | Scope §6 allows Meilisearch "depending on scale". |
| AS-9 | Merchant Personnel **contact export does not exist anywhere**: no DB export flags, no API endpoints, no UI. Any attempt to call a non-existent export path under personnel scope is logged as an unauthorized-access attempt. | Scope §5.15 Removed Launch Capability. |
| AS-10 | Inactivity lifecycle (suspend at 3 months, delete at 6 months of no platform-fee payment) performs **anonymizing soft-deletion of identity data while archiving financial/audit records** to satisfy "must not be silently destroyed" (scope §3.2 Inactivity Rule) and Kenya Data Protection Act retention duties. | Scope §3.2. |
| AS-11 | One staging environment mirrors production topology. Production hosting is any Docker-capable host (e.g., AWS ECS/EC2 or DigitalOcean); the plan is host-agnostic but requires the §26 pipeline. | Product Technical Details §20. |
| AS-12 | "Super Administrator" is a **platform-scope role outside any merchant tenant**; platform staff records live in the same `users` table flagged via `platform_roles`. Super Admin never holds merchant roles. | Scope §3.1 exclusions; §7 of prompt (super-admin separation). |

---

## 3. Non-Negotiable Security Rules

These restate and operationalize scope §11 + Technical Details §22. CI enforces several mechanically (noted). **Any pull request violating these rules must be rejected.**

1. **No jQuery.** CI greps `package.json` and the bundle for `jquery` and fails the build.
2. **Frontend checks are UX only.** Every mutating route has a Form Request + Policy. CI test `tests/Feature/Security/RouteCoverageTest.php` asserts every `/api/v1` route (except auth + health) carries `auth:sanctum` and a policy/permission middleware.
3. **No cross-tenant or cross-branch leakage.** Every tenant-owned model uses the `BelongsToMerchant` global scope; branch-owned models add `BelongsToBranch`. Isolation tests (§25) are mandatory per module.
4. **No skipped authorization.** Controllers may not contain raw `Model::find()` on tenant data; route-model binding resolves by ULID **within tenant scope** so foreign ULIDs 404, never 403-with-existence-leak.
5. **No hardcoded secrets.** `.env` only; CI runs `gitleaks` on every push.
6. **No sensitive data in logs.** A log processor redacts `token`, `magic_link`, `payment_reference` partials, emails/phones in non-audit channels. Never log Magic Link tokens.
7. **Responsive behavior via CSS media queries only** (breakpoints §13). No JS device detection. No `user-agent` branching for layout.
8. **Never disable browser zoom.** `viewport` meta must remain `width=device-width, initial-scale=1` — no `maximum-scale`, no `user-scalable=no`. CI greps for violations.
9. **No fixed layouts that break mobile.** Every screen tested at 360px, 768px, 1280px in Playwright.
10. **No shipping without validation + rate limiting + secure auth.** Throttle map in §11.6 is mandatory.
11. **Accessibility is a release gate** (§15 checks run in CI via axe-core on critical pages).
12. **CSS is presentation only.** State changes (disabled, error, open/closed) are driven by ARIA attributes / data attributes set by JS or server state; CSS merely styles them.
13. **JS never substitutes for backend authorization.**
14. **Magic Links:** single-use, 15-minute expiry, 64-byte random tokens stored **hashed (SHA-256)**, bound to email + intended tenant context, invalidated on suspension/deactivation, throttled (§9).
15. **Audit logs are append-only and hash-chained.** No `UPDATE`/`DELETE` grants on `audit_logs` for the app DB role; a DB trigger raises an exception on update/delete attempts.
16. **Receipts only after validation.** DB-level guard: `receipts.payment_record_id` FK plus a trigger/constraint check that the referenced payment record status is `validated` at insert time, in addition to service-layer enforcement.
17. **Personnel contact export endpoints do not exist.** Tests assert 404 on any historic/guessed export path and that an `unauthorized_access` audit row is written for export-shaped requests under personnel scope.
18. **Exports use expiring signed URLs**, are permission-gated, reason-required for sensitive reports, download-counted, and audited (scope Finance Export Governance).
19. **HTTPS enforced in production** (HSTS, secure cookies, `SESSION_SECURE_COOKIE=true`, redirect 80→443 at Nginx).
20. **Strict CORS:** only the SPA origin(s); credentials allowed only for those origins.

---

## 4. System Architecture

### 4.1 Components

| Component | Technology | Responsibility |
|-----------|-----------|----------------|
| Edge | Nginx (container) | TLS termination, HTTP→HTTPS, gzip/brotli, static asset serving, proxy to PHP-FPM, security headers (CSP, HSTS, X-Content-Type-Options, Referrer-Policy, frame-ancestors 'none'). |
| App | Laravel 11 on PHP-FPM 8.3 | API (`/api/v1`), Sanctum SPA auth, domain services, policies. |
| SPA | Vue 3 + TS, built by Vite, served by Nginx | All role dashboards and workflows. |
| DB | PostgreSQL 16 | Source of truth; constraints enforce financial invariants. |
| Cache/Queue | Redis 7 (separate logical DBs: cache=0, queue=1, horizon=2, throttle=3) | Cache, queues, rate limiting, session locks. |
| Workers | Laravel Horizon (supervised container) | All queued jobs; per-queue concurrency (mail, pdf, billing, search, default). |
| Scheduler | `php artisan schedule:work` container | Billing cycles, day-close report dispatch, inactivity sweeps, backup trigger, Meilisearch health, ledger reconciliation. |
| Search | Meilisearch 1.x | Tenant-filtered indexes (every document carries `merchant_id`, `branch_id`; every query injects mandatory filters). |
| Object storage | S3-compatible (AWS S3 prod, MinIO dev) | Merchant logos, dispute evidence, generated PDFs, exports — all private; access via temporary signed URLs only. |
| Mail | SES/Mailgun via Laravel Mail | Magic Links, invitations, notifications, daily PDF reports. |
| Error tracking | Sentry (backend + frontend SDKs) | Exceptions, performance traces. |
| CI/CD | GitHub Actions | Lint, static analysis, tests, build, scan, deploy. |

### 4.2 Environments

| Env | Purpose | Data |
|-----|---------|------|
| local | Docker Compose dev | Seeded fixtures (2 merchants × 2 branches × all roles) |
| ci | Ephemeral per pipeline | Migrated + factory data |
| staging | Production mirror | Anonymized fixtures; real mail to sandbox inbox (Mailpit/SES sandbox) |
| production | Live | Real data; backups; monitoring |

### 4.3 Request lifecycle (authenticated API call)

```text
1. Nginx: TLS, headers, proxy
2. Laravel kernel: EnsureFrontendRequestsAreStateful (Sanctum), CSRF (web origin)
3. auth:sanctum → resolves User
4. ResolveTenantContext middleware → loads active MerchantUser membership
   (or platform context for Super Admin); aborts 403 'no_tenant_context'
   if user has none; sets app(TenantContext::class)
5. EnsureBranchScope middleware (branch-scoped route groups) → validates the
   {branch} route param ULID belongs to merchant AND user has an active
   branch_user_assignment (or is Merchant Admin); aborts 404 otherwise and
   writes an unauthorized_access audit row when the branch exists under
   another merchant
6. throttle:<named-limiter>
7. FormRequest validation (authorize() delegates to Policy)
8. Controller → Domain Service (DB transaction) → API Resource
9. AuditRecorder (within the same transaction for financial writes)
10. JSON response: { data, meta, links } or { error: { code, message, fields } }
```

---

## 5. Backend Architecture

### 5.1 Directory layout (Laravel 11, domain-oriented)

```text
app/
  Console/Commands/            # billing:run-cycle, merchants:inactivity-sweep, audit:verify-chain
  Domain/
    Auth/        {Actions, Services, Models: MagicLoginToken, Notifications}
    Tenancy/     {TenantContext.php, Concerns/BelongsToMerchant.php, Concerns/BelongsToBranch.php}
    Onboarding/  {Actions: RegisterMerchant, CompleteFirstTimeSetup}
    Merchants/   {Models: Merchant, MerchantProfile, MerchantUser; Services}
    Branches/    {Models: MerchantBranch, BranchOperatingHour, BranchCalendarException,
                  BranchDayRecord, BranchCashUp; Services: BranchDayService, CashUpService,
                  BranchClosureGuard}
    Hr/          {Models: StaffProfile, StaffInvitation, PersonnelServiceEligibility,
                  PersonnelAvailabilitySchedule; Services: StaffLifecycleService}
    Catalogue/   {Models: Service, ServiceCategory}
    Clients/     {Models: Client, ClientConsent; Services: DuplicateClientGuard, ClientMergeService}
    Scheduling/  {Models: Appointment; Services: AppointmentService, AvailabilityCalculator,
                  ConflictGuard}
    Queueing/    {Models: QueueEntry; Services: QueueService, WaitTimeEstimator,
                  ReassignmentService; States/}
    Sessions/    {Models: ServiceSession; Services: SessionService; States/}
    Invoicing/   {Models: Invoice, InvoiceItem, InvoiceNumberSequence;
                  Services: InvoiceService, NumberGenerator, VoidApprovalService,
                  TierPricingCalculator}
    Payments/    {Models: PaymentRecord, PaymentValidationEvent, PaymentReferenceCheck,
                  ExternalRefund, FinanceDispute; Services: PaymentRecordingService,
                  ValidationService, DuplicateReferenceDetector, RefundService}
    Receipts/    {Models: Receipt, ReceiptNumberSequence, ReceiptReissue;
                  Services: ReceiptService}
    Billing/     {Models: PlatformFeeLedgerEntry, PlatformFeeInvoice, PlatformFeeDispute,
                  ServiceFeeTier enum; Services: BillingEngine, FeeAccrualService,
                  CycleInvoiceService, SuspensionTriggerService}
    Commissions/ {Models: CommissionRule, CommissionLedgerEntry;
                  Services: CommissionCalculator, CommissionReversalService}
    FinanceOps/  {Models: FinancialPeriodLock, FinanceExport; Services: PeriodLockService,
                  ExportService}
    Audit/       {Models: AuditLog, FlaggedAuditEvent; Services: AuditRecorder, ChainVerifier}
    Notifications/ {Models: NotificationLog; Channels, Notifications/*}
    Reports/     {Services: BranchReportService, MerchantReportService, PlatformReportService,
                  Pdf/DayCloseReportPdf, Pdf/CashUpReportPdf}
  Http/
    Controllers/Api/V1/{Auth, Platform, Merchant, Branch, Hr, Catalogue, Clients,
                        Scheduling, Queueing, Sessions, Invoicing, Payments, Receipts,
                        Billing, Commissions, Finance, Audit, Reports, Notifications}/
    Middleware/{ResolveTenantContext, EnsureBranchScope, EnsureRole, EnsurePermission,
                EnsureMerchantActive, LogUnauthorizedAttempt}
    Requests/...      # one FormRequest per mutating endpoint
    Resources/...     # one JsonResource per exposed model
  Policies/           # one per tenant-owned model
  Jobs/               # SendMagicLink, SendStaffInvitation, GenerateDayClosePdf,
                      # GenerateCashUpPdf, AccruePlatformFee, RunBillingCycle,
                      # CalculateCommission, ReverseCommission, BuildFinanceExport,
                      # SyncSearchIndex, SweepInactiveMerchants, NotifyFinanceInbox
  Enums/              # backed string enums for every status machine
  Support/Money.php   # integer money value object
database/{migrations, seeders, factories}
routes/{api_v1.php, web.php, console.php}
tests/{Unit, Feature, Feature/Security, Feature/Isolation, Browser}
```

### 5.2 Backend conventions (binding)

- **Status fields are PHP backed enums** mirrored by Postgres `CHECK` constraints; transitions validated by tiny state-machine classes (`Domain/*/States`) that throw `InvalidTransition` (422 `invalid_state_transition`).
- **All multi-step writes are transactional.** Walk-in creation, invoice+items, payment validation→receipt→commission→fee accrual chains each run in `DB::transaction()` with `lockForUpdate()` on sequence and balance rows.
- **Money** never floats: `Money` value object wrapping `int` minor units; Eloquent casts.
- **Eloquent mass-assignment:** every model declares explicit `$fillable`; `Model::preventSilentlyDiscardingAttributes()` and `preventLazyLoading()` enabled in non-production to surface mistakes during development.
- **No business logic in controllers.** Controllers: validate → authorize → call service → return Resource.
- **Events** (`PaymentValidated`, `InvoiceVoided`, `RefundApproved`, `StaffSuspended`, …) decouple side effects: commission calc, fee accrual, notifications, search sync, session invalidation — each side effect is a queued listener carrying tenant context.

---

## 6. Frontend Architecture

### 6.1 Stack and structure

Vue 3 (Composition API, `<script setup lang="ts">`), Pinia, Vue Router 4, Tailwind CSS, Vite, axios-based API client, Headless UI + custom components, vue-i18n-ready copy files (English at launch). Structure follows scope §9 exactly:

```text
resources/spa/src/
  layouts/    AuthLayout.vue  PlatformAdminLayout.vue  MerchantLayout.vue
              BranchLayout.vue FrontOfficeLayout.vue PersonnelLayout.vue
              FinanceLayout.vue AuditLayout.vue
  pages/      auth/ platform/ merchant/ branch/ hr/ finance/ front-office/
              personnel/ audit/
  components/ forms/ tables/ modals/ dashboards/ queue/ appointments/
              invoices/ receipts/ reports/ cash-up/ audit/ ui/ (design system)
  services/   apiClient.ts authService.ts tenantContext.ts permissionService.ts
  stores/     authStore.ts merchantStore.ts branchStore.ts permissionStore.ts
              themeStore.ts notificationStore.ts
  router/     index.ts guards.ts routes/{auth,platform,merchant,branch,hr,
              finance,frontOffice,personnel,audit}.ts
  types/      api.ts models.ts enums.ts
  utils/      money.ts dates.ts status.ts
```

### 6.2 Authentication & tenant state

- `authStore`: `user`, `memberships[]` (merchant + role + branch assignments), `activeMembership`, `permissions[]` from `GET /api/v1/me`. Bootstrap on app start; show full-page loading state until resolved.
- Login flow: request Magic Link → "check your email" screen → `/auth/verify?token=…` page POSTs token → Sanctum session established → bootstrap `/me` → route to role home (`permissionService.homeRouteFor(role)`).
- **Branch switching:** users with multiple branch assignments pick the active branch (persisted server-side in `merchant_users.last_branch_id`); the API still authorizes every request independently — the selector is convenience only.
- Router guards: `requiresAuth`, `requiresRole`, `requiresPermission`, `requiresActiveMerchant`. Guards are UX; the API is the security boundary (§3 rule 2).

### 6.3 API client (`services/apiClient.ts`)

- Single axios instance: `baseURL=/api/v1`, `withCredentials=true`, `X-Requested-With`, automatic CSRF cookie priming (`GET /sanctum/csrf-cookie`) before first mutating call.
- Response interceptor maps the structured error envelope (§11.5) to typed `ApiError { code, message, fields }`. 401 → logout + redirect; 403 `merchant_suspended` → suspended-merchant screen; 423 `period_locked` → locked-period banner; 409 `duplicate_reference` / `duplicate_client` → dedicated modals; 429 → toast with retry-after.
- All list calls share `Paginated<T>` typing: `{ data: T[], meta: { current_page, per_page, total, last_page }, links }`.

### 6.4 Required UI states (scope §9) — implementation contract

Every page/component must explicitly render: Loading (skeletons, no spinners-only), Empty (illustrated, with primary action), Success, Error (retry affordance), plus the domain states: Unauthorized, Suspended merchant, Inactive user, Pending payment validation, Pending staff activation, Suspended branch, Branch closed, Pending cash-up review, Financial period locked, Duplicate payment reference, Duplicate client warning, No branch access, No permission. These are encoded as a `<StateBoundary>` wrapper component taking a typed `viewState` discriminated union; CI Playwright tests snapshot each state for the critical pages.

### 6.5 Safe rendering & secrets

- Never `v-html` user content; the rare rich fields (notes) render as plain text with line breaks via CSS `white-space: pre-line`.
- No secrets, no privileged logic in the bundle. CI greps `dist/` for `APP_KEY`, `SECRET`, AWS key patterns.

---

## 7. Database Architecture

PostgreSQL 16. Conventions for **every** table unless noted: `id bigint generated always as identity primary key`; `ulid char(26) not null unique` (public identifier; indexed); `created_at/updated_at timestamptz`; tenant-owned tables add `merchant_id bigint not null references merchants(id)` (indexed); branch-owned tables add `branch_id bigint not null references merchant_branches(id)` (indexed, composite index `(merchant_id, branch_id)`); mutable business tables add `created_by/updated_by bigint references users(id)`; soft deletes (`deleted_at timestamptz`) only where recovery is a business need (marked SD below). Financial and audit tables are **never** soft- or hard-deleted by application code (retention policy §7.4).

### 7.1 Identity, tenancy, access

**users** — global identities (platform staff + merchant staff). No password column.
| Column | Type | Notes |
|---|---|---|
| id, ulid | — | PK / public id |
| email | citext not null unique (partial: `where deleted_at is null`) | login identity |
| phone | varchar(32) null, partial unique where active staff (see staff_profiles) | |
| first_name, last_name, display_name | varchar(120) | |
| email_verified_at, last_login_at | timestamptz null | set on first/each magic-link success |
| status | enum: active, suspended, deactivated | check constraint |
| is_platform_staff | boolean default false | Super Admin scope flag |
| mfa_secret (encrypted), mfa_enabled_at | text/timestamptz null | optional TOTP |
| deleted_at | SD | anonymization on lifecycle delete |
Indexes: email, status. **Security:** email is the only credential anchor; uniqueness enforced for active rows.

**merchants** — tenant root. `ulid`, `name varchar(160)`, `slug citext unique`, `status enum(active,suspended,deactivated,pending_setup)`, `service_fee_tier enum(customer_centric,split_tier,business_centric)`, `setup_completed_at timestamptz null`, `suspended_at`, `suspension_reason text`, `last_fee_payment_at timestamptz null` (drives inactivity rule), timestamps. Index: status, last_fee_payment_at.

**merchant_profiles** — 1:1 merchant. `merchant_id unique`, `business_category varchar(80)`, `logo_path varchar(255) null` (S3 key, private), `contact_email`, `contact_phone`, `address`, `town`, `country char(2) default 'KE'`, `timezone varchar(64) default 'Africa/Nairobi'`.

**merchant_users** — membership: which user holds which account-type role in which merchant.
| Column | Type | Notes |
|---|---|---|
| merchant_id, user_id | FK | unique together (one membership per user per merchant) |
| role | enum: merchant_admin, branch_manager, hr, finance, front_office, personnel, audit | the seven merchant account types |
| status | enum: invited, active, suspended, deactivated | |
| invited_by | FK users | |
| activated_at, suspended_at, deactivated_at | timestamptz | |
| last_branch_id | FK null | UX branch selector persistence |
Indexes: (merchant_id, role, status), user_id. **Security:** all authorization derives from an `active` row here.

**branch_user_assignments** — branch scope per membership. `merchant_user_id FK`, `branch_id FK`, `status enum(active,revoked)`, `assigned_by FK users`, `assigned_at`, `revoked_at`. Unique `(merchant_user_id, branch_id) where status='active'`. **Every branch-scoped role (branch_manager, hr, finance, front_office, personnel, audit) requires an active row to touch branch data.** Merchant Admin sees all branches of own merchant by role.

**roles / permissions / role_permission_assignments** — permission registry (seeded, not merchant-editable at launch): `permissions(key citext unique, group varchar(60), description)`; `roles(key citext unique, scope enum(platform,merchant))`; pivot with unique pair. The full matrix is §10.3. Granular finance permissions can be toggled per merchant_user via **merchant_user_permission_overrides** (`merchant_user_id`, `permission_id`, `granted boolean`, `set_by`, unique pair) to satisfy "Merchant Finance must not launch as one broad permission".

**magic_login_tokens** — `user_id FK`, `token_hash char(64) unique` (SHA-256 of 64-byte random), `intended_merchant_id FK null`, `ip_requested inet`, `expires_at timestamptz not null`, `consumed_at timestamptz null`, `invalidated_at timestamptz null`, `created_at`. Index: (user_id, consumed_at). Retention: purge rows >30 days nightly. **Security:** raw token never stored; single use enforced by atomic `UPDATE … SET consumed_at=now() WHERE token_hash=? AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at>now()` returning row count 1.

**staff_profiles** — 1:1 with merchant_users for staff. `merchant_user_id unique`, `merchant_id`, `primary_branch_id FK`, `first_name,last_name,display_name`, `phone varchar(32) not null`, `profile_photo_path null`, `role_title varchar(80)` (Barber, Stylist…), `employment_type enum(full_time,part_time,contract,commission_only)`, `employment_status enum(employed,on_leave,terminated)`, `start_date date`, `invited_by FK`. Partial uniques: `phone` and user email unique among **active** staff platform-wide (scope Duplicate Staff Prevention) — implemented as partial unique indexes joined through a denormalized `is_active boolean` maintained by the lifecycle service. SD.

**staff_invitations** — `merchant_id, branch_id, email citext, role enum(merchant account types), role_title`, `service_eligibility_ids jsonb null`, `token_hash char(64) unique`, `status enum(pending,accepted,revoked,expired)`, `invited_by`, `expires_at (72h)`, `accepted_at, revoked_at`, `resend_count smallint default 0`, `last_sent_at`. Index: (merchant_id,status), email. Audit every transition.

**staff_history** — append-only role/branch/status history (scope HR table): `staff_profile_id`, `field enum(role,branch,status,employment_status,service_eligibility,availability)`, `old_value jsonb`, `new_value jsonb`, `changed_by`, `reason text null`, `approval_status enum(n/a,pending,approved,rejected)`, `created_at`.

### 7.2 Branch operations

**merchant_branches** — `merchant_id`, `name`, `code varchar(20)` unique per merchant (`unique(merchant_id, code)`), `address text not null`, `town varchar(80) not null`, `phone varchar(32) not null`, `email null`, `business_category` (nullable override), `status enum(active,suspended,archived)`, `status_reason`, timestamps. **Closure protection** enforced in `BranchClosureGuard` service (checks the 8 blocking conditions of scope §3.3) — and a deferred constraint trigger refuses `status='archived'` when open operational rows exist.

**branch_operating_hours** — `branch_id`, `weekday smallint 0-6`, `opens_at time`, `closes_at time`, `is_closed boolean`, `break_start/break_end time null`. Unique (branch_id, weekday).

**branch_calendar_exceptions** — `branch_id`, `date date`, `type enum(public_holiday,special_closure,emergency_closure,modified_hours)`, `opens_at/closes_at null`, `reason text not null when closure`, `created_by`. Unique (branch_id, date, type). Emergency closure immediately blocks queue/appointments (service checks today's exceptions).

**branch_day_records** — `branch_id`, `business_date date`, `status enum(not_opened,open,paused,closed,reopened)`, `opened_by/opened_at`, `closed_by/closed_at`, `reopened_reason`, `summary jsonb` (day-close totals snapshot), unique (branch_id, business_date). Day-close PDF job reads `summary`.

**branch_cash_ups** — `branch_id`, `branch_day_record_id FK`, `expected_total bigint`, `recorded_totals jsonb` (per method), `cash_counted bigint`, `discrepancy_amount bigint`, `discrepancy_note text null (required when ≠0, validated)`, `status enum(draft,submitted,approved,rejected)`, `submitted_by/at`, `reviewed_by/at`, `review_note`. Approval triggers daily period lock + Merchant Admin PDF email.

### 7.3 Catalogue, clients, scheduling, queue, sessions

**service_categories** — `merchant_id, branch_id, name`, unique (branch_id, name). SD.
**services** — `merchant_id, branch_id, category_id FK null, name, description, price bigint, currency char(3) default 'KES', duration_minutes smallint, status enum(active,inactive,archived), discount_type enum(none,fixed,percent), discount_value int, preferred_fee_eligible boolean default true`. Index (branch_id, status). SD. Configured by Branch role only (policy).

**personnel_service_eligibilities** — `merchant_id, branch_id, staff_profile_id, service_id, assigned_by, status enum(active,revoked)`. Unique active pair (staff_profile_id, service_id). Assignment authority: HR within own branch only.

**personnel_availability_schedules** — `staff_profile_id, branch_id, weekday/opens/closes/break fields` + **personnel_unavailabilities** (`staff_profile_id, starts_at, ends_at, type enum(sick,emergency,leave,break,no_show,other), note, created_by`). Operational state lives on staff_profiles.`availability_state enum(available,busy,on_break,offline,unavailable,suspended)`.

**clients** — branch-scoped records. `merchant_id, branch_id, first_name, last_name, phone varchar(32) not null, email null, gender null, notes text, preferences jsonb, marketing_consent boolean default false, consent_recorded_at`. **Duplicate prevention:** partial unique `(branch_id, phone) where deleted_at is null and merged_into_id is null`; `merged_into_id FK clients null` supports controlled merge. SD. Index (branch_id, phone), trigram index on name for search fallback.

**client_consents** — append-only: `client_id, type enum(contact,marketing,data_processing), granted boolean, recorded_by, source, created_at`.

**appointments** — `merchant_id, branch_id, client_id, service_id, personnel_staff_profile_id null (preferred/assigned), starts_at, ends_at, status enum(booked,checked_in,converted,completed,cancelled,no_show,rescheduled), is_preferred_personnel boolean, cancellation_reason, checked_in_at, queue_entry_id FK null (set on conversion — guarantees no duplicate queue/session records), created_by`. Exclusion constraint `EXCLUDE USING gist (personnel_staff_profile_id WITH =, tstzrange(starts_at, ends_at) WITH &&) WHERE (status IN ('booked','checked_in'))` prevents double-booking at DB level. Index (branch_id, starts_at).

**queue_entries** — `merchant_id, branch_id, client_id, service_id, appointment_id FK null, assignment_mode enum(next_available,manual,preferred)`, `personnel_staff_profile_id null`, `is_preferred boolean`, `preferred_fee_amount bigint null`, `position int`, `status enum(waiting,assigned,in_service,completed,cancelled,no_show)`, `cancellation_reason (required on cancel)`, `estimated_wait_minutes`, `reassigned_from_staff_id null`, `reassignment_reason`, timestamps per transition (`assigned_at, started_at, completed_at`). Index (branch_id, status, position). Partial unique `(appointment_id) where appointment_id is not null` (one queue entry per appointment).

**queue_configurations** — per branch: `queue_open boolean, capacity_total int null, capacity_per_personnel int null, default_assignment_mode`.

**service_sessions** — `merchant_id, branch_id, client_id, service_id, personnel_staff_profile_id, queue_entry_id FK unique null, appointment_id FK null, status enum(draft,waiting,assigned,in_progress,completed,cancelled,invoiced,paid), started_at, ended_at, notes, cancellation_reason, invoice_id FK null`. Partial unique on queue_entry_id prevents duplicate sessions; eligibility check (FK pair must exist in personnel_service_eligibilities) enforced in service layer + insert trigger.

**preferred_personnel_fee_rules** — platform-scoped (no merchant_id): `model enum(fixed,percentage), value int (minor units or basis points), applies_to enum(all,service_category,branch_category,personnel_tier) default 'all', rules jsonb null, active boolean, effective_from, set_by`. Only Super Admin writes.

### 7.4 Financial tables (no deletes, ever; retention ≥ 7 years per Kenyan accounting practice)

**invoice_number_sequences / receipt_number_sequences** — `merchant_id unique, next_number bigint default 1`. Number generation: `SELECT … FOR UPDATE` within the issuing transaction; formatted as `{BRANCHCODE-}INV-{000000}`; uniqueness backstopped by `unique(merchant_id, number)` on invoices/receipts. Voided invoices keep numbers.

**invoices** — `merchant_id, branch_id, client_id, service_session_id FK null, number varchar(30), type enum(merchant_client,platform_fee), subtotal bigint, discount_total bigint, preferred_fee_total bigint, tier_surcharge_total bigint, total bigint, currency, status enum(draft,issued,partially_paid,paid,voided,adjusted), service_fee_tier_applied enum null, issued_at, voided_at, void_reason, void_approved_by, created_by`. Unique (merchant_id, number). Indexes: (branch_id,status,issued_at), client_id. Tier math (per scope §3.2): customer_centric → surcharge 0; split_tier → +50% of platform fee per qualifying line; business_centric → +100%. Platform-fee liability is **never** reduced by tier.

**invoice_items** — `invoice_id, kind enum(service,preferred_personnel_fee,tier_surcharge,discount,adjustment), service_id null, description, quantity smallint, unit_amount bigint, line_total bigint, personnel_staff_profile_id null`.

**payment_records** — `merchant_id, branch_id, invoice_id, method enum(cash,mpesa,bank_transfer,card_terminal,voucher,split_leg,other), amount bigint, reference varchar(120) null (required per method rules), reference_normalized varchar(120) (upper/trimmed, for dup detection), parent_payment_id FK null (split legs), paid_at, note, status enum(pending_validation,validated,partially_validated,rejected,disputed,correction_requested,voided,refunded_externally), recorded_by, validated_by null, validated_at, rejection_reason`. Indexes: (invoice_id), (merchant_id, reference_normalized), (branch_id,status). Method-specific reference requirements validated in FormRequest + service (scope table). Duplicate detection: same-merchant match on `reference_normalized` for non-cash methods → block/warn; overrides recorded in **payment_reference_checks** (`payment_record_id, matched_payment_id, scope enum(same_branch,same_merchant), action enum(blocked,warned,overridden), override_reason, overridden_by`).

**payment_validation_events** — append-only history: `payment_record_id, from_status, to_status, actor_id, note, rejection_reason, created_at`.

**receipts** — `merchant_id, branch_id, invoice_id, number varchar(30), validated_amount bigint, methods jsonb, issued_by, issued_at, status enum(issued,reversed), reversal_reason, reversed_by/at, pdf_path`. Unique (merchant_id, number). **Constraint trigger:** on insert, every linked payment record (via **receipt_payment_records** pivot: receipt_id, payment_record_id unique) must have status `validated`, else raise. **receipt_reissues**: `original_receipt_id, new_receipt_id, reason, reissued_by`. **receipt_download_logs**: `receipt_id, downloaded_by, ip, user_agent, created_at`.

**external_refunds** — `merchant_id, branch_id, invoice_id, payment_record_id, type enum(full,partial), amount bigint (≤ validated amount, checked), method, reference, status enum(requested,approved,rejected,finalized), requested_by, approved_by, approval_note, finalized_at`.

**finance_disputes** — fields per scope: `merchant_id, branch_id, dispute_type, invoice_id null, payment_record_id null, raised_by, assigned_to, amount_in_dispute, reason, evidence_path null (S3, private), status enum(open,under_review,evidence_requested,resolved,rejected,escalated,closed), resolution_note, resolved_by, resolved_at`.

**financial_period_locks** — `merchant_id, branch_id null (null = merchant-month lock), period_type enum(day,month), period_start date, period_end date, status enum(locked,reopened), locked_by/at, reopened_by/at, reopen_reason, reopen_approved_by`. Unique (branch_id, period_type, period_start). `PeriodLockService::assertWritable(branch, date)` is called by every financial mutation; locked → 423 `period_locked`.

**platform_fee_ledger** — Citrus billing ledger (append-only): `merchant_id, branch_id, entry_type enum(fee_accrual,fee_payment,adjustment,exemption,dispute_credit), source_invoice_id null, billing_cycle_id null, amount bigint (signed), balance_after bigint, description, calculation jsonb (explanation: base fee, tier, rule version), created_by null (system)`. Index (merchant_id, created_at), (branch_id). Branch-level debt = sum per branch (drives the "clear branch debts before branch-user deletion" rule and suspension triggers).

**platform_fee_invoices** — Citrus→merchant invoices per cycle: `merchant_id, cycle_start, cycle_end, number, total bigint, status enum(issued,paid,partially_paid,overdue,disputed), due_at, paid_at`. **platform_billing_settings** (platform-scoped): `base_fee_amount bigint, billing_cycle enum(weekly,monthly), invoice_day smallint, grace_days smallint, suspension_after_days smallint, preferred_fee_platform_treatment enum(included,exempt), version int, set_by`. **platform_fee_disputes**: ledger/invoice reference, reason, status workflow, resolution.

**commission_rules** — HR-configured: `merchant_id, branch_id, scope enum(all_personnel,role,individual,service), staff_profile_id null, role_title null, service_id null, type enum(fixed,percentage), value int, applies_to_preferred_fee boolean, status, effective_from, set_by`. Precedence: individual > service > role > all_personnel (documented in CommissionCalculator).
**commission_ledger** — `merchant_id, branch_id, staff_profile_id, invoice_id, invoice_item_id, rule_id, base_amount bigint, commission_amount bigint, status enum(pending,earned,reversed,paid), earned_at (on payment validation), reversed_reason, period date`. Index (staff_profile_id, period), (branch_id, status).

**finance_exports** — `merchant_id, branch_id null, requested_by, report_type, scope jsonb (date range/filters), reason text (required for sensitive types), status enum(queued,ready,expired,failed), file_path, signed_url_expires_at, download_count int default 0, masked boolean`. **export_download_logs** child table.

### 7.5 Audit, notifications, framework

**audit_logs** — append-only, hash-chained. Exact field set from scope §5.18: `id, ulid, actor_user_id null, actor_role varchar, merchant_id null (null = platform event), branch_id null, action varchar(120), target_entity_type varchar(120), target_entity_id varchar(40) (ULID), old_values jsonb null, new_values jsonb null, severity enum(info,low,medium,high,critical), event_status enum(normal,flagged), ip_address inet, user_agent text, record_hash char(64), previous_record_hash char(64), created_at`. `record_hash = sha256(previous_record_hash || canonical_json(payload))`, chained **per merchant** (platform chain for merchant_id null), with the previous hash read under advisory lock `pg_advisory_xact_lock(hashtext('audit:'||merchant_id))` to serialize the chain. DB trigger blocks UPDATE/DELETE. `audit:verify-chain` command re-walks chains nightly and alerts on breaks. Partition by month (`created_at`) from day one (high volume). Sensitive values in old/new are masked at **read** time per viewer permission, never at write time.

**flagged_audit_events** — `audit_log_id, status enum(open,under_review,resolved,dismissed), severity, reason, created_by, reviewed_by, resolution_note, created_at/updated_at`.

**notification_logs** — `merchant_id null, branch_id null, user_id null, client_id null, channel enum(email,sms,whatsapp), type varchar(80) (the 20 scope types), payload jsonb (no secrets), status enum(queued,sent,failed), provider_message_id, sent_at, failed_reason`. Retention 12 months.

**Framework tables:** `sessions` (database driver for revocability — suspending a user deletes their session rows immediately), `jobs`, `failed_jobs`, `job_batches`, `cache`, `personal_access_tokens` (Sanctum; unused for SPA but kept for future mobile tokens), `media/uploaded_files` (`merchant_id, branch_id null, owner_type/owner_id, disk, path, original_name, mime, size_bytes, sha256, visibility enum(private), uploaded_by`).

### 7.6 Migration rules

- One migration per table; FKs and check constraints in the same migration; partial/exclusion indexes via raw statements with `DB::statement`.
- Never edit a shipped migration; forward-only changes.
- Every migration must be reversible **or** explicitly documented as irreversible (audit partitions).
- Seeders: `PermissionSeeder` (full matrix), `PlatformSettingsSeeder`, `DemoTenantSeeder` (local/staging only, guarded by `app()->environment()`).

---

## 8. Multi-Tenancy and Data Isolation Model

### 8.1 Tenant resolution

`ResolveTenantContext` middleware (after `auth:sanctum`):
1. Super Admin requests under `/api/v1/platform/*` get a **PlatformContext** (no merchant). Super Admin is rejected (403) on merchant routes — exclusions in scope §3.1 are enforced structurally: there are no platform endpoints to create merchants, create first admins, or run merchant operations; governance endpoints (suspend merchant, billing settings, fee rules) exist only under `/platform`.
2. For merchant users: load the single active `merchant_users` row (one membership per user at launch). If none → 403 `no_tenant_context`. If `merchant.status != active` → 403 `merchant_suspended` (read-only historical endpoints allowlisted for suspended merchants per scope branch rules).
3. Bind `TenantContext { merchant, merchantUser, role, permissions, branchIds[] }` into the container.

### 8.2 Query scoping

- `BelongsToMerchant` trait: global scope `where merchant_id = TenantContext::merchantId()` + creating hook that sets `merchant_id` (throws if context missing). Applied to **every** tenant-owned model.
- `BelongsToBranch` trait: for branch-scoped roles adds `whereIn branch_id (TenantContext::branchIds())`; Merchant Admin and merchant-wide reads skip the branch filter but keep the merchant filter.
- Escaping the scope requires `Model::withoutTenancy()` which (a) is only callable from `Platform*` services and audited jobs, and (b) triggers a static-analysis ban elsewhere (PHPStan rule `NoWithoutTenancyOutsidePlatform`).
- **Route-model binding:** `resolveRouteBinding` looks up by `ulid` **within the scoped query** → foreign-tenant ULIDs yield 404 and write an `unauthorized_access` audit row when the ULID exists in another tenant (detected via an unscoped existence check inside `LogUnauthorizedAttempt`).

### 8.3 Tenant context in background jobs

Every job that touches tenant data extends `TenantAwareJob`: constructor captures `merchantId` (+ `branchId` where relevant); `handle()` first rehydrates `TenantContext` from IDs (re-validating merchant status), or fails the job with `MissingTenantContext`. A Horizon middleware asserts context is set before any Eloquent call on tenant models (the global scope throws otherwise — this is the proof mechanism). Exports, notifications, webhooks (future), and search-index jobs all inherit this.

### 8.4 Denied-case examples (each becomes a permanent test)

| Case | Expected behavior | Test |
|------|-------------------|------|
| User of Merchant A GETs `/api/v1/invoices/{ulid-of-B}` | 404 + audit `unauthorized_access` (severity high) | `Isolation/InvoiceCrossTenantTest` |
| Finance user of Branch X lists payments of Branch Y (same merchant) | 404/empty per scope; attempt audited | `Isolation/CrossBranchPaymentTest` |
| Member with role but without `payments.validate` POSTs validation | 403 `permission_denied` | `Security/FinancePermissionTest` |
| Queued `GenerateDayClosePdf` dispatched without merchant id | Job fails `MissingTenantContext`; alert | `Unit/TenantAwareJobTest` |
| Export job given an unscoped query | Export service refuses (asserts scope applied) | `Feature/Finance/ExportScopeTest` |
| Valid public ULID of another tenant passed to any binding | 404, never 403 (no existence leak), audit row | `Isolation/RouteBindingTest` (parameterized over all bound models) |
| Personnel requests another personnel's queue | 404 | `Isolation/PersonnelOwnScopeTest` |
| Suspended merchant user calls any mutating endpoint | 403 `merchant_suspended` | `Security/SuspendedMerchantTest` |

### 8.5 Super Admin safety

Super Admin operates only via `/platform/*` read/governance endpoints; reads of merchant data are aggregate (reports, ledgers, audit logs) and individually audited (`platform_read`, severity medium). There is **no** impersonation feature at launch.

---

## 9. Authentication Model

### 9.1 Magic Link flow (all users)

```text
POST /api/v1/auth/magic-link            { email }
  → throttle: 3/min per email, 10/hour per IP
  → ALWAYS return 202 {message:"If the email exists and is active, a link was sent."}
    (no account enumeration)
  → server-side checks BEFORE sending (scope §2.3, all seven):
      1 user exists by email
      2 user has an active membership in a merchant tenant (or is platform staff)
      3 user.status = active
      4 merchant_users.status = active (role active)
      5 user not suspended (merchant or platform level)
      6 branch assignment exists where the role is branch-scoped
      7 (link validity checks happen at verify time)
    If any check fails → no email; write audit login_link_denied (low/medium).
  → create magic_login_tokens row (hash only), queue SendMagicLink mail
    Link: https://app.servana.africa/auth/verify?token=<raw>

POST /api/v1/auth/magic-link/verify     { token }
  → throttle: 10/min per IP
  → atomic consume (see §7.1) → on success:
      - re-run checks 1–6 at consume time (status may have changed)
      - login via Sanctum session guard (regenerate session id — fixation defense)
      - set email_verified_at if null, last_login_at
      - audit login_success (info) with ip/user_agent
  → failures: 422 invalid_or_expired_token (uniform message); audit login_link_failed
```

### 9.2 Session security

- Sanctum SPA mode: stateful first-party cookies; `SESSION_DRIVER=database`; `SESSION_LIFETIME=480` (8h, absolute), idle timeout 60 min enforced by `last_activity` middleware; `SESSION_SECURE_COOKIE=true`, `http_only`, `same_site=lax`.
- CSRF: `/sanctum/csrf-cookie` priming; all mutating routes verified.
- **Immediate revocation:** suspending/deactivating a user deletes their `sessions` rows and invalidates unconsumed magic tokens in the same transaction (`StaffLifecycleService`), satisfying scope §3.4 Suspension Rule. Test proves a live session 401s on the next request.
- Optional MFA (TOTP): when enabled for Super Admin/Merchant Admin/Finance/Audit, verify step returns `mfa_required`; `POST /auth/mfa/verify {code}` completes login. Secrets encrypted at rest; 5 attempts/5 min throttle.

### 9.3 Rate limiting (named limiters, Redis-backed)

| Limiter | Applied to | Limit |
|---|---|---|
| magic-link-request | /auth/magic-link | 3/min/email + 10/hr/IP |
| magic-link-verify | /auth/magic-link/verify | 10/min/IP |
| registration | /merchant-registration/self-register | 3/hr/IP |
| invitation-accept | invitation acceptance | 10/hr/IP |
| api | all authenticated API | 120/min/user |
| finance-sensitive | validation, voids, refunds, exports, period locks | 30/min/user |
| search | search endpoints | 60/min/user |
Credential-stuffing defense: per-email + per-IP counters, exponential backoff after repeated denials, audit `login_abuse_suspected` (high) at threshold.

---

## 10. Authorization, Roles, and Permissions

### 10.1 Roles

Platform scope: `super_admin` (+ internal platform roles later). Merchant scope (the seven account types — these supersede the generic Owner/Admin/Manager/Member/Viewer minimum, mapping: Owner/Admin→merchant_admin, Manager→branch_manager, Member→front_office/personnel/finance/hr, Viewer→audit):
`merchant_admin` (Owner — single account type per scope §3.2), `branch_manager`, `hr`, `finance`, `front_office`, `personnel`, `audit`.

### 10.2 Authority boundaries (hard rules from scope — enforced in policies AND absent from UI)

- Merchant Admin: creates branches; adds **only** branch_manager + hr emails; cannot configure services/pricing, personnel assignment, commissions, or Front Office payment permissions; deleting a branch user requires that branch's platform-fee debt = 0 (`BillingEngine::branchDebt()` check).
- Branch Manager: own branch only; configures services/pricing, queue, appointments, day open/close, cash-up submission; read-only on HR-controlled personnel data; may **transfer** queue/appointments from unavailable personnel (operational continuity), never assign personnel.
- HR: staff lifecycle within own branch only; sets commissions and service eligibility; cannot export client/payment data; cannot self-escalate (policy denies role changes where target = self or target role outranks actor).
- Finance: granular permissions (next table); branch-scoped.
- Front Office: operational flows; records payments but **cannot validate** or issue receipts (Finance validates; receipts auto-issue post-validation).
- Personnel: own-scope reads only (own queue/appointments/sessions/commissions/served clients); no export anything.
- Audit: read-only everywhere; server-side write denial on all merchant resources.

### 10.3 Permission matrix (registry keys; ✓ = default grant; ◐ = grantable override)

| Permission key | merchant_admin | branch_manager | hr | finance | front_office | personnel | audit |
|---|---|---|---|---|---|---|---|
| merchant.profile.manage / merchant.tier.update | ✓ | | | | | | |
| branches.create / branches.manage_users_lifecycle | ✓ | | | | | | |
| branch.profile.manage, branch.calendar.manage | | ✓ | | | | | |
| services.manage | | ✓ | | | | | |
| queue.configure / queue.operate | | ✓ / ✓ | | | ✓ (operate) | | |
| queue.transfer_entries | | ✓ | | | | | |
| appointments.manage | | ✓ | | | ✓ | | |
| day.open_close / cashup.submit | | ✓ | | | | | |
| staff.invite/edit/suspend, eligibility.manage, availability.manage, commissions.manage | | | ✓ | | | | |
| clients.create/edit/view | | view | | view | ✓ | view-own-served | view-masked |
| sessions.manage / invoices.create | | ✓ | | | ✓ | | |
| invoices.view | ✓ | ✓ | | ✓ | ✓ | own | ✓ |
| invoices.void_unpaid | | | | ✓ | | | |
| invoices.void_paid / invoices.adjust_paid | ✓ (approve) | | | ◐ request | | | |
| payments.record | | | | ✓ | ✓ | | |
| payments.validate / payments.reject | | | | ✓ | | | |
| payments.edit_reference | | | | ◐ | | | |
| payments.override_duplicate | | | | ◐ | | | |
| receipts.view / receipts.reissue | ✓ | ✓ | | ✓ / ◐ | view | own | view |
| refunds.request / refunds.approve | | | | ✓ / ◐ | | | |
| disputes.manage | | | | ✓ | | | |
| cashup.review_approve | | | | ✓ | | | |
| periods.lock / periods.reopen | ✓ | | | ◐ delegated | | | |
| commissions.view | ✓ | branch | ✓ | ◐ | | own | ✓ |
| platform_fees.view / platform_fees.dispute | ✓ | branch | | ◐ / ✓ | | | ✓ |
| reports.view (scoped) | ✓ | branch | staff | finance | day | own | ✓ |
| exports.finance / exports.staff_roster | ◐ admin/finance | | ✓ roster only | ◐ | | **never** | ◐ audit reports |
| audit.view_full / audit.flag | masked | branch | staff events | finance events | | | ✓ / ✓ |
| platform.* (settings, merchants.govern, billing.configure, fee_rules.manage, audit.view) | — super_admin only | | | | | | |

`EnsurePermission:{key}` middleware + `Gate::before` denies anything not in the resolved set (role grants + per-user overrides). Permission changes are audited (high severity) and visible in HR "Permission Preview".

### 10.4 Policies (one per model — examples)

```php
// app/Policies/PaymentRecordPolicy.php
public function validate(User $u, PaymentRecord $p): bool {
    $ctx = app(TenantContext::class);
    return $ctx->merchantId() === $p->merchant_id
        && $ctx->hasBranch($p->branch_id)
        && $ctx->can('payments.validate')
        && ! app(PeriodLockService::class)->isLocked($p->branch_id, $p->paid_at);
}
// InvoicePolicy::voidPaid → requires invoices.void_paid AND an approval record
// QueueEntryPolicy::transfer → branch_manager + same branch + reason present
// ClientPolicy::view for personnel → exists service_session linking personnel↔client
```

Ownership transfer (merchant admin → another user) and merchant deactivation are Merchant Admin actions with confirmation + audit (critical severity); branch-user deletion gated by branch fee-debt zero check.

---

## 11. API Design

### 11.1 Conventions

- Base `/api/v1`; nouns, kebab-case; ULIDs in URLs; JSON only.
- Verbs: GET list/show, POST create + state-transition sub-resources (e.g. `POST /payments/{ulid}/validate`), PATCH partial update, DELETE only where business-deletable (almost nowhere — financial records never).
- Every collection paginated (`per_page` ≤ 100, default 25), filterable via whitelisted `filter[...]` params, sortable via whitelisted `sort` (validated; unknown → 422).
- Idempotency: mutating financial endpoints accept `Idempotency-Key` header (Redis-stored response replay, 24h) — protects double-submits from flaky SME networks.

### 11.2 Route groups (full launch surface; middleware shown once per group)

```text
// public
POST   /auth/magic-link                     throttle:magic-link-request
POST   /auth/magic-link/verify              throttle:magic-link-verify
POST   /auth/mfa/verify
POST   /merchant-registration/self-register throttle:registration
POST   /staff-invitations/{token}/accept    throttle:invitation-accept
GET    /health  /health/deep                (deep: db+redis+search+storage probes)

// authenticated
GET    /me        POST /auth/logout
group auth:sanctum + tenant + EnsureMerchantActive:
  // onboarding (pending_setup merchants allowed here only)
  POST /merchant-registration/first-time-setup        (tier, profile, branch, invites)
  // merchant admin
  GET|PATCH /merchants/profile     PATCH /merchants/service-fee-tier
  GET|POST  /branches              GET /branches/{branch}
  GET  /merchant-users             POST /merchant-users/initial-invites
  POST /merchant-users/{u}/suspend|activate|deactivate   DELETE /merchant-users/{u}
  GET  /reports/merchant/...       GET /billing/platform-fees  /billing/statement
  POST /billing/platform-fee-disputes
group + EnsureBranchScope (prefix /branches/{branch}):
  PATCH /profile      GET|PUT /operating-hours    GET|POST|DELETE /calendar-exceptions
  POST  /day/open|pause|close|reopen              GET /day/current
  GET|POST /cash-ups  POST /cash-ups/{c}/submit|approve|reject
  GET|POST|PATCH /services   POST /services/{s}/archive
  GET|POST|PATCH /clients    POST /clients/{c}/merge        GET /clients/{c}/history
  GET|POST /appointments     POST /appointments/{a}/check-in|reschedule|cancel|no-show
  GET /queue  POST /queue/walk-ins   POST /queue/{q}/assign|start|complete|cancel|no-show
  POST /queue/{q}/transfer   PATCH /queue/configuration   POST /queue/open|close
  GET|POST /service-sessions POST /service-sessions/{s}/complete|cancel
  GET|POST /invoices         POST /invoices/{i}/void  /invoices/{i}/adjustments
  GET|POST /payments         POST /payments/{p}/validate|reject|dispute
  POST /payments/{p}/duplicate-override
  GET /receipts   GET /receipts/{r}/download   POST /receipts/{r}/reissue|reverse
  GET|POST /refunds          POST /refunds/{r}/approve|reject
  GET|POST /disputes         PATCH /disputes/{d}
  GET /reports/branch/...    GET /personnel (read-only HR state)
group HR (prefix /hr, branch-scoped):
  GET /dashboard   GET|POST|PATCH /staff   POST /staff/{s}/suspend|activate|deactivate
  GET|POST /staff-invitations   POST /staff-invitations/{i}/resend|revoke
  GET|PUT /staff/{s}/service-eligibility    GET|PUT /staff/{s}/availability
  GET|POST|PATCH /commission-rules          GET /staff/{s}/history
  GET /staff/{s}/permission-preview         POST /exports/staff-roster
group Finance (prefix /finance, branch-scoped):
  GET /overview /task-inbox /audit-activity
  GET /commission-liabilities    GET /financial-periods  POST /financial-periods/lock|reopen
  GET|POST /exports              GET /exports/{e}/download (signed)
group Personnel (prefix /personnel — own scope enforced in every query):
  GET /dashboard /queue /appointments /sessions /commissions /clients (served-only)
group Audit (prefix /audit, read-only):
  GET /dashboard /logs (search+filters)  GET|POST|PATCH /flagged-events
  POST /exports/audit-report (permission-gated)
group Platform (prefix /platform, super_admin only):
  GET|PATCH /settings   GET /merchants  POST /merchants/{m}/suspend|reactivate
  GET|PATCH /billing/settings   GET|PATCH /fee-rules/preferred-personnel
  GET /billing/ledger /reports/...  GET /audit-logs
GET|PATCH /notifications (per-user inbox, mark-read)
GET /search?q=&types=  (tenant+branch-filtered Meilisearch proxy)
```

There is deliberately **no** route shaped like `/personnel/clients/export` or any contact export under personnel scope (§3 rule 17).

### 11.3 Validation

One Form Request per mutating endpoint; `authorize()` delegates to policy. Method-specific payment reference rules expressed as conditional rules, e.g.:

```php
'reference' => [Rule::requiredIf(fn() => in_array($this->method,
    ['mpesa','bank_transfer','card_terminal','voucher'])), 'string','max:120'],
'legs'      => ['required_if:method,split_leg.parent','array','min:2'],
'legs.*.method' => ['required', new EnumRule(PaymentMethod::class)],
```

### 11.4 Resources

JsonResources expose ULIDs as `id`, ISO-8601 timestamps, money as `{ amount: int, currency: 'KES', formatted: 'KES 535.00' }`, and embed `can: { validate: bool, void: bool, … }` ability maps for UX-only rendering. Sensitive fields (client phone for masked viewers) pass through `MaskedField::for($viewer)`.

### 11.5 Errors

```json
{ "error": { "code": "duplicate_reference", "message": "This M-Pesa code was already recorded on invoice KIL-INV-000119.",
             "fields": { "reference": ["Duplicate within this merchant."] },
             "meta": { "matched_payment_id": "01J…", "scope": "same_branch" } } }
```
HTTP mapping: 401 unauthenticated · 403 permission_denied / merchant_suspended / no_tenant_context · 404 not_found (incl. cross-tenant) · 409 duplicate_reference, duplicate_client, branch_closure_blocked, double_booking · 422 validation_failed, invalid_state_transition · 423 period_locked · 429 rate_limited. 5xx return generic `internal_error` + correlation id; details only to Sentry/logs.

### 11.6 API logging

Request log (structured): correlation id, route, user ulid, merchant ulid, status, duration. Never bodies for auth/payment endpoints. Unauthorized attempts additionally produce audit rows (§22).

---

## 12. UI/UX Design System

Implements the Brand Identity document directly as Tailwind theme tokens.

### 12.1 Tokens (`tailwind.config.ts` + CSS variables for theming)

```css
:root {
  --color-primary: #F97316;        /* Savannah Orange — CTAs, active states */
  --color-sun: #FBBF24;            /* badges, soft highlights */
  --color-success: #2E7D32; --color-growth: #3F7D20;
  --color-brand-deep: #4A2208;     /* headings, footer, admin anchor */
  --color-accent: #007C78;         /* Service Teal — module accents, trust */
  --color-warning: #F59E0B; --color-error: #DC2626; --color-info: #0284C7;
  --color-text: #1F2933; --color-text-muted: #6B7280;
  --color-border: #E5E7EB; --color-surface: #FFFFFF;
  --color-surface-alt: #F3F4F6; --color-bg: #F9FAFB; --color-cream: #FFF8E7;
  --radius-card: 12px; --radius-control: 8px;
  --shadow-card: 0 1px 3px rgb(0 0 0 / .08);
}
```
Usage ratio guard (brand §11): neutrals dominate; orange reserved for primary actions; teal for active module accents; never multiple vivid colors inside data tables.

### 12.2 Typography
Inter for all product UI (body 400/500, buttons 600, sentence case — "Create invoice", never "CREATE INVOICE"); Manrope 700–800 for H1/H2 page titles. Scale: H1 24/32 (mobile 20/28), H2 18/28, H3 16/24, body 14/22, caption 12/16. Loaded via self-hosted `@fontsource` (no third-party font CDN → privacy + CSP).

### 12.3 Component inventory (in `components/ui/`, each with light+dark, all states, a11y baked in)
`SvButton` (primary orange / secondary outline / ghost / destructive; loading + disabled states; min 44px touch), `SvInput/SvSelect/SvTextarea/SvPhoneInput/SvMoneyInput` (label always rendered; placeholder never replaces label; error text `aria-describedby`-linked; required marker `*` + `aria-required`), `SvCard`, `SvTable` (desktop table → mobile stacked cards ≤767px), `SvBadge` (Paid, Pending validation, Partially paid, Validated, Rejected, Disputed, Voided, Flagged, Locked, Suspended… — color + icon + text, never color alone), `SvModal` (focus trap, `aria-modal`, Esc, restore focus), `SvDrawer`, `SvToast` (`role="status"`, auto-dismiss ≥5s, pause on hover), `SvTabs`, `SvSidebarNav`, `SvHeader`, `SvProfileMenu`, `SvBranchSwitcher`, `SvEmptyState` (warm illustration, primary action), `SvStateBoundary`, `SvConfirmDialog` (typed-confirmation for critical actions: void paid invoice, reopen period, deactivate user), `SvStat` dashboard card, `SvQueueBoard`, `SvTimeline` (audit/history).

Navigation per role follows the scope's "Final Production-Launch Navigation" lists verbatim (Branch: Overview→Settings 18 items; Finance: Overview→Settings 17 items; etc.) — the IDE agent must not rename or drop items.

---

## 13. Responsive Layout Strategy

Breakpoints (CSS only — Tailwind screens overridden to match scope exactly):
```js
screens: { md: '768px', lg: '1025px' }   // mobile <768, tablet 768–1024, desktop ≥1025
```
Rules: fluid layouts (flex/grid + minmax), no fixed pixel page widths, no horizontal scroll (CI Playwright asserts `document.scrollingElement.scrollWidth <= innerWidth` at 360/768/1280 on every critical page), no JS layout decisions, real-time adaptation on resize, ≥44px touch targets, typography scales via the §12 scale.

Per-area strategy:
| Area | Desktop ≥1025 | Tablet 768–1024 | Mobile ≤767 |
|---|---|---|---|
| Dashboard stat cards | 4-col grid | 2-col | 1-col stack |
| Sidebar | fixed 260px, collapsible to icon rail | overlay drawer, hamburger | overlay drawer |
| Header | full: search, branch switcher, profile | condensed; search collapses to icon | minimal; profile + menu |
| Data tables | full table, sticky header | priority columns + row expand | stacked cards (label/value pairs); actions in row kebab menu |
| Forms | 2-col field grid | 1–2 col | single column; sticky submit bar |
| Queue board | kanban columns (waiting/assigned/in-service) | horizontal scroll-snap columns (the one sanctioned horizontal scroller, with visible affordance) | single-column with status filter tabs — Front Office mobile-critical |
| Modals | centered, max-w-lg | centered | full-screen sheet |
| Settings/billing/team pages | side tab nav + content | top tabs | accordion sections |
| Personnel app | n/a focus | optimized | **mobile-first** (scope §3.7): big touch rows, bottom nav |

---

## 14. Dark Mode Strategy

- Class strategy (`html.dark`), light default. Toggle in profile menu (sun/moon, `aria-pressed`).
- Persistence: authenticated users → `PATCH /me/preferences {theme}` stored in `users.preferences jsonb`; mirrored to `localStorage` for instant boot.
- **Flash prevention:** inline `<head>` script (before CSS) reads localStorage → sets `dark` class pre-paint.
- Dark tokens (same CSS variables, `.dark` overrides): bg `#111827`, surface `#1F2933`, surface-alt `#273340`, text `#F3F4F6`, muted `#9CA3AF`, border `#374151` (borders stay visible — never removed), primary stays `#F97316` (AA on dark), accent lightened to `#14B8A6`, error `#F87171`, success `#4ADE80`, focus ring `#FDBA74` 2px (visible in both themes). Validation errors keep icon+text, not color alone.
- Testing: Playwright runs the critical-flow suite in both themes; axe contrast checks both; visual snapshots per theme.

---

## 15. Accessibility Strategy

WCAG 2.1 AA-aligned. Implementation requirements (binding):
1. Full keyboard support: logical tab order, skip-to-content link, roving tabindex in menus/queue board, Esc closes overlays.
2. Visible focus: 2px ring tokens both themes; never `outline: none` without replacement.
3. Contrast AA: tokens pre-verified; axe-core CI gate on auth, dashboards, walk-in flow, payment validation, receipts, settings.
4. Every input has a `<label for>`; placeholders are hints only; errors linked via `aria-describedby` + `aria-invalid`; error summary with anchor links on long forms.
5. Buttons/links have accessible names (icon buttons get `aria-label`).
6. Touch targets ≥44×44.
7. Zoom respected (§3 rule 8); layout works at 200% zoom.
8. `prefers-reduced-motion: reduce` disables non-essential transitions (queue card movement falls back to instant reposition).
9. Screen readers: modals `role="dialog" aria-modal`, focus trap + restore; toasts `role="status"`; nav landmarks (`header/nav/main/aside`); live queue updates announced via polite `aria-live` region; tables with `<caption>` + `scope` headers; badge meaning conveyed in text.
10. Verification steps per release: keyboard-only walkthrough of the five critical flows; NVDA + VoiceOver smoke on walk-in→receipt; axe CI; manual zoom check.

---

## 16. Forms and Input Behavior Strategy

- State model: each form uses a composable `useForm<T>` (typed values, touched, errors, submitting, dirty). Server 422 `fields` map merges into client errors keyed by input name — exact mapping contract with §11.5.
- Duplicate-submit prevention: submit disabled + spinner while in-flight; Idempotency-Key on financial posts; navigation-away guard on dirty forms.
- States styled via attributes only (`:disabled`, `[aria-invalid="true"]`, `[data-state]`) — CSS never toggles behavior (§3 rule 12).
- Long forms (merchant first-time setup, staff creation) are sectioned steppers with per-step validation and review step.
- Sensitive fields (payment reference, phone) styled normally — not overemphasized; masked display where viewer lacks permission, with "permission required" badge instead of the value.
- Required indicators: asterisk + "Required" in helper text on first error.
- Success: inline toast + optimistic-free re-fetch (no optimistic writes on financial data).

---

## 17. User Profile and Account UI Strategy

- Header right cluster: `SvProfileMenu` = avatar (or initials) + display name + role chip as **one** button (single focus target, cursor pointer, hover surface tint, visible focus ring).
- Click/Enter opens an anchored preview card (popper positioning, `flip+shift` so it never clips viewport or covers the primary action bar): photo, name, role, merchant name, active branch, links → My profile, Preferences (theme), Notifications, Sign out. Esc/outside-click closes; focus restored.
- `SvBranchSwitcher` adjacent for multi-branch users (listbox semantics, persists via `/me/active-branch`).
- Profile page: photo upload (S3 private, signed display URL), display name, phone (uniqueness validated), theme, notification preferences. Email immutable (it is the credential — change = HR/admin re-invite workflow, audited).
- CSS provides styling only; open/close handled by component state; implementation steps: build menu → keyboard tests → anchored card → branch switcher → profile page → Playwright coverage of hover/focus/clip behavior at all breakpoints.

---

## 18. Billing and Plan Enforcement Strategy (Citrus Billing Engine)

### 18.1 Fee accrual
On `PaymentValidated` (merchant-client invoice fully validated → status paid), queued `AccruePlatformFee` writes a `platform_fee_ledger` `fee_accrual` row: amount = platform base fee (from versioned `platform_billing_settings`), `calculation` jsonb = `{base_fee, settings_version, invoice_ulid, tier, preferred_fee_treatment}`. **Tier never changes this amount** — tiers only changed the merchant-client invoice via `TierPricingCalculator` at invoice issue time (customer_centric +0; split_tier +50% of base fee as `tier_surcharge` line; business_centric +100%). Preferred-personnel fee inclusion in platform fee follows the platform setting (`included|exempt`).

### 18.2 Cycle invoicing & enforcement
Scheduler (`billing:run-cycle`, daily, idempotent): on each merchant's cycle boundary, roll un-invoiced accruals into a `platform_fee_invoices` row (type platform_fee, numbered `CIT-INV-…`), email statement PDF, set `due_at = cycle_end + grace_days`. Super Admin records merchant payments (`fee_payment` ledger entries; updates `merchants.last_fee_payment_at`). Overdue past `suspension_after_days` → `SuspensionTriggerService` flags, notifies (Platform fee overdue — Critical), and suspends merchant per platform policy (automated governance, scope §3.1). Branch-level debt view backs the branch-user deletion gate. Merchant-facing: fee ledger, calculation explanation, current cycle, due date, overdue status, downloadable statement, dispute workflow (`platform_fee_disputes`). Inactivity sweep (`merchants:inactivity-sweep`, daily): no fee payment 3 months → suspend + warning emails at 60/75/90 days; 6 months → anonymize-and-archive deletion per AS-10, fully audited.

### 18.3 Tests (minimum)
Tier pricing per scope's KES 500/70 table (assert 500/535/570 client totals and constant 70 liability), accrual idempotency, cycle rollup, overdue→suspension, branch debt gate, dispute flow, statement PDF contents.

---

## 19. File Upload and Storage Strategy

| Upload | Types | Max | Notes |
|---|---|---|---|
| Merchant logo | png, jpg, webp, svg→rasterized | 2 MB | re-encoded via Intervention Image (strips metadata/active content), stored private, rendered into invoice/receipt PDFs |
| Staff/client photo | png, jpg, webp | 2 MB | same pipeline |
| Dispute evidence | pdf, png, jpg | 10 MB | private, finance-permission download |
| Generated PDFs/exports | system-produced | — | private, signed URLs (15-min expiry), download-logged |

Controls: server-side MIME sniffing (`finfo`) + extension whitelist (both must agree), size limits, image re-encode, **ClamAV container scan** for user uploads (quarantine on hit, audit critical), randomized storage keys `merchants/{ulid}/...` (never user-supplied names), no public disk for tenant files, authorization before issuing upload and before signing any download, orphan cleanup job (weekly: media rows without owners > 48h old → delete object + row), audit log for sensitive file downloads (receipts, exports, evidence). Abuse tests: oversized, spoofed MIME (php-as-png), traversal names, cross-tenant download attempts, expired signed URL reuse.

---

## 20. Queue, Jobs, Notifications, and Scheduled Task Strategy

- Horizon queues: `mail` (Magic Links priority), `pdf`, `billing`, `search`, `notifications`, `default`; per-queue workers, `tries=3`, exponential backoff, `failed_jobs` monitored + Sentry alert; all jobs `TenantAwareJob` (§8.3); financial jobs idempotent via natural keys (e.g., one accrual per invoice — unique index `(source_invoice_id, entry_type)`).
- Notifications: Laravel notifications with `mail` channel + `NotificationLogChannel` (writes notification_logs) + database channel for the in-app inbox and the **Finance task inbox** (priority field per scope's table — Critical items pinned). All 20 scope notification types implemented as classes under `Domain/Notifications`; channel abstraction ready for SMS/WhatsApp.
- Scheduler: `billing:run-cycle` daily 01:00 EAT; `reports:day-close` + `reports:cash-up` per-branch on day close event (job) with 23:55 EAT fallback sweep; `merchants:inactivity-sweep` daily; `audit:verify-chain` nightly; `tokens:purge`, `exports:expire`, `media:orphan-cleanup`, `backup:run` nightly; `search:health` every 5 min. Every command idempotent + `withoutOverlapping` + `onOneServer`.

---

## 21. Search Strategy

Meilisearch via Laravel Scout. Indexes: `clients` (name, phone, email), `staff` (name, email, phone, role, status, eligibility), `invoices` (number, client name), `receipts` (number), `appointments` (reference, client). Every document includes `merchant_id`, `branch_id`; **every** query is executed server-side (`GET /api/v1/search`) with mandatory `filter: merchant_id = X AND branch_id IN [...]` injected from TenantContext — the SPA never holds a search API key. Front Office speed search (phone, name, invoice no, receipt no, queue position) targets <100ms: phone/number lookups hit Postgres indexes directly; fuzzy name goes to Meilisearch. Index sync via queued Scout jobs (tenant-aware); nightly drift check (`scout:verify-counts`). Isolation test: merchant A's query can never return B's documents even with crafted filter input (filters are server-built, user input is the query string only, escaped).

---

## 22. Observability and Audit Logging Strategy

### 22.1 Operational observability
- Logs: Monolog JSON to stdout (Docker) → centralized (e.g., Grafana Loki or CloudWatch). Fields: ts, level, env, correlation_id, user_ulid, merchant_ulid, route, message, context. Redaction processor (§3 rule 6).
- Errors: Sentry (Laravel + Vue SDKs), release tagging, PII scrubbing on.
- Performance: Sentry traces + `pg_stat_statements`; slow query log >200ms reviewed weekly; endpoint p95 dashboards; alert thresholds: error rate >1%/5min, queue wait >60s, failed jobs >0 on billing/pdf queues, health check fail ×3, audit chain break (page immediately), disk >80%.
- Queue monitoring: Horizon dashboard (platform-staff-gated route), metrics exported.
- Health: `/health` (liveness) and `/health/deep` (db, redis, meilisearch, S3, mail transport ping) for uptime monitors (e.g., Better Uptime/Pingdom).

### 22.2 Audited events (complete list = scope §5.18; mechanism = `AuditRecorder`)
Every event in scope §5.18 is implemented as a constant in `Enums/AuditAction.php` (merchant self-registration → platform fee setting changes — ~50 actions). Recorder API:
```php
AuditRecorder::record(action: AuditAction::PaymentValidated, target: $payment,
    old: $before, new: $after, severity: Severity::High);
```
Captured fields exactly per scope (§7.5 table) incl. ip/user_agent from request context (or `system` for jobs), hash chain per §7.5. High/critical by default: role changes, payment validation changes, receipt generation, voids, contact-access attempts, branch access changes, period locks/reopens, fee setting changes, login abuse. Login + security events (link denied/failed/success, unauthorized access, abuse suspected) flow into the same store. Merchant Audit UI reads with masking; Super Admin platform audit reads the platform chain + governance events. Flagging workflow per `flagged_audit_events`. Before/after values recorded only for sensitive state changes and never include tokens/secrets.

---

## 23. Performance and Scalability Plan

Likely bottlenecks → mitigations (each with a measurable target):

| Bottleneck (evidence) | Mitigation | Target |
|---|---|---|
| Queue board polling (busiest screen) | `GET /branches/{b}/queue` cached 5s in Redis, keyed per branch, invalidated on queue mutation events; ETag/304; SPA polls 10s (WebSockets deferred post-launch) | p95 <120ms |
| Dashboard aggregates (today's revenue, counts) | Redis cache 60s per branch/day; invalidate on invoice/payment events; heavy ranges (last 3 months) precomputed nightly into `report_snapshots` | p95 <200ms |
| Invoice/receipt number contention | single-row `FOR UPDATE` on sequence inside the issuing txn (short); per-merchant so no cross-tenant contention | <5ms lock hold |
| Audit volume | monthly partitions, BRIN on created_at, async write via queued recorder for non-financial events (financial audit stays in-txn) | inserts <3ms |
| N+1 queries | `preventLazyLoading` in dev/CI; resource classes use explicit `with()` | CI fails on lazy load |
| PDF generation (day-close, statements, receipts) | dedicated `pdf` queue, Browsershot/Chromium container, throttled concurrency | never on request thread |
| Search indexing bursts | queued, batched | n/a |
| Frontend bundle | route-level code splitting per layout, lazy charts, image optimization, immutable hashed assets behind CDN, gzip/brotli | initial JS <250KB gz |
| Hot rows: platform_fee_ledger balance | balance_after computed in txn with merchant advisory lock; reconciliation job re-verifies sums nightly | drift = 0 |

Capacity assumptions: 5k merchants × 2 branches × 50 invoices/day ≈ 500k invoices/day worst case — comfortably single-Postgres with the above indexes; scale path: read replica for reports → table partitioning of payments/invoices by month if >100M rows.

---

## 24. Security Threat Model

STRIDE-organized; every row has an implemented control and a verifying test.

| Threat | Vector | Control | Verifying test |
|---|---|---|---|
| SQL injection | filters/search params | Eloquent bindings only; whitelisted sort/filter; no raw concat (PHPStan rule) | `Security/SqlInjectionProbeTest` (sqlmap-style payloads → 422/safe) |
| XSS | client names, notes rendered in SPA & PDFs | Vue auto-escaping, no v-html, PDF templates escape, CSP `default-src 'self'` | `Security/XssRenderTest`, CSP header assert |
| CSRF | browser session calls | Sanctum CSRF on all mutating routes, SameSite=Lax | `Security/CsrfTest` |
| Broken access control / IDOR | ULID swapping, role abuse | tenant-scoped bindings (404), policies on every route, route-coverage test, unauthorized-attempt audit | the entire `Feature/Isolation` + `Feature/Security` suites |
| Mass assignment | extra JSON keys | explicit $fillable + FormRequest `validated()` only ever passed to services | `Security/MassAssignmentTest` (inject `merchant_id`, `status`, `validated_by`) |
| Magic link theft/replay | email compromise, link forwarding | 15-min expiry, single-use atomic consume, hashed at rest, bound checks re-run at consume, session regeneration, audit | `Auth/MagicLinkSecurityTest` |
| Session fixation/hijack | — | regenerate on login, secure/httponly/SameSite cookies, idle+absolute timeout, instant revocation on suspension | `Auth/SessionLifecycleTest` |
| Brute force / credential stuffing | link request floods | §9.3 limiters, backoff, abuse audit | `Auth/RateLimitTest` |
| File upload abuse | malware, polyglots | §19 pipeline (sniff+whitelist+re-encode+ClamAV+private) | `Security/UploadAbuseTest` |
| Payment fraud (internal) | fake/duplicate refs, premature receipts, tampered invoices | reference rules, duplicate detection + override audit, receipt-after-validation DB guard, void/adjust approvals, period locks, append-only audit | finance suite (§25) |
| Sensitive data exposure | logs, exports, personnel scope | redaction, masking, export governance, personnel contact-export nonexistence | `Security/LogRedactionTest`, `Finance/ExportGovernanceTest`, `Isolation/PersonnelOwnScopeTest` |
| API abuse / scraping | enumeration | ULIDs, pagination caps, throttles, uniform 404s | `Security/EnumerationTest` |
| Unsafe redirects | verify-page `redirect` param | no user-controlled redirects; SPA routes only from whitelist | `Auth/RedirectTest` |
| Dependency vulns | supply chain | Dependabot + `composer audit` + `npm audit --audit-level=high` in CI (fail on high/critical), lockfiles committed | CI gate |
| Audit tampering | insider | append-only trigger, hash chain, nightly verify, DB role without UPDATE/DELETE on audit_logs | `Audit/ChainTamperTest` |
| SSRF/headers | — | no user-supplied URL fetching at launch; strict security headers at Nginx | header assert test |

---

## 25. Testing Strategy

Stack: Pest (unit/feature/API) on PostgreSQL service container (never SQLite — constraints/partitions must match prod), Vitest + Vue Testing Library (components), Playwright (browser E2E + a11y via axe), Larastan level 8, Pint, ESLint+vue-tsc. Coverage gate: 85% lines on `app/Domain`, 100% of the isolation/security suites green.

Conventions: every module ships positive, negative, **cross-tenant denial**, **permission denial**, and **validation failure** cases (prompt §22). Factory states for every status enum. The scope §13 test table is the canonical checklist; mapping (file names are binding):

| Area | Test file | Key cases (P=positive, N=negative, X=cross-tenant, D=permission denial, V=validation) |
|---|---|---|
| Magic link auth | `Feature/Auth/MagicLinkRequestTest`, `MagicLinkVerifyTest`, `MagicLinkSecurityTest`, `Auth/RateLimitTest` | P issue+consume; N expired/reused/invalidated token; N suspended user link denied; V bad email; throttle 429; no-enumeration (202 always) |
| Self-registration & setup | `Feature/Onboarding/SelfRegistrationTest`, `FirstTimeSetupTest`, `NoPlatformMerchantCreationTest` | P register→tenant→owner; P setup steps incl. auto-branch-select & welcome mails queued; N dashboard APIs blocked while pending_setup; **assert no platform route can create merchants/first admins or demand KYC** |
| Tenant/branch isolation | `Feature/Isolation/*` (one per module: Invoice, Payment, Receipt, Client, Queue, Appointment, Session, Staff, Commission, Report, Export, RouteBindingTest, PersonnelOwnScopeTest, CrossBranchPaymentTest) | X every binding 404s; X list endpoints return only scoped rows; audit row written |
| Roles & permissions | `Feature/Security/PermissionMatrixTest` (data-provider over §10.3), `FinancePermissionTest`, `HrSelfEscalationTest`, `AuditReadOnlyTest`, `AuthorityBoundariesTest` | D every ✗ cell denied; D merchant_admin cannot configure services/commissions/assign personnel; D HR cannot touch other branch / self-escalate; D audit role 403 on every write; D front office cannot validate/issue receipts |
| Staff lifecycle | `Feature/Hr/StaffInvitationTest`, `StaffDuplicateTest`, `StaffSuspensionTest`, `StaffHistoryTest` | P invite/resend/revoke/accept; N duplicate active email & phone blocked; **P suspension kills sessions + magic links immediately and triggers reassignment checks**; history rows complete |
| Branch ops | `Feature/Branch/ProfileTest`, `CalendarTest`, `DayLifecycleTest`, `CashUpTest`, `ClosureProtectionTest`, `NumberingTest` | V required profile fields; P emergency closure blocks queue+appointments; P day statuses + reopen reason; P cash-up expected-vs-recorded + discrepancy note required + approve→lock + PDF queued; N closure blocked on each of the 8 conditions; P invoice/receipt numbers unique under 50-thread concurrency (`NumberingConcurrencyTest`), voided keeps number |
| Catalogue | `Feature/Catalogue/ServiceAuthorityTest`, `ServiceCrudTest` | D merchant_admin 403 on service create; P branch user CRUD |
| Clients | `Feature/Clients/DuplicateClientTest`, `MergeTest`, `ConsentTest` | N same-branch duplicate phone 409; P other-branch allowed; P controlled merge preserves history |
| Queue & sessions | `Feature/Queue/WalkInAtomicityTest`, `TransitionTest`, `PreferredPersonnelTest`, `ReassignmentTest`, `Sessions/DoubleBookingTest` | P walk-in creates client+entry+optional fee atomically (rollback on any failure); N invalid transitions 422; P next-available vs preferred (fee line, lock to personnel, override needs reason+perm, audit); P transfer on unavailability; N eligibility violation blocked; N DB exclusion blocks overlap |
| Appointments | `Feature/Scheduling/CheckInConversionTest`, `ConflictTest`, `ClosureProtectionTest` | P check-in converts to queue without duplicate records (asserts row counts); N double-book 409; N booking into closure 422 |
| Invoices | `Feature/Invoicing/TotalsTest`, `TierPricingTest`, `VoidApprovalTest`, `LogoTest` | P totals/discount/preferred fee lines; P 500/535/570 tier table & constant 70 liability; D/P void unpaid (perm+reason) vs paid (approval); P logo embedded in PDF |
| Payments | `Feature/Payments/RecordingTest`, `ValidationWorkflowTest`, `ReferenceRulesTest`, `DuplicateReferenceTest`, `PartialSplitTest` | V method-specific reference required; P all validation statuses + event history; N duplicate mpesa ref 409, override needs perm+reason+audit; P legs validate independently, invoice paid only when validated total == invoice total |
| Receipts | `Feature/Receipts/GenerationGuardTest`, `AutoIssueTest`, `ReissueReversalTest`, `DownloadLogTest` | N receipt before validation blocked at service **and** DB trigger; P auto-issue on validation; P reissue references original, reversal preserves record; P downloads logged |
| Refunds & disputes | `Feature/Payments/ExternalRefundTest`, `FinanceDisputeTest` | N amount > validated rejected; P approval flow + invoice impact; P dispute statuses/evidence |
| Periods | `Feature/FinanceOps/PeriodLockTest` | P lock on cash-up approval; N writes into locked period 423; P reopen needs reason+approval+audit |
| Commissions | `Feature/Commissions/RuleAuthorityTest`, `CalculationTest`, `ReversalTest` | D only HR sets rules; P precedence; P earned only on validation; P reversal on void/refund |
| Billing engine | `Feature/Billing/AccrualTest`, `CycleTest`, `SuspensionTriggerTest`, `BranchDebtGateTest`, `FeeDisputeTest`, `InactivitySweepTest` | per §18.3 |
| Exports | `Feature/Finance/ExportGovernanceTest`, `Hr/RosterExportTest`, `Audit/AuditExportTest` | D permission, V reason required, P signed URL expiry + download count + masking + audit; N roster export contains no client/payment columns |
| Audit | `Feature/Audit/AppendOnlyTest`, `ChainTamperTest`, `SeverityTest`, `FlaggedEventTest`, `UnauthorizedAttemptLoggingTest`, `MaskingTest` | N UPDATE/DELETE raises; P chain verifies, tamper detected; P all §5.18 actions write rows (data-provider) |
| Personnel scope | `Feature/Isolation/PersonnelOwnScopeTest`, `Security/ContactExportAbsenceTest` | X own-only on all five surfaces; **404 on every export-shaped path + audit row** |
| API contract | `Feature/Api/PaginationTest`, `ErrorEnvelopeTest`, `Security/RouteCoverageTest` | every collection paginates; envelope shape; every route authenticated+authorized |
| Frontend units | `spa/tests/**` Vitest | money/date utils, useForm, permissionService, StateBoundary states, SvTable card collapse |
| E2E (Playwright) | `tests/Browser/` | journeys: register→setup→dashboard; invite→accept→magic login; walk-in→assign→serve→invoice→record payment→finance validate→receipt download; preferred-personnel with fee; day open→close→cash-up→PDF; both themes; 360/768/1280; axe on each |

---

## 26. Deployment and CI/CD Strategy

### 26.1 Docker
`docker/` contains: `php.Dockerfile` (php:8.3-fpm-alpine + extensions pdo_pgsql, redis, intl, gd, opcache (preload on), non-root user), `nginx.Dockerfile` (config + built SPA assets), `docker-compose.yml` (dev: app, nginx, postgres:16, redis:7, meilisearch, minio, mailpit, clamav, horizon, scheduler), `docker-compose.prod.yml` (app, nginx, horizon, scheduler; managed Postgres/Redis/S3 recommended). One image serves app/horizon/scheduler with different commands. `.dockerignore` excludes .env, node_modules, tests artifacts.

### 26.2 Pipeline (GitHub Actions)
```text
on PR:    pint --test → larastan → eslint+vue-tsc → gitleaks
          → pest (Postgres+Redis services, parallel) → vitest
          → composer audit + npm audit (fail high) → build SPA → playwright (critical tag)
on main:  all above → build+push images (sha tag) → deploy staging
          → staging smoke (health/deep, login E2E) → manual approval
          → deploy production
deploy:   pull images → php artisan down --render=maintenance (only if breaking)
          → migrate --force (expand/contract pattern: additive first; destructive
            changes ship one release after code stops using them)
          → config:cache route:cache view:cache event:cache
          → horizon:terminate (graceful drain) → restart containers → health gate
rollback: redeploy previous image tag; migrations are backward-compatible by the
          expand/contract rule, so prior code runs on the new schema; if a migration
          itself must be reverted, restore from the pre-deploy snapshot + replay plan
```
Secrets: GitHub Environments + host secret store (SSM/Doppler); never in images or repo. Backups: nightly `pg_dump` (custom format) + WAL archiving where managed-PG offers PITR; encrypted to a separate bucket; **monthly restore drill into staging is a scheduled task with a checklist**. HTTPS: Let's Encrypt/ACM at the edge, HSTS preload. Caching in prod: opcache + config/route/view caches; `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=database`, `APP_DEBUG=false` verified by a boot-time assertion that aborts if debug is on in production.

---

## 27. Step-by-Step Development Roadmap

Phases are sequential; later phases assume earlier acceptance criteria hold. **Common to every phase:** follow §28 execution rules; run `composer pint && composer stan && php artisan test --parallel` plus the phase's named tests; commit only green; update `docs/CHANGELOG.md`. **Common rollback:** every phase lands as one reviewed PR; rollback = revert the PR; migrations within unreleased phases may be rolled back with `migrate:rollback` (never after release — use expand/contract).

### Phase 1 — Project initialization
**Objective:** Repo skeleton, tooling, quality gates. **Files:** `composer.json`, `package.json`, `pint.json`, `phpstan.neon` (level 8 + custom rules NoWithoutTenancyOutsidePlatform, NoRawSqlConcat), `.editorconfig`, `eslint.config.js`, `tsconfig.json`, `.github/workflows/ci.yml` (lint+stan+test skeleton), `docs/`. **Tasks:** `laravel new servana` (11.x); scaffold Vue 3+TS+Vite in `resources/spa`; Tailwind with §12 tokens and §13 screens; install Pest, Larastan, Pint, gitleaks hook. **Tests:** `tests/Feature/SmokeTest` (app boots, /health 200). **Commands:** `composer install && npm ci && npm run build && php artisan test`. **Verification:** CI green on first PR; gitleaks passes. **Acceptance:** clean install reproducible from README in <15 min. **Risks:** version drift → lockfiles committed.

### Phase 2 — Docker & environment setup
**Objective:** Dev parity with prod per §26.1. **Files:** `docker/*`, `docker-compose.yml`, `.env.example` (every var documented; no real secrets), `Makefile` (`make up/test/fresh`). **Tasks:** all dev services incl. mailpit, minio, clamav; healthchecks; non-root containers. **Security:** verify `.env` ignored; secrets only via env. **Tests:** CI switches to compose-equivalent service containers (Postgres 16, Redis). **Verification:** `make up && make fresh && make test` green; Mailpit receives a test mail; MinIO bucket reachable. **Acceptance:** new dev onboards with one command. **Risk:** ClamAV memory in dev → optional profile flag.

### Phase 3 — Laravel backend foundation
**Objective:** §5 skeleton + cross-cutting infrastructure. **Files:** Domain folders, `Support/Money.php`, `Enums/*`, error envelope handler (`bootstrap/app.php` exception renderer per §11.5), correlation-id middleware, structured logging config + redaction processor, named rate limiters (§9.3), Sentry. **DB:** framework tables (sessions, jobs, failed_jobs, job_batches, cache). **Tests:** `Unit/MoneyTest`, `Feature/Api/ErrorEnvelopeTest`, `Security/LogRedactionTest`. **Verification:** forced exception returns envelope + appears in Sentry (staging DSN); redaction proven on a synthetic token. **Acceptance:** all green; stan level 8 passes.

### Phase 4 — Frontend foundation
**Objective:** §6 skeleton + §12 design system core. **Files:** layouts (8), router with guards (stubbed), stores, `apiClient.ts`, `ui/` core components (SvButton, inputs, SvCard, SvModal, SvToast, SvStateBoundary, SvEmptyState), theme tokens light+dark, head theme script. **Tests:** Vitest for apiClient error mapping, useForm, StateBoundary; Playwright smoke (app shell at 3 breakpoints, no horizontal scroll). **Acceptance:** Storybook-style demo page renders all components in both themes; axe clean.

### Phase 5 — Authentication (Magic Link + sessions)
**Objective:** §9 fully. **Files:** `Domain/Auth/*`, `magic_login_tokens` migration, `MagicLinkController`, `MfaController`, mail templates (brand voice), SPA pages `auth/Login.vue`, `auth/CheckEmail.vue`, `auth/Verify.vue`, `/me` endpoint + authStore bootstrap. **Security:** all seven §2.3 checks; hashing; atomic consume; session regeneration; limiters; uniform 202. **Tests:** the four auth files in §25 + `Auth/SessionLifecycleTest`. **Commands:** `php artisan test --group=auth`. **Verification:** manual: request link in Mailpit, login, idle-timeout logout; API examples captured in `docs/proof/phase5.md` (202, 422 expired, 429). **Acceptance:** denial matrix (each failed check → no email + audit row) proven by tests. **Risk:** mail deliverability → SPF/DKIM checklist in docs.

### Phase 6 — Account & tenant model (merchants, self-registration, first-time setup)
**Objective:** Scope §3.2/§5.1. **DB:** users, merchants, merchant_profiles, merchant_users + seeders. **Backend:** `RegisterMerchant` action (creates user+merchant pending_setup+owner membership, transactional), `CompleteFirstTimeSetup` action (tier, profile, ≥1 branch, initial branch_manager+hr invites with auto-branch-select, welcome mails, flips status→active, redirect signal), `ResolveTenantContext`, `EnsureMerchantActive` (pending_setup → only setup endpoints). **Frontend:** registration page, 4-step setup wizard (§16 stepper), merchant dashboard shell. **Tests:** `Onboarding/*` incl. `NoPlatformMerchantCreationTest`. **Verification:** DB rows shown (merchant, membership role=merchant_admin), mails in Mailpit, pending_setup API block proven. **Acceptance:** scope first-time-setup steps 1–7 all enforced server-side; Super Admin cannot create merchants (no route exists — route list diff attached as proof).

### Phase 7 — Branches, memberships, invitations
**Objective:** Branch entity+scope (A2), staff invitations, lifecycle. **DB:** merchant_branches, branch_user_assignments, staff_invitations, staff_profiles, staff_history. **Backend:** branch CRUD (admin-only create), `EnsureBranchScope`, invitation accept flow (token → user+membership+assignment+staff_profile, pending→active), `StaffLifecycleService` (suspend/deactivate = sessions+tokens revoked + reassignment-check event), resend/revoke. **Frontend:** branch list/create (admin), invitation accept page, staff status badges. **Security:** branch-debt gate stub (returns 0 until Phase 16, interface fixed now). **Tests:** `Hr/StaffInvitationTest`, `StaffSuspensionTest`, `Isolation/RouteBindingTest` first models. **Verification:** suspend a logged-in test user → their next request 401s (browser test proof). **Acceptance:** duplicate active email/phone blocked (DB partial uniques demonstrated with attempted insert).

### Phase 8 — Roles & permissions
**Objective:** §10 registry, policies, middleware. **DB:** roles, permissions, role_permission_assignments, merchant_user_permission_overrides; `PermissionSeeder` = §10.3 matrix. **Backend:** TenantContext permission resolution (cached per request), `EnsurePermission`, policies for existing models, HR permission-preview endpoint. **Tests:** `PermissionMatrixTest` (data provider iterates every cell), `HrSelfEscalationTest`, `AuditReadOnlyTest` (skeleton), `AuthorityBoundariesTest`. **Verification:** matrix test output table committed to `docs/proof/phase8-matrix.txt`. **Acceptance:** zero matrix mismatches; permission changes audited (depends on Phase 19 stub — `AuditRecorder` interface introduced here with a temporary table-backed minimal implementation to avoid retrofitting).
*(Note: implement the real `audit_logs` schema here, full event coverage matures in Phase 19 — auditing must exist before financial phases.)*

### Phase 9 — Tenant-scoped data access hardening
**Objective:** §8 complete. **Backend:** BelongsToMerchant/BelongsToBranch traits applied to all models, scoped route binding, `LogUnauthorizedAttempt`, `TenantAwareJob`, PHPStan tenancy rule active. **Tests:** entire `Feature/Isolation` suite for existing models incl. `Unit/TenantAwareJobTest`, parameterized `RouteBindingTest`. **Verification:** demonstrate denied cases of §8.4 with recorded API transcripts in `docs/proof/phase9.md`. **Acceptance:** every §8.4 row green; stan rule blocks a deliberate violation (shown then removed).

### Phase 10 — API foundation
**Objective:** §11 conventions across the board. **Backend:** pagination/filter/sort traits, Idempotency-Key middleware, resources with `can` maps, `RouteCoverageTest`, OpenAPI generation (`scribe` or `l5-swagger`) published to `docs/api`. **Tests:** `Api/PaginationTest`, `ErrorEnvelopeTest`, `Security/RouteCoverageTest`. **Acceptance:** route coverage test enumerates all routes and passes; OpenAPI doc builds in CI.

### Phase 11 — UI layout foundation
**Objective:** Role layouts + navigation per scope nav lists; profile menu (§17); branch switcher. **Frontend:** all 8 layouts wired to router guards; SvSidebarNav with role nav configs (verbatim scope lists); SvProfileMenu + preview card; suspended/no-access state pages. **Tests:** Playwright nav per role (seeded users), keyboard menu tests, clip/overflow checks. **Acceptance:** each role lands on correct home; nav items match scope lists exactly (snapshot test of nav config vs fixture).

### Phase 12 — Responsive design pass
**Objective:** §13 strategies on all existing screens; SvTable card-collapse; queue board scaffolding responsive. **Tests:** Playwright matrix 360/768/1280 + scrollWidth assertions + touch-target audit (bounding boxes ≥44). **Acceptance:** zero horizontal scroll, zero overlap on matrix run.

### Phase 13 — Dark mode
**Objective:** §14 complete. **Tasks:** dark token sheet, toggle, `/me/preferences`, head script, both-theme Playwright + axe. **Acceptance:** contrast AA both themes on critical pages; no flash (Playwright asserts class present before first paint via init script check).

### Phase 14 — Accessibility foundation
**Objective:** §15 items 1–9 retrofitted and gated. **Tasks:** skip link, focus management audit, aria-live regions, error-summary pattern, reduced-motion CSS. **Tests:** axe CI gate turned blocking; keyboard E2E for login + setup wizard. **Acceptance:** axe: 0 serious/critical on gated pages.

### Phase 15 — HR, catalogue, clients (operational data layer)
**Objective:** Scope §3.4/§5.4/§5.5/§5.6. **DB:** service_categories, services, personnel_service_eligibilities, personnel_availability_schedules, personnel_unavailabilities, clients, client_consents. **Backend:** HR module endpoints (roster, search, eligibility, availability, history, permission preview, roster export — roster only), service catalogue (branch authority), DuplicateClientGuard + merge workflow. **Frontend:** HR dashboard/roster/staff editor, services pages, client pages with duplicate-warning modal. **Tests:** `Catalogue/ServiceAuthorityTest`, `Clients/DuplicateClientTest`, `MergeTest`, `Hr/RosterExportTest` (no client/payment columns), availability tests. **Acceptance:** merchant_admin 403 on service config; HR other-branch 404; duplicate phone same branch 409 + allowed cross-branch (proof transcripts).

### Phase 16 — Scheduling, queue, sessions, preferred personnel (operational core)
**Objective:** Scope §4, §5.7–5.9. **DB:** appointments (+exclusion constraint), queue_entries, queue_configurations, service_sessions, preferred_personnel_fee_rules. **Backend:** QueueService (atomic walk-in txn; capacity; modes; estimator using active personnel × service duration × queue length), AppointmentService (availability calc honoring branch calendar + personnel availability; check-in conversion linking — no duplicates), SessionService (state machine, eligibility check), ReassignmentService (branch-manager transfer with reason), preferred-personnel flow (fee preview endpoint → confirmation → fee on queue entry → invoice line in Phase 17; override perm+reason; unavailability options: wait/reassign/cancel+fee-reversal hook). **Frontend:** Front Office dashboard + actionable queue board (check in/assign/start/invoice/record payment/awaiting validation/ready for receipt), walk-in wizard with next-available vs preferred chooser (fee + estimated wait shown pre-confirmation), appointments calendar, Personnel mobile-first pages. **Tests:** the full Queue/Scheduling/Sessions block of §25. **Verification:** demo script: two concurrent walk-ins to same preferred personnel → second queues behind; DB exclusion blocks overlapping appointment (SQLSTATE shown). **Acceptance:** valid-transition table enforced (every invalid pair 422, data-provider test); atomicity proven by induced mid-txn failure leaving zero rows. **Risks:** wait estimation accuracy → label as estimate, tune post-launch.

### Phase 17 — Invoicing
**Objective:** Scope §5.10 + tier pricing. **DB:** invoices, invoice_items, invoice_number_sequences. **Backend:** InvoiceService (build from session: service price, discount, preferred fee line, tier surcharge line via TierPricingCalculator; FOR UPDATE numbering; merchant logo into PDF via pdf queue), Void/Adjustment approval workflows. **Frontend:** invoice create-from-session, list/detail, void/adjust modals with typed confirmation, PDF download (signed). **Tests:** Invoicing block of §25 + `NumberingConcurrencyTest` (50 parallel issuances, zero gaps/dupes assertion). **Acceptance:** tier table reproduced exactly (500/535/570 vs constant 70); voided invoice retains number and is excluded from revenue but present in history.

### Phase 18 — Payments, receipts, refunds, disputes, cash-up, period locks (finance core)
**Objective:** Scope §3.5, §5.11–5.12. **DB:** payment_records, payment_validation_events, payment_reference_checks, receipts (+pivot, trigger), receipt_number_sequences, receipt_reissues, receipt_download_logs, external_refunds, finance_disputes, financial_period_locks, branch_day_records, branch_cash_ups. **Backend:** recording (Front Office) → validation (Finance) workflow with all statuses; method reference rules; DuplicateReferenceDetector (+override); partial/split legs with invoice-paid invariant; ReceiptService auto-issue on full validation (DB-guarded); refunds with caps+approval; disputes; BranchDayService (open/pause/close/reopen+reason, summary snapshot); CashUpService (expected vs recorded, discrepancy note rule, finance review → daily lock); PeriodLockService wired into every financial mutation; Finance task inbox notifications (priority table). **Frontend:** Finance navigation (all 17 scope items), pending-validations queue, validation modal with method-specific fields, duplicate-reference modal, partial/split UI, receipts with reissue/reverse, refunds, disputes with evidence upload, cash-up wizard, periods screen, day open/close on Branch layout, payment UI states Saved/Unsaved/Pending validation. **Tests:** the entire Payments/Receipts/Refunds/Periods/CashUp blocks of §25. **Verification:** end-to-end transcript walk-in→receipt committed as proof; trigger test shows DB refusing premature receipt even when service layer bypassed. **Acceptance:** invoice paid only when validated total equals invoice total (split-leg test); locked day rejects edits 423; cash-up approval emails queued. **Risks:** workflow complexity for SMEs → inbox priorities + empty-state guidance.

### Phase 19 — Audit logging completion
**Objective:** §22.2 full coverage; chain integrity. **DB:** finalize audit_logs partitioning, flagged_audit_events, append-only trigger, restricted DB role. **Backend:** AuditRecorder coverage for every §5.18 action (data-provider test asserts each action writes a row with required fields), masking-at-read, ChainVerifier + `audit:verify-chain`, unauthorized-attempt pipeline complete. **Frontend:** Merchant Audit dashboard (high-risk, recent, flagged, payment issues, role changes, contact-access attempts, preferred overrides), searchable/filterable log (date/actor/role/branch/module/action/entity/severity/status), flag workflow, audit export (permission-gated). **Tests:** Audit block of §25. **Verification:** manual UPDATE attempt on audit_logs as app role → SQL error transcript; tamper a row in test → verifier flags. **Acceptance:** §5.18 action checklist 100% covered.

### Phase 20 — Citrus Billing Engine & commissions
**Objective:** §18 + scope §5.13–5.14. **DB:** platform_fee_ledger, platform_fee_invoices, platform_billing_settings, platform_fee_disputes, commission_rules, commission_ledger. **Backend:** FeeAccrualService (event-driven, idempotent), CycleInvoiceService + `billing:run-cycle`, SuspensionTriggerService, branch-debt gate now real (Phase 7 stub replaced), merchant statements (PDF), fee disputes; CommissionCalculator (precedence, preferred-fee setting, earned-on-validation) + ReversalService (void/refund events); Super Admin platform screens (settings, fee rules, merchants governance, ledger, reports); inactivity sweep. **Frontend:** merchant platform-fee pages (ledger, explanation, cycle, statement, dispute), HR commission rules, Finance commission liabilities, Personnel commission view. **Tests:** Billing + Commissions blocks. **Verification:** simulated 2-cycle run on seeded data; suspension fires on overdue fixture; reconciliation job reports zero drift. **Acceptance:** tier never alters liability (regression-pinned); commission earned only post-validation and reverses on void.

### Phase 21 — Queues, notifications, scheduled reports
**Objective:** §20 complete. **Backend:** Horizon config, all 20 notification types, Finance/branch inboxes, day-close + cash-up PDF jobs emailing Merchant Admin daily (Browsershot templates with logo, EAT date handling), scheduler entries, failed-job alerting. **Tests:** notification fakes per trigger; PDF snapshot tests (structure assertions); `Unit/TenantAwareJobTest` extended to all jobs; scheduler smoke (`schedule:test`). **Acceptance:** day close in E2E produces both PDFs in Mailpit addressed to Merchant Admin.

### Phase 22 — Search
**Objective:** §21. **Tasks:** Scout+Meilisearch, five indexes with tenant filters, `/search` endpoint, Front Office speed-search bar (phone/invoice/receipt fast-paths), HR roster search, audit log filters. **Tests:** isolation search test, latency assertion on seeded volume (10k clients), drift check command. **Acceptance:** cross-tenant search isolation proven; speed search <100ms p95 locally on fixture volume.

### Phase 23 — Security hardening & threat-model verification
**Objective:** Close §24 table. **Tasks:** CSP/security headers final, CORS lock, upload pipeline + ClamAV wiring, gitleaks/audit gates blocking, `ContactExportAbsenceTest`, EnumerationTest, dependency update sweep, internal pen-test checklist run (OWASP ASVS L2 spot-check) with findings fixed via Bug Fix Protocol (§28). **Acceptance:** every §24 row's verifying test exists and passes; pen-test checklist signed off in `docs/security/asvs-checklist.md`.

### Phase 24 — Performance optimization
**Objective:** Hit §23 targets. **Tasks:** add caches (queue board, dashboards, report snapshots) + invalidation events; k6 load scripts (`load/` folder: queue polling 200 VUs, invoice issuance 50 VUs, validation 50 VUs) against staging; fix regressions; bundle analysis ≤250KB gz initial; index review with `EXPLAIN ANALYZE` on top 20 queries committed to `docs/perf/`. **Acceptance:** all §23 targets met on staging hardware; zero N+1 in CI.

### Phase 25 — Deployment pipeline & final production readiness
**Objective:** §26 live + §31 checklist. **Tasks:** production infra provisioning, DNS/TLS, secrets, backup + restore drill executed and documented, uptime monitors, Sentry prod, Horizon auth, on-call alert routing; staging full-regression (all suites + E2E both themes); production smoke after first deploy; runbooks (`docs/runbooks/`: deploy, rollback, restore, incident, merchant-suspension, period-reopen). **Acceptance:** §31 Final Verification Checklist 100% checked, evidence linked per item; restore drill proof (timestamped log) attached. **Rollback:** previous-tag redeploy rehearsed on staging before go-live.

---

## 28. IDE Agent Execution Instructions

For **every** implementation step:
1. **Inspect first:** read the files you will touch (`view`/open), the relevant plan section, and the scope passage it cites. Never edit unseen code.
2. **Identify the requirement:** quote the plan section ID (e.g., "§18.1 fee accrual") in the PR description.
3. **Prove the gap:** show the failing test, missing route, or absent constraint that demonstrates the need (red test, route:list diff, schema dump).
4. **Smallest correct change:** implement only what the requirement needs; no drive-by refactors; no unrelated formatting churn.
5. **Preserve behavior:** run the full affected test directory before and after; existing green tests must stay green.
6. **Add/update tests** named per §25 before or with the change (TDD where practical).
7. **Run tests** (`php artisan test --parallel` + targeted `--filter`, `npm run test`, Playwright tag when UI changes).
8. **Show results:** paste the test summary into the PR/`docs/proof/` artifact.
9. **Demonstrate behavior:** API transcript, screenshot, or DB query output for the happy path **and** one denial path.
10. **Document remaining risks** in the PR under "Residual risk".
11. **Never:** weaken a constraint to make a test pass, broaden a `$fillable`, add `withoutTenancy`, skip a policy, log a secret, or touch audit rows.

**Bug Fix Protocol (mandatory format for any defect):**
```
- Observed problem:
- Evidence: (failing test / log excerpt / reproduction steps)
- Affected files:
- Root cause:
- Why this is the root cause: (not a symptom — show the causal chain)
- Correct fix:
- Files changed:
- Tests added or updated:
- Test command:
- Test result:
- Proof of resolution:
- Remaining risk:
```
Frontend-only fixes for backend authorization findings are rejected; styling-only fixes for logic defects are rejected; silent catch blocks are rejected.

---

## 29. Acceptance Criteria (release gate)

Servana is acceptable for production only when **all** hold, each evidenced by named tests/artifacts:
1. Multiple merchants, branches, and users operate concurrently with zero cross-tenant/cross-branch access (Isolation suite + §8.4 transcripts).
2. Magic Link auth enforces all seven §2.3 checks; sessions revoke instantly on suspension (auth suite).
3. Authorization matches the §10.3 matrix exactly; authority boundaries of scope §3 enforced server-side (PermissionMatrixTest, AuthorityBoundariesTest).
4. Operational core works end-to-end: walk-in/appointment → queue → session → invoice → offline payment → Finance validation → automatic receipt, with preferred-personnel fee, atomicity, and valid-transition enforcement (E2E journey green).
5. Financial integrity: unique numbering under concurrency, paid-only-when-validated-total-equals-invoice, receipt-after-validation DB guard, void/adjust approvals, duplicate-reference detection, partial/split correctness, period locks, cash-up reconciliation, daily PDFs delivered.
6. Citrus Billing Engine: tier table behavior exact, accrual/cycle/statement/dispute/suspension/branch-debt gate all proven; commissions earned-on-validation with reversals.
7. Audit: every §5.18 event recorded, append-only, hash-chained, verifiable, flaggable, masked appropriately; unauthorized attempts logged.
8. Personnel contact export does not exist anywhere (ContactExportAbsenceTest) and personnel own-scope holds server-side.
9. UI responsive at 360/768/1280 with no horizontal scroll or overlap; light+dark both AA; accessibility gates pass; all §6.4 states implemented.
10. APIs versioned, validated, authenticated, authorized, paginated, rate-limited, with the structured error envelope (RouteCoverageTest).
11. Background jobs carry tenant context; Horizon healthy; scheduler tasks idempotent.
12. Observability live: logs, Sentry, Horizon, health checks, uptime, alerts; backups with a passed restore drill.
13. CI/CD pipeline deploys staging→production repeatably with rollback rehearsed; no secrets in repo/images; dependency scans clean of high/critical.
14. §31 checklist 100% complete with linked evidence.

---

## 30. Risk Register with Mitigation Steps

Inherits all scope §16 risks (each mapped to the controls built in Phases 16–20 as noted there). Additional delivery/engineering risks:

| # | Risk | L | I | Mitigation | Owner phase |
|---|------|---|---|------------|-------------|
| R1 | Finance workflow complexity overwhelms SMEs (scope: 35–50%) | M | H | Workflow-driven dashboards, task inbox priorities, empty-state guidance, staged rollout with pilot merchants | 18, 21 |
| R2 | Scope creep delays launch (scope: 40–60%) | M | H | Launch checklist (scope §15) is the only backlog; client portal/SMS/inventory explicitly deferred | all |
| R3 | Magic-link email deliverability failures lock users out | M | H | Reputable provider, SPF/DKIM/DMARC, delivery monitoring, resend flow, support runbook | 5, 25 |
| R4 | Numbering/locking contention under load | L | M | Per-merchant sequences, short txns, concurrency test, k6 validation | 17, 24 |
| R5 | Audit volume degrades DB | M | M | Monthly partitions, BRIN, async non-financial writes, retention/archival job | 19, 24 |
| R6 | Hash-chain serialization becomes a write bottleneck | L | M | Per-merchant chains + advisory locks (parallel across tenants); measured in k6 | 19, 24 |
| R7 | Inactivity deletion conflicts with legal retention | M | H | AS-10 anonymize-and-archive design; legal sign-off checkpoint before Phase 20 ships sweep | 20 |
| R8 | Day-boundary/timezone bugs (EAT vs UTC) in day records, locks, reports | M | H | Single `BusinessDate` helper, all day logic tested with EAT fixtures incl. midnight edges | 18, 21 |
| R9 | Seed/permission drift between matrix doc and seeder | M | M | PermissionMatrixTest generated from one fixture consumed by both seeder and test | 8 |
| R10 | Restore procedure untested until disaster | L | C | Monthly scheduled restore drill with sign-off artifact | 25 |
| R11 | Playwright/E2E flakiness erodes CI trust | M | M | Network-idle waits, seeded deterministic data, retries=1 with flake quarantine label | 11+ |
| R12 | Single membership assumption breaks for multi-merchant staff later | L | M | merchant_users already supports many rows; only context resolution is single-active — documented extension point | 6 |

---

## 31. Final Verification Checklist

Tick only with linked evidence (test run, transcript, screenshot, or doc):

**Tenancy & access**
- [ ] All Isolation tests green (CI link)
- [ ] §8.4 denied-case transcripts captured
- [ ] Route coverage test enumerates 100% of /api/v1 with auth+authz
- [ ] PermissionMatrixTest output matches §10.3
- [ ] ContactExportAbsenceTest green; unauthorized attempts visible in audit UI
**Auth**
- [ ] Seven-check magic-link denial matrix green; throttles return 429
- [ ] Suspension revokes live session (browser proof)
- [ ] MFA enroll/verify for privileged roles
**Operations**
- [ ] E2E: register→setup→invite→walk-in→preferred fee→invoice→payment→validation→receipt→day close→cash-up→PDFs (video/trace)
- [ ] Invalid-transition data-provider green; atomic walk-in rollback proof
- [ ] Appointment exclusion constraint demo
**Finance**
- [ ] NumberingConcurrencyTest green; voided number retained
- [ ] Tier table 500/535/570 + constant 70 regression green
- [ ] Receipt-before-validation blocked at DB layer (SQL transcript)
- [ ] Duplicate-reference block/override + audit proof
- [ ] Period lock 423 + reopen approval audit
- [ ] Billing cycle run on staging fixtures; statement PDF reviewed; overdue→suspension fired; branch-debt gate blocks deletion
- [ ] Commission earned-on-validation + reversal proofs
**Audit**
- [ ] §5.18 action coverage test green; chain verify clean; tamper test detects
- [ ] Append-only trigger transcript
**UI/UX**
- [ ] Responsive matrix (360/768/1280) zero horizontal scroll/overlap
- [ ] Light+dark axe AA on gated pages; no theme flash
- [ ] Keyboard + screen-reader walkthrough notes for critical flows
- [ ] All §6.4 states snapshot-tested
**Platform & ops**
- [ ] CI pipeline green end-to-end; gitleaks/composer/npm audits clean (no high/critical)
- [ ] Staging deploy → smoke → production deploy → smoke logs
- [ ] Rollback rehearsal log; restore drill log
- [ ] Health/deep endpoints monitored; alert test fired and received
- [ ] APP_DEBUG=false assertion; HTTPS+HSTS verified; security headers scan
- [ ] Backups encrypted, off-site, retention configured
- [ ] Runbooks complete (deploy, rollback, restore, incident, suspension, period-reopen)
- [ ] docs/proof/ artifacts complete for Phases 5–25

---

*End of plan. Execute Phase 1.*
