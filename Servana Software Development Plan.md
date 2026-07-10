# Servana by Citrus — Production Software Development Plan (v4, As-Built + Remediation + Feature Delivery + Wallet by Citrus & Citrus Refer & Earn Integration)

> This document is the single executable plan of record for building and completing Servana by Citrus.
> It is written to be executed by an IDE-based AI coding agent without guessing. Every phase is bounded,
> reviewable, testable, and traceable to authoritative product scope. Where this plan names a mandatory,
> version-controlled specification file (for example a per-table data-dictionary entry or a per-screen
> specification), that file is a required deliverable of the owning phase and must exist and pass review
> **before** the corresponding migration, route, or screen is implemented. "Details in the owning phase"
> is never used as a placeholder: the owning phase either contains the detail inline here, or is bound to
> create the named specification file to the exact format defined in this plan.
>
> **v4 is a standalone, fully merged plan of record.** It incorporates the Wallet by Citrus payment-orchestration
> integration and the Citrus Refer & Earn source-product integration directly into the plan body; no separate
> amendment document is required and v3 does not need to be consulted. Every statement v4 changed from v3 is
> recorded, with reasons, in §1.2 (supersessions) so no scope decision disappears silently.

---

## 0. How the Implementation Agent Must Use This Plan

1. Read the entire owning phase in Section 79 (remediation) or Section 80 (features) before changing any code.
2. Open every authoritative scope reference the phase cites in `SERVANA COMBINED.txt`.
3. Inspect the actual repository state (migrations, `route:list`, policies, services, components, tests, lock files, CI).
4. Prove the current state with commands and evidence; never trust `PROGRESS.md` or `CHANGELOG.md` as proof of behavior.
5. For any defect, perform root-cause analysis (Section 6) before editing.
6. Produce a file-level implementation checklist for the phase.
7. Implement only the scoped phase. Do not touch unrelated bounded contexts.
8. Write or update tests **before** declaring completion. Never weaken, skip, or delete a test to pass.
9. Run the full relevant quality suite (Section 75) and produce the proof artifacts the phase requires.
10. Update `PROGRESS.md`, `CHANGELOG.md`, the traceability matrix (Section 85), and any new ADRs.
11. Stop and record a blocking ambiguity if an authoritative rule is missing. Never invent business rules.
12. Never implement anything from the Wallet by Citrus or Citrus Refer & Earn platform scopes inside the Servana repository. Those platforms are separate systems. Servana implements only its own side of each integration contract as specified in this plan (§2.2 ownership matrix; §§55–58B; Phases 20D‑W, 21R‑A, 21R‑B). Building a partner-owned capability in Servana is a defect even if it works.
13. Before writing any Phase 20 code, land the plan-adoption PR described in §1.3 (ADRs 012–015, data-dictionary file changes, the non-regression rule, and the static-analysis guards of §9 rule 20).

---

## 1. Document Control

| Field | Value |
|---|---|
| Document | `Servana Software Development Plan.md` (the canonical active plan file in the repository root; earlier v4 drafts called this file `SERVANA_DEVELOPMENT_PLAN.md` — that name is retired and no file by that name exists) |
| Version | v4 (standalone full merge: the v3 plan plus the Wallet by Citrus & Citrus Refer & Earn integration revision; every change from v3 is recorded in §1.2) |
| Status | Active plan of record |
| Product authority | `SERVANA COMBINED.txt` (Upgraded Platform Project Scope + PART B Contradiction-Resolution Amendment + Brand Identity) |
| Engineering-correction authority | `SERVANA_DEVELOPMENT_PLAN_CORRECTIONS.md` (Corrections 1–25, Sections 0 and 26–27) — historical source document, fully folded into this v4 plan; the file itself is **not** present in this repository |
| Payment-orchestration authority | `Wallet_by_Citrus_Platform_Project_Scope.md` — the Servana↔Wallet integration contract: product API, signed webhooks, structured payment references, collection state machine, idempotency mandates |
| Referral-integration authority | `Refer_and_Earn_Project_Scope.md` + `Citrus_Refer_and_Earn_Production_Software_Development_Plan.md` — the Servana↔R&E integration contract: event catalogue, `X-Citrus-*` signing contract, qualification authority, reconciliation surface |
| As-built evidence | repository, migrations, `route:list`, policies, tests, lock files, CI, `PROGRESS.md`, `CHANGELOG.md` (claims, not proof) |
| Currency | KES default; integer minor units only; `char(3)` ISO currency with uppercase check |
| Primary timezone | `Africa/Nairobi` for business-date boundaries; `timestamptz` for events |
| Database | PostgreSQL 16 (partial indexes, exclusion constraints, JSONB, advisory locks, triggers are required and PostgreSQL-specific) |
| Cache/queue/locks | Redis 7 (persistence/failover sized per Section 77) |
| Object storage | Private S3-compatible (MinIO in dev; managed S3-compatible in production) with versioning + lifecycle |
| Backend | Laravel (target Laravel 12.60+; see ADR-001 and Phase R1) on PHP 8.3 pinned across all images |
| Frontend | Vue 3 + TypeScript + Vite SPA, Pinia, Tailwind, Sanctum stateful session auth |

### 1.1 Change Control

- This plan changes only through a reviewed PR that updates the affected section, the traceability matrix, and any ADR.
- Enum values, status names, route names, permission keys, audit-event names, and lifecycle rules are defined once in this plan and referenced everywhere. Divergence in any layer is a defect.
- No phase may introduce a business table, route, screen, or financial workflow that is not represented in this plan and the traceability matrix.

### 1.2 Changes from v3 (integration-revision record)

**Status at the time of this revision:** Phases V, R1–R7, 10, 10F, 11, 15A, 15B, 16A, 16B, 16C, 17, 18A, 18B, and 19 are complete and verified. **Phase 20 has not commenced.** Every change below therefore modifies *unbuilt* phases; no destructive rework of shipped code is required, and no expand-and-contract migration of populated M-Pesa or R&E tables is needed (those tables were never created).

**Why the revision exists (Prove the Problem):**

| # | Evidence | Problem proven |
|---|---|---|
| 1 | v3 §§55–58 and Phase 20D made Servana directly responsible for Daraja credentials, STK submission, provider request identifiers, C2B validation/confirmation callbacks, `mpesa_callback_inbox`, receipt uniqueness, transaction-status queries, provider reconciliation, and exception management. | Wallet by Citrus scope §2–§5, §21–§22, §34–§36 defines exactly these responsibilities as centralized Wallet capabilities shared by Servana, Kikao, SkillFlow, and future products, with a single authoritative callback flow per shared shortcode (Wallet §21: integrated products must not independently register competing validation/confirmation URLs for the same shared shortcode). Implementing the v3 Phase 20D unchanged would knowingly build architecture Wallet is defined to replace. |
| 2 | v3 contained **zero** references to Citrus Refer & Earn. | The R&E settled architecture (R&E scope §0.2–§0.4; R&E dev plan §1.1) requires each source product — explicitly including Servana — to retain product-native referral capture, attribution notice, signed integration event production, a reconciliation surface, and product-specific activity-decision logic. R&E scope §11.2 already publishes Servana-specific active-use rules. Without this revision, Servana could not honor an existing cross-product commercial contract, and the R&E platform could not pay referrers for Servana acquisitions. |
| 3 | v3 §80.1 routed `20B → 20D` (direct Daraja) and §13.11 defined Daraja-shaped tables. | Executing 20A–20C against an obsolete source of truth during the platform's most financially sensitive work would violate §0 ("the plan is the executable plan of record"). |
| 4 | Phase 20 has not started. | Revising now is a pure plan change with zero code-migration cost; deferring converts a plan edit into a production data migration. |

**Explicit supersessions (each supersedes the corresponding v3/scope statement):**

| ID | Superseded v3/scope statement | v4 rule | Reason |
|---|---|---|---|
| SUP‑01 | v3 §55–§56: "create provider requests using server-held credentials … persist provider IDs (checkout/merchant request id)" | Servana holds **no** payment-provider credentials and persists **no** raw provider request identifiers; it persists Wallet public identifiers (`wallet_payment_id`, `wallet_attempt_id`) and a masked provider reference only. | Wallet scope §22.1 mandates products request STK through Wallet; Wallet §50 requires provider credentials to live only in Wallet. |
| SUP‑02 | v3 §13.11 `mpesa_callback_inbox`, `mpesa_reconciliation_events` | Replaced by `wallet_webhook_inbox` and `billing_reconciliation_exceptions` (§13.11). Raw Daraja payloads never enter Servana. | Wallet §34 makes Wallet the sole raw-payload custodian; duplicating raw callbacks in Servana violates data minimization and creates dual financial truth. |
| SUP‑03 | v3 §24.1 `provider_webhook_mutation` defined around Daraja controls ("no invented HMAC … ADR‑006") | Route class generalized to `partner_webhook_mutation` with **mandatory signature verification** (algorithm-aware per ADR‑015: HMAC or asymmetric, selected by algorithm identifier + key ID + contract version), because Wallet outgoing webhooks are signed by contract (Wallet §35). ADR‑006 remains historically valid for the abandoned direct-Daraja design and is marked superseded-by‑ADR‑015. | The "no invented HMAC" rule existed because Daraja doesn't sign callbacks. Wallet **does** sign. Not verifying an available signature would be a security defect. |
| SUP‑04 | v3 §22/§58: "only a fully validated subscription payment moves `suspended_billing → active`" where "validated" meant Servana's own callback validation | "Validated" now means: a Wallet webhook event whose signature verified, whose `wallet_event_id` is first-seen, whose payment maps to the invoice's registered Wallet payment, and whose amount was applied under the invoice row lock. Semantics of billing-only recovery are otherwise unchanged. | Ownership boundary (§2.2): Wallet owns money-movement truth; Servana owns billing truth and the decision to restore access. |
| SUP‑05 | v3 §13.9: `subscription_invoices.account_reference varchar (exact M-Pesa reference)` | `account_reference` is the **Wallet structured payment reference** (`SRV-PAY-<ULID26>`), issued by registering the invoice's payment resource with Wallet (ADR‑014); nullable until registration succeeds; PayBill/Till instructions and STK initiation are both gated on registration (§56.1 sequencing). | Wallet §21.1 requires product-prefixed structured references mapped to a registered payment reference, immutable after issuance. |
| SUP‑06 | v3 permission keys `platform.mpesa_exception.view/resolve` and route names `mpesa.*` | Renamed to `platform.billing_reconciliation.view/resolve` and `billing.wallet.*` / `integrations.wallet.*` (§19, §56.1). Since Phase 20 is unbuilt, this is a plan rename, not a code migration. | Names must match the new boundary; keeping "mpesa" keys would imply provider ownership Servana no longer has. |

No other scope statement is superseded. In particular the following rules are **reaffirmed unchanged**: no manual Super-Admin payment recording; payment never clears a non-billing suspension; trial starts at Merchant-Admin creation; ADR‑005 money/rounding; ADR‑011 price source of truth; overpayment→billing credit (A‑10); Servana does not move personnel-payout funds at launch (Phase 20H).

### 1.3 Plan-adoption PR (mandatory checklist)

Adopting v4 in the repository is **one reviewed architecture-change PR** (per §1.1), landed **before any Phase 20 code is written**. It must contain, and review must verify:

1. `docs/architecture/adr/0012-wallet-by-citrus-payment-orchestration-boundary.md` (ADR‑012, §8.1).
2. `docs/architecture/adr/0013-citrus-refer-and-earn-integration-authority.md` (ADR‑013, §8.1).
3. `docs/architecture/adr/0014-structured-payment-reference-and-invoice-registration.md` (ADR‑014, §8.1).
4. `docs/architecture/adr/0015-cross-platform-machine-identity-and-webhook-signing.md` (ADR‑015, §8.1).
5. Data-dictionary file changes: `docs/architecture/data-dictionary/billing-and-wallet.md` (replaces `billing-and-mpesa.md`) and new `refer-earn-integration.md` (§13.2).
6. `docs/auth/permission-matrix.yaml` updated per §19 (renames + new platform keys); `PermissionMatrixParityTest` green.
7. The recorded **non-regression rule**: *no new payment-provider credential, provider callback endpoint, provider OAuth token handling, provider receipt-uniqueness logic, or provider reconciliation implementation may be added to the Servana repository* (enforced by the static-analysis guard in §11 and `NoDirectProviderIntegrationTest`, §75.1).
8. `PROGRESS.md` and `CHANGELOG.md` entries recording the adoption with actual commit references (§5 style — commits, not narrative assertions).

---

## 2. Source-of-Truth Hierarchy

Apply this precedence when any two sources appear to conflict. Do not silently choose between conflicting requirements: record the conflict, name the controlling source, state the decision, and update every affected section.

1. **`SERVANA COMBINED.txt`** governs **product behavior**: roles, role boundaries, permissions intent, billing behavior, account lifecycles, workflows, UX, billing-payment behavior (as amended by the §1.2 supersessions), compensation rules, audit behavior, and acceptance requirements. PART B (Contradiction-Resolution Amendment) has higher precedence than the body of the scope and must reference the requirement it supersedes.
2. **`SERVANA_DEVELOPMENT_PLAN_CORRECTIONS.md`** governed **implementation planning**: architecture, data model, API contracts, security controls, sequencing, decomposition, testing, migration strategy, and production readiness. Where the previous development plan conflicted with the corrections, the corrections won and are folded into this plan. (Historical: that corrections file is not kept in this repository — its content is fully absorbed here.)
3. **`Wallet_by_Citrus_Platform_Project_Scope.md`** governs the **Servana↔Wallet integration contract**: product API shapes, signed-webhook contract, structured payment references, collection states, idempotency mandates, and the division of payment responsibilities. Where it conflicts with direct-M-Pesa statements in source 1, the recorded supersessions in §1.2 control.
4. **`Refer_and_Earn_Project_Scope.md` + `Citrus_Refer_and_Earn_Production_Software_Development_Plan.md`** govern the **Servana↔R&E integration contract**: the required event catalogue, the `X-Citrus-*` signing contract, qualification-authority rules, decision precedence, and the reconciliation surface.
5. **This plan (`Servana Software Development Plan.md` v4)** translates 1–4 into sequenced, executable engineering work. It reuses prior plan content only where that content is correct and compatible with 1–4.
6. **The repository** (migrations, routes, policies, services, components, tests, deployment files) is the authoritative evidence of what is actually built — but only after Phase V verifies it. `PROGRESS.md` and `CHANGELOG.md` are useful context, never proof.

### 2.1 Settled Cross-Cutting Rules (binding everywhere)

These are non-negotiable and apply to every phase, table, route, screen, job, and test:

1. Financial values are stored as `bigint` minor units plus a `char(3)` uppercase-checked currency. Floating-point money is forbidden. KES is the default currency.
2. Every tenant-owned record is tenant-scoped (`merchant_id`). Every branch-owned record is tenant- and branch-scoped (`merchant_id` + `branch_id`). Personnel-facing own-scope resources additionally derive `staff_profile_id` from the authenticated membership and never accept another personnel identifier.
3. Merchant **operational status** and merchant **billing status** are separate state machines. A billing payment never reactivates a merchant suspended for fraud, security, legal, compliance, manual platform action, or deactivation.
4. Merchant-client payments are **off-platform records** at launch (Servana records, does not move, client funds). Merchant-to-Servana subscription/platform billing payments use **M-Pesa via Wallet by Citrus**: Servana never integrates directly with Safaricom/Daraja and holds no provider credentials; all money movement is orchestrated by Wallet, and Servana applies confirmed funds only from verified Wallet webhook events (§2.2; ADR-012). Servana does not move personnel payout money at launch.
5. The **Super Administrator** cannot create merchant tenants, create the first Merchant Administrator, impersonate merchant users, or conduct merchant operations. Those routes/screens must not exist. The only merchant-creation path is merchant self-registration.
6. **Personnel contact export is permanently prohibited** in every channel: API, UI, search, download, SMS, logs, and exports. No endpoint returns bulk full phone numbers.
7. **Maker/checker** separation is enforced server-side for financial workflows. Settled financial history is never updated destructively; corrections use reversal/adjustment ledger rows.
8. All mutation routes carry an explicit route classification (Section 24). All stateful aggregates transition only through documented transition actions (Section 25). No controller assigns a status string directly.
9. The canonical platform billing modes are exactly:
   `fixed_amount`, `percentage_on_merchant_client_invoice`, `fixed_amount_plus_percentage_on_merchant_client_invoice`.
   At launch Servana is **subscription-first**: the subscription plan/price path is launch-active; the percentage platform-fee engine is built and launch-capable (Phase 20E) but is activated only when a percentage component is configured.
10. Authorization is enforced on the backend. Frontend permission checks are UX only and never a security control.

### 2.2 Cross-Platform Ownership Boundary Matrix (Normative)

**The one-sentence ownership rule:** *Servana owns business-billing truth and referral-activity truth; Wallet owns money-movement truth; R&E owns referral-reward truth.* Servana never talks to Safaricom. Wallet never decides whether a Servana merchant is entitled to a plan or whether access is restored. R&E never queries Servana's database and never converts raw activity counts into qualification — Servana's signed `activity.qualification_decided` event is final authority (R&E scope §11.4).

Every implementation decision in Phases 20D‑W, 21R‑A, and 21R‑B must be checked against this matrix. Building a capability in the wrong column is a defect even if it works.

| Capability | Servana | Wallet by Citrus | Citrus R&E |
|---|---|---|---|
| Subscription plans, versioned prices, entitlements | **Owns** | — | — |
| Merchant subscriptions, billing cycles, trials, grace, `merchants.billing_status` | **Owns** | — | — |
| Subscription invoices + line items + immutable snapshots | **Owns** | — | — |
| Promotions, free-period offers, discount snapshots | **Owns** | — | — |
| Percentage platform-fee ledger (business liability) | **Owns** (Phase 20E) | — | — |
| Who may initiate a payment; payment UX per role | **Owns** | — | — |
| Applying confirmed funds to an invoice; overpayment→credit | **Owns** | — | — |
| Billing-only recovery; refusal to clear non-billing suspensions | **Owns** | — | — |
| Provider credentials, shortcodes, PayBill/Till accounts | — | **Owns** | — |
| STK submission, provider request IDs, checkout IDs | — | **Owns** | — |
| C2B validation/confirmation endpoints; raw callbacks | — | **Owns** | — |
| M‑Pesa receipt uniqueness; callback replay protection | — | **Owns** | — |
| Provider transaction-status queries; provider downtime handling | — | **Owns** | — |
| Authoritative payment + settlement state; double-entry ledger | — | **Owns** | — |
| Provider/bank reconciliation; provider exception queue | — | **Owns** | — |
| Signed product webhooks; durable retries; replay | — | **Owns** | — |
| Structured reference issuance (`SRV-PAY-…`) | Requests + displays | **Issues/owns** | — |
| Servana↔Wallet allocation reconciliation (projection vs Wallet totals) | **Owns** (its own books) | Answers status queries | — |
| Referrer accounts, codes, campaigns, reward rules | — | — | **Owns** |
| Referral capture at Servana registration; local snapshot | **Owns** | — | Validates code |
| Attribution uniqueness + effective earning attribution | Notifies | — | **Owns** |
| Servana merchant lifecycle + subscription financial facts | **Emits (source of truth)** | — | Consumes as evidence |
| Active-use qualification decision for Servana merchants | **Owns (final authority)** | — | Consumes; may request re-evaluation; never overrides |
| Reward calculation, reward ledger, referrer payouts, statements | — | (future payout rail for R&E; not Servana's concern) | **Owns** |
| Personnel payout fund movement | Out of scope at launch (records only) | Future (post-launch enhancement) | — |

---

## 3. Scope and Product Summary

Servana by Citrus is a multi-tenant SaaS operating platform for service-based SMEs (barbershops, salons, spas, grooming and beauty studios) in African markets, sold subscription-first, collecting merchant billing via M-Pesa through **Wallet by Citrus** (the central Citrus payment-orchestration platform), and participating in **Citrus Refer & Earn** as a referral source product.

### 3.1 Account Types (8) and Their Boundaries

The platform has one platform-side role and seven merchant-side roles. Role boundaries are authoritative (`SERVANA COMBINED.txt` §4, §13) and enforced through the permission matrix (Section 19):

- **Super Administrator (platform):** platform governance only — billing settings, plans/prices, entitlements, promotions, free-period offers, preferred-personnel-fee rules, Wallet-integration configuration and billing-reconciliation exceptions, integrations health, referral-qualification oversight, merchant suspension/reactivation/deactivation, registration monitoring, platform audit. **Cannot** create merchants, mint the first Merchant Administrator, impersonate, or perform merchant operations.
- **Merchant Administrator / Owner:** account owner — merchant profile, branches, staff overview, subscription/plan/billing/payment, recovery, all-branch reports, compensation summary, high-value payout approval, exceptional period-reopen approval. **Not** an operational superuser: no service-catalogue mutation, no staff compensation editing, no invoice creation, no payment validation, no queue transfer by default.
- **Branch Manager:** owns the **service catalogue**, branch profile/calendar, branch day open/pause/close (and reopen with reason), cash-up submission, branch reports, branch-context subscription payment. **Must not** receive invoice creation, queue/appointment transfer, payment validation, cash-up approval, refund management, or period-lock management.
- **HR:** owns staff, invitations, lifecycle, role/branch assignment, personnel eligibility/availability, **compensation setup** (plans, commission rules), and payout-run draft preparation/submission. Branch-scoped. Cannot self-escalate, manage other branches, mark payouts paid, approve payout runs, or export client/payment data.
- **Front Office:** owns ordinary client creation, appointments, walk-ins, queue assignment/transfer, service sessions, preferred-personnel selection, invoice creation, and **default customer-payment recording** (maker). Branch-scoped. Recording a payment does not grant validation.
- **Finance:** owns payment **validation**/rejection/correction, receipts/reissue, refunds/disputes, cash-up approval, **financial period locks**, finance exports, subscription payment-attempt visibility, compensation liability, compensation adjustments, payout verification/standard approval/mark-paid, and earnings-query responses (checker). Sensitive actions require MFA step-up.
- **Personnel:** strict **own-scope** — own queue/appointments/sessions, own served clients (masked contact), own compensation/earnings/statements/payouts, own earnings queries, and **in-platform bulk SMS to personally served clients only**. No contact export ever.
- **Audit:** branch-scoped, read-only with field-masking; may update only flagged-event **review metadata**. Cannot modify any source operational, financial, compensation, billing, user, or audit-log record.

### 3.2 Core Product Pillars

- **Subscription-first billing** with configurable billing modes; flat plan pricing from a single price source; trial → grace → suspension lifecycle; no mid-cycle proration; shared overdue escalation.
- **Wallet-orchestrated merchant billing** (STK Push first; PayBill/Till structured-reference payments as fallback; verified Wallet webhooks drive settlement; stale-attempt status queries and nightly allocation reconciliation; automatic billing-only reactivation) with no manual Super-Administrator payment-recording path and zero provider logic inside Servana (ADR-012).
- **Referral source product for Citrus Refer & Earn:** non-blocking referral capture at self-registration, signed idempotent event emission through a transactional outbox, and final-authority monthly activity-qualification decisions (ADR-013).
- **Branch-scoped operations:** services/catalogue, clients, appointments, walk-ins, queues, service sessions, preferred-personnel fee.
- **Financially auditable money flow:** merchant-client invoices → offline payment recording → Finance validation → auto receipts → cash-up → period locks → refunds/disputes, with immutable ledgers and maker/checker.
- **Compensation:** commission-only, salary-only, salary-plus-commission; effective-dated plans; commission earned only after validated payment; salary accrual; payout runs with HR→Finance→(Merchant Admin for high value) ownership; personnel own-scope earnings.
- **Personnel bulk SMS** to served clients as a controlled messaging workflow that can never become a contact-export surrogate.
- **Audit, observability, responsiveness, dark mode, and accessibility** as first-class, tested concerns.

### 3.3 Out of Scope at Launch (explicit exclusions)

- Super-Administrator merchant creation, first-admin creation, impersonation, and merchant operations.
- Movement of merchant-client funds and personnel payout funds by Servana (records only).
- Client self-service portal (inactive; branch records only).
- Mid-cycle proration; automatic plan grandfathering.
- Any contact-export capability for Personnel.
- Percentage platform-fee billing **activation** unless a percentage component is configured (engine is built in 20E and remains launch-inactive until configured).
- Direct integration with M-Pesa/Daraja or any payment provider (all merchant→Servana collections flow through Wallet by Citrus; Servana holds no provider credentials — §9 rule 20).
- Referral reward computation, referrer accounts/wallets, and referrer payouts (owned by Citrus R&E; Servana emits events and answers reconciliation queries only).
- Wallet payout rails (B2C/bulk) for personnel payouts (post-launch enhancement; A-17).

---

## 4. Current As-Built Verification (Reported vs. To-Be-Verified)

`PROGRESS.md` and `CHANGELOG.md` report Phases 1–8 merged (PRs #1–#8) and Phase 9 (tenant-scoped data-access hardening) complete locally and awaiting CI/owner approval. **These are claims, not verified facts.** Phase V (Section 79) regenerates this section from repository evidence and produces `docs/verification/as-built-discrepancies.md`. Until Phase V completes, no remediation or feature phase may rely on any row below being correct.

> **Phase V status (2026-06-21, `local_complete` on branch `phase-v-as-built-verification` @ `e8681f6`):** the verification is done; PR #9 (Phase 9) is in fact **merged**, and the framework upgrade landed via **PR #11** (Laravel 12.62.0, PHP 8.3.31). The evidence-based outcome of every row below is recorded in **§4.1** and in `docs/verification/as-built-discrepancies.md` (with supporting `docs/verification/evidence/*` and the seeded `docs/remediation/register.yaml`). The "Status before Phase V" column is retained as the historical pre-verification claim.

Verification statuses used: `Claimed` (reported, not yet independently verified), `Verified`, `Partially verified`, `Not found`, `Contradicted`, `Requires remediation`.

| # | Reported as-built claim (source) | Status before Phase V | Phase V action / expected remediation linkage |
|---|---|---|---|
| 1 | Laravel 11.54 on PHP 8.3; advisory CVE-2026-48019 / GHSA-5vg9-5847-vvmq ignored-with-rationale since Phase 1 (`PROGRESS`/`CHANGELOG`) | Claimed → **Requires remediation** | Confirm exact framework/PHP versions from lock files and running containers. The unsupported/vulnerable Laravel 11 line with a suppressed advisory is a **C0** item → **Phase R1** (upgrade to Laravel 12.60+, remove the ignore). |
| 2 | Magic Link auth: 64-byte random token, SHA-256 at rest, 15-min expiry, atomic single-use; seven-check eligibility contract; idle timeout 60 min (`PROGRESS` Phase 5) | Claimed → **Verify** | Verify token hashing/expiry/single-use, eligibility checks 1–7, and idle-timeout middleware via tests in clean containers. |
| 3 | Real MFA is a safe placeholder only (`mfa_not_enabled`) (`PROGRESS` Phase 5) | Claimed → **Requires remediation** | Confirm no privileged MFA enforcement exists. Privileged MFA + step-up is a **C0** gap → **Phase R3**. |
| 4 | Tenant model: `merchants`, `merchant_profiles`, `merchant_users`, `merchant_status_histories`, minimal `merchant_branches`; self-registration only; no Super-Admin/KYC route (`PROGRESS`/`CHANGELOG` Phase 6) | Claimed → **Verify** | Confirm forbidden Super-Admin merchant-creation routes do **not** exist (`route:list`); confirm self-registration path and `pending_setup` lifecycle. |
| 5 | Branch/staff: `branch_user_assignments`, `staff_invitations`, `staff_profiles`, `staff_history`, `branch_operating_hours`, `branch_calendar_exceptions`, `branch_day_records`, `branch_cash_ups`; hashed 72h invite token; staff lifecycle revokes sessions/links (`PROGRESS`/`CHANGELOG` Phase 7) | Claimed → **Verify / Partially verify** | Verify branch-closure guards (several are named stubs for Phases 16–18/20), invitation token hashing, and lifecycle revocation. Stubbed closure blockers are tracked and flipped by their owning phases. |
| 6 | Roles & permissions: `permissions`, `roles`, `role_permission_assignments`, `merchant_user_permission_overrides`; registry of **54 keys × 8 roles**, 82 grants; deny-beats-grant; audit role can never gain mutating key (`PROGRESS`/`CHANGELOG` Phase 8) | Claimed → **Partially verified / Requires extension** | Verify resolver semantics. The 54-key registry is a **baseline**; the authoritative canonical matrix (Section 19, Correction 16) is larger. Reconcile registry to the canonical `docs/auth/permission-matrix.yaml` in the owning feature phases; add the parity test (Correction 16.5). |
| 7 | `audit_logs` append-only, hash-chained, DB trigger blocks UPDATE/DELETE; `AuditRecorder`/`DatabaseAuditRecorder` (`PROGRESS`/`CHANGELOG` Phase 8) | Claimed → **Verify; complete in R2/19** | Verify immutability trigger and hash columns. Event **coverage** and the chain **verifier** are incomplete → **Phase R2** (core) and **Phase 19** (full coverage + flagged-event workflow). |
| 8 | Tenant isolation: `BelongsToMerchant`/`BelongsToBranch` global scopes, scoped route binding, `LogUnauthorizedAttempt`, `TenantAwareJob`, PHPStan tenancy rules; cross-tenant 404 / cross-branch 403 posture (`PROGRESS`/`CHANGELOG` Phase 9) | Claimed (Phase 9 not merged) → **Verify; harden in R5/R6** | Verify global scopes, scoped binding, foreign-ULID audit, and job tenancy. Add `merchant_id` to any branch-owned table lacking it and complete revocation → **Phase R5/R6**. |
| 9 | `idempotency_keys` defined in prior plan **without** `key_hash` but with a unique constraint on `(merchant_id, key_hash)` (prior plan; Correction 3) | Claimed → **Contradicted** | The prior definition is invalid. Replace with the corrected schema (Section 13, Correction 3) → **Phase R4**. |
| 10 | Money value object (integer minor units; KES + USD); structured logging + redaction; correlation IDs; named rate limiters; `/health` + `/health/deep` (`PROGRESS`/`CHANGELOG` Phases 3–4) | Claimed → **Verify** | Verify redaction list (Section 24.5), rate limiters, and that readiness requires production dependencies (liveness vs readiness split is completed in **Phase R7**). |
| 11 | Frontend foundation: 8 layouts, router + UX-only guard stubs, Pinia stores, `apiClient`, `useForm`, 9 UI components, breakpoints `md:768`/`lg:1025`, dark-mode tokens (`PROGRESS`/`CHANGELOG` Phase 4) | Claimed → **Verify** | Verify the design-system core and breakpoints. Full responsive/dark/a11y are per-feature gates (Sections 28–30, Correction 9) plus a release-wide audit (Phase 23). |
| 12 | Reported test counts (e.g., ~230 backend, 72 frontend, 27 e2e); `composer audit` shows 1 ignored advisory (`PROGRESS`) | Claimed → **Re-run, do not trust counts** | Re-run all suites in clean containers against PostgreSQL/Redis, repeatedly/parallel; record actual counts and skipped tests with reasons (Correction 25.3). |
| 13 | `PROGRESS.md` Phase 20 still titled "Citrus Billing Engine & commissions" tracking the **pre-correction** roadmap (`PROGRESS`) | Claimed → **Contradicted / superseded** | The pre-correction §27 roadmap is replaced by the corrected roadmap (Section 79–80). Phase 20 is decomposed into 20A–20H (subscription-first). Rewrite `PROGRESS.md` phase list during Phase V/Section 25 progress correction. |

**Rule:** Any row that Phase V finds `Contradicted` or materially `Partially verified` becomes a C0/C1 item in the remediation register (Section 5) before dependent feature work.

### 4.1 Phase V Verified Outcomes (evidence-based, 2026-06-21)

Statuses: `confirmed` / `partially_confirmed` / `contradicted` / `not_verifiable`. Full evidence per row in `docs/verification/as-built-discrepancies.md`.

| # | Claim (short) | Verified status | Evidence | Owning phase |
|---|---|---|---|---|
| 1 | Laravel 11 / advisory ignored | **contradicted (superseded)** — Laravel 12.62.0, PHP 8.3.31, advisory removed, audit clean | versions.txt; composer.lock; container | R1 (REM-DEP-001, *partial*) |
| 2 | Magic Link hashing/expiry/atomic + idle timeout | **confirmed** | MagicLinkTokenService; auth tests | verified |
| 3 | MFA placeholder only | **confirmed** (no privileged MFA) | no mfa table/route | R3 (REM-MFA-001) |
| 4 | Tenant model; self-registration only; no SA create route | **confirmed** | route:list; NoPlatformMerchantCreationTest | verified |
| 5 | Branch/staff schema; hashed invite; lifecycle revocation | **partially_confirmed** (closure stubs deferred) | schema; HR tests | 16–18/20 (feature) |
| 6 | Roles/permissions 54×8; deny-beats-grant | **partially_confirmed** (matrix < §19) | PermissionMatrixTest | 19 (REM-PERM-001) |
| 7 | audit_logs append-only hash-chained; trigger | **confirmed** (immutability runtime-proven); coverage partial | schema.sql; DB trigger proof | R2 (REM-AUD-001) |
| 8 | Tenant isolation scopes/binding/404-403 | **confirmed** (Phase 9 merged); **structure partial** (branch tables lack merchant_id) | Isolation tests; coverage query | R5 (REM-TEN-001) |
| 9 | idempotency_keys (prior invalid schema) | **contradicted** — table absent entirely | clean schema (grep=0) | R4 (REM-IDEMP-001) |
| 10 | Money/redaction/correlation/limiters/health | **confirmed** | Phase 3 tests | verified; readiness → R7 |
| 11 | Frontend foundation | **confirmed** | typecheck/vitest/e2e/build | verified |
| 12 | Reported test counts; 1 ignored advisory | **confirmed** (238/4 BE, 72 FE, 27 e2e) — advisory sub-claim **contradicted** (0 advisories) | test-results.md | verified |
| 13 | PROGRESS §27 roadmap (pre-correction) | **contradicted/superseded** | PROGRESS/CHANGELOG/CLAUDE.md | Phase V (REM-DOC-001) |

**Gate (historical Phase V finding, 2026-06-21):** at Phase V completion the pre-feature remediation gate (§5.4) was **closed-not-satisfied**; the then-open C0 items (REM-DEP-001 partial, REM-AUD-001, REM-MFA-001, REM-IDEMP-001, REM-TEN-001, REM-SESS-001) and C1 REM-OPS-001 blocked every Section 80 feature phase.

**Gate (current status):** the pre-feature remediation gate is **closed-satisfied**. Phases R1–R7 completed and every `PRE_FEATURE_REMEDIATION` register item is `verified_complete`; the completion evidence is `docs/proof/pre-feature-remediation-gate-closure.md`, `docs/remediation/pre-feature-completion-report.md`, and the per-item entries in `docs/remediation/register.yaml`. The historical finding above is preserved as the Phase V evidence record and is not deleted; it no longer describes the current gate.

---

## 5. Confirmed Remediation Register (Classification and Gate)

### 5.1 Classification (replaces any B/S/V scheme)

```text
C0 — Security or data-integrity blocker. Must be fixed, merged, and demonstrated before any new feature phase.
C1 — Required correction to completed/claimed work. Must be completed before the affected subsystem progresses.
C2 — Verified optimization/enhancement that does not correct a defect. Scheduled later only after evidence proves omission
     does not violate scope, security, data integrity, operability, or production readiness.
N  — Investigated and proven non-issue. Requires a written decision record and a passing guard test where regression is possible.
```

Every register item additionally carries a **gating category** that determines *when* it must complete, so that a feature-owned obligation is never required to finish before its owning feature phase is allowed to start:

```text
PRE_FEATURE_REMEDIATION   — Phase V, R1–R7, and every C0/C1 defect discovered in ALREADY-IMPLEMENTED work
                            that must be corrected before any dependent feature delivery. Gated by Section 5.4.
FEATURE_DELIVERY_OBLIGATION — New-feature DB completeness, feature-owned schema additions, permission additions,
                            feature state machines, payment/Wallet-billing/compensation/SMS/reporting implementation, and
                            production-deployment work. These do NOT correct existing implemented code; each is
                            gated by its OWN owning phase (Section 5.4a), not by the pre-feature gate.
```

Replacement gate rule (supersedes any "all C0/C1 before features" wording):

```text
No feature-delivery phase may begin until Phase V, R1–R7, and every C0/C1 defect affecting
ALREADY-IMPLEMENTED work are verified complete.
A FEATURE_DELIVERY_OBLIGATION must be completed before its OWNING phase may exit and before any
DEPENDENT phase may begin — it is never required to complete before its owning phase starts.
```

A C0/C1 classification expresses severity; the gating category expresses sequencing. A C0 obligation may legitimately be a `FEATURE_DELIVERY_OBLIGATION` (for example Wallet settlement in Phase 20D‑W): it must still be fully built, tested, and demonstrated before its phase exits and before dependents begin, but it cannot block the start of its own phase.

Rules: a finding is never downgraded merely to preserve schedule; the requirement to fix existing production defects before new dependent work begins is never weakened; N items require an ADR and a guard test.

### 5.2 Remediation Register Location and Fields

Create `docs/remediation/register.yaml`. Each item carries:

```text
id | classification | scope_requirement | repository_evidence | root_cause |
affected_files_tables_routes | security_data_business_impact | precise_correction |
migration_impact | test_plan | proof_artifact | owner | completion_commit | reviewer | status
```

### 5.3 Confirmed Remediation Items (seeded from the corrections; Phase V may add more)

| ID | Class | Gating | Title | Owning phase | Source correction |
|---|---|---|---|---|---|
| REM-V-001 | C0 | PRE_FEATURE | As-built verification baseline + discrepancy register | Phase V | 25 |
| REM-DEP-001 | C0 | PRE_FEATURE | Upgrade Laravel to 12.60+, pin PHP 8.3 across all images, remove advisory ignore, CR/LF email regression tests | R1 | 5 |
| REM-AUD-001 | C0 | PRE_FEATURE | Core audit-event completeness + hash-chain verifier + masked read API/policies | R2 | 6 (gate), 22 (combined) |
| REM-MFA-001 | C0 | PRE_FEATURE | TOTP enrollment, encrypted secrets, hashed recovery codes, mandatory MFA (Super Admin/Merchant Admin/Finance), step-up freshness | R3 | 7 (workstream) |
| REM-IDEMP-001 | C0 | PRE_FEATURE | Corrected `idempotency_keys` schema + middleware + financial-route coverage test + provider-callback dedupe seams | R4 | 3 |
| REM-TEN-001 | C0 | PRE_FEATURE | Add `merchant_id` to branch-owned tables where missing; backfill; indexes/constraints; static-analysis/source-scan; route-binding tenant safety | R5 | 7 (workstream) |
| REM-SESS-001 | C0 | PRE_FEATURE | Session/token/Magic-Link/invitation/cache revocation; active-membership+role check on every authenticated request; mid-session suspension tests; 404/403 posture | R6 | 7 (workstream) |
| REM-OPS-001 | C1 | PRE_FEATURE | Split liveness/readiness; production deps required in readiness; isolate Redis/cache/rate-limit prefixes in tests; align PHP/Node/Composer; brand contrast ADR | R7 | 7 (workstream) |
| REM-DDL-001 | C1 | FEATURE_DELIVERY | Per-table data-dictionary completeness (the false "full DDL exists" claim is already removed in §13); `DataDictionaryCoverageTest`; `TenantColumnCoverageTest`; PostgreSQL migration tests | Phase 13 substrate + each owning phase | 1, 2 |
| REM-ENT-001 | C1 | FEATURE_DELIVERY | Add missing entities: `commission_rules`, `commission_ledger`, `salary_ledger`, `finance_exports`, `free_period_offers`, file + SMS records, reconciliation records, plus the Correction-3 scope entities | owning feature phases | 2 |
| REM-ENUM-001 | C1 | FEATURE_DELIVERY | Canonical billing-mode enum across PHP/DB/API/TS/seed/audit/tests; expand-and-contract data migration | 20A/20E | 4 |
| REM-ROUTE-001 | C1 | FEATURE_DELIVERY | Route classifications + `RouteSecurityContractTest`; class-specific acceptance matrix | Phase 10 | 10, 11 |
| REM-MIG-001 | C1 | FEATURE_DELIVERY | Expand-and-contract migration strategy + migration manifest; remove reliance on destructive `down()` | Phase 10 + all phases | 12 |
| REM-FILE-001 | C1 | FEATURE_DELIVERY | File/media foundation (schema, pipeline, scanning, signed downloads, authorization, jobs, tests) | Phase 10F | 13 |
| REM-WALLET-001 | C0 | FEATURE_DELIVERY | Complete Wallet-orchestrated billing-payment domain (merchant-account sync, invoice registration, STK, verified webhooks, exactly-once application, reversals, reconciliation exceptions) + partner-webhook security; no manual Super-Admin payment recording; no direct provider integration | 20D‑W | 14, 15 (as amended by ADR‑012) |
| REM-RE-001 | C0 | FEATURE_DELIVERY | Citrus R&E source-product integration: non-blocking referral capture, transactional outbox + signed delivery, subscription-fact emission, final-authority qualification engine, inbound reconciliation surface | 21R‑A/21R‑B | ADR‑013; R&E scope §11 |
| REM-PERM-001 | C1 | FEATURE_DELIVERY | Complete canonical permission matrix + parity tests + role-boundary tests | Phase 19 + owning phases | 16 |
| REM-SM-001 | C1 | FEATURE_DELIVERY | Complete state-machine catalogue with transition actions; no direct status assignment | each owning phase | 17 |
| REM-PAY-001 | C1 | FEATURE_DELIVERY | Full merchant-client payment domain (methods, allocation, maker/checker, receipts, refunds) | 18A/18B | 18 |
| REM-COMP-001 | C1 | FEATURE_DELIVERY | Full compensation domain (models, effective dating, ledgers, payouts, earnings) | 20F/20G/20H | 19 |
| REM-SMS-001 | C1 | FEATURE_DELIVERY | Personnel served-client SMS with contact-protection controls | 21S | 20 |
| REM-REP-001 | C1 | FEATURE_DELIVERY | Report catalogue with formulas, scope, masking, scheduled PDFs | 21N + owning phases | 21 |
| REM-SCR-001 | C1 | FEATURE_DELIVERY | Complete screen inventory + per-screen specifications | Phase 11 + owning phases | 22 |
| REM-TRACE-001 | C1 | FEATURE_DELIVERY | Requirement traceability matrix + CI enforcement | continuous; gated at Phase 23 | 23 |
| REM-PRODOPS-001 | C1 | FEATURE_DELIVERY | Measurable SLOs, topology, backup/DR, alerts, runbooks | Phase 25 | 24 |

### 5.4 Pre-Feature Remediation Gate (PRE_FEATURE_REMEDIATION only)

The **pre-feature** gate covers only `PRE_FEATURE_REMEDIATION` items (Phase V, R1–R7, and any C0/C1 defect Phase V finds in already-implemented work). No feature-delivery phase may begin until it is satisfied:

1. Every `PRE_FEATURE_REMEDIATION` row is `verified_complete`. (Feature-delivery obligations are **not** part of this gate — see §5.4a.)
2. Every required migration in those items has been applied and tested against a production-like PostgreSQL backup copy using the expand-and-contract strategy.
3. Full backend, frontend, browser, isolation, security, and dependency checks pass in clean containers.
4. The required ADRs are merged.
5. CI evidence is attached to each pre-feature item.
6. A reviewer signs a pre-feature remediation completion report.
7. `PROGRESS.md` and `CHANGELOG.md` are regenerated with actual commit references rather than narrative assertions.

### 5.4a Feature-Delivery Obligation Gate (per owning phase)

Each `FEATURE_DELIVERY_OBLIGATION` (including C0 obligations such as REM-WALLET-001) is gated by its own owning phase, not by §5.4: it must be `verified_complete` with all required tests and proof **before its owning phase may exit and before any dependent phase may begin**, and it is never required to complete before its owning phase starts. A feature phase's exit criteria (Section 82) include every feature-delivery obligation mapped to that phase.

### 5.5 Automated Enforcement

Add a CI script that fails when: a `PRE_FEATURE_REMEDIATION` item is not complete while a feature-delivery PR is open (pre-feature gate closed); a `FEATURE_DELIVERY_OBLIGATION` mapped to the PR's owning phase is incomplete at that phase's exit; or a required evidence path is missing. Every feature-phase PR declares `depends_on_pre_feature_gate: true` (verifying the §5.4 gate file) and lists the `FEATURE_DELIVERY_OBLIGATION` IDs it must satisfy to exit (verified against §5.4a). The enforcement never blocks a feature phase from *starting* on account of one of its own feature-delivery obligations.

---

## 6. Operating Manifesto (applies to every phase)

### 6.1 Prove the Problem
For every task, the agent records: what must be built/changed/removed/migrated/verified; why; the authoritative requirement satisfied; the evidence the need exists; the affected code/DB/routes/services/components/jobs/infra; the failure/vulnerability/financial/isolation/operational/UX defect avoided; the implementation approach; the tests and proof required. A progress note is not proof. A passing happy-path test is not proof of authorization, isolation, concurrency safety, or failure recovery.

### 6.2 Root-Cause Analysis
Before any fix: inspect code/config; reproduce/prove where feasible; identify the true root cause and distinguish it from symptoms; enumerate every affected file/class/method/record/constraint/route/policy/middleware/job/component/test/service/workflow; check whether the same defect pattern exists elsewhere; document intended behavior from scope; avoid superficial/localized patches.

### 6.3 Fix with Precision
Prohibited: broad rewrites without evidence; unrelated refactoring inside fixes; styling-only fixes for logic defects; frontend-only fixes for backend authorization/isolation; backend checks without DB integrity where DB enforcement is appropriate; temporary hacks; disabled/weakened/skipped tests; suppressed errors without approved justification; duplicated business logic; unverified assumptions; silent failure handling; catch-all exception handling that hides cause; security controls in the frontend only; financial state changes without transactions/locking/idempotency/audit; tenant-owned queries without tenant enforcement; branch-owned queries without branch enforcement; personnel own-scope enforced only by route naming or UI filtering. Each fix defines affected layers, migration strategy, backward compatibility, rollout/rollback, regression tests, and completion evidence.

### 6.4 Test Thoroughly
Each significant change includes the relevant combination of: unit, domain-service, feature, API, request-validation, authentication, authorization, role/permission, tenant-isolation, branch-scope, personnel-own-scope, plan-entitlement, billing-status, operational-status, period-lock, idempotency, concurrency/locking, duplicate-callback/replay, ledger-integrity, audit-chain, notification, queue-job, scheduler, file-upload-security, frontend component/store/composable, browser/e2e, responsive, dark-mode, accessibility, security-regression, deployment-smoke, and backup-restore tests. Cases must include success, denied, invalid, duplicate, expired, suspended, cross-tenant, cross-branch, unauthorized, concurrent, retry, provider-failure, partial-failure, and recovery.

### 6.5 Demonstrate Resolution
Each completed unit/phase produces, where applicable: test commands + results; CI results; API request/response examples; schema/constraint/index verification; transaction/locking verification; authorization-denial and cross-tenant/branch non-disclosure proof; own-scope/entitlement/billing-status/period-lock denial proof; idempotent-replay and duplicate-callback proof; audit-event and chain-verification proof; queue execution proof; browser screenshots; responsive/light+dark/accessibility verification; edge-case verification; deployment smoke and backup-restore evidence; explicit exit criteria. A phase is complete only when acceptance criteria are met and evidence is produced — never on compilation alone.

---

## 7. Assumptions and Resolved Decisions

| ID | Topic | Resolved decision (controlling source) |
|---|---|---|
| A-01 | Currency/money | `bigint` minor units + `char(3)` uppercase currency; KES default; no float. (Correction 1.3, §9) |
| A-02 | Public identifiers | Every externally referenced row exposes an immutable ULID (`char(26)`) or native UUID; never expose sequential internal keys. (Correction 1.3; IDE rule 19) |
| A-03 | Auth model | Passwordless Magic Link + Sanctum stateful sessions; `users.password` nullable; seven-check eligibility. (Scope §4 auth rules; Phase 5 as-built) |
| A-04 | Timezone/date boundaries | `Africa/Nairobi` business dates; `timestamptz` events; `date` for pay-period boundaries. (Correction 19.5, 21.2) |
| A-05 | Billing posture | Subscription-first launch; percentage platform-fee engine built but inactive unless configured. (Scope §2.3, §6; Correction 8) |
| A-06 | Billing-payment recovery | Self-service STK + PayBill/Till payment against the Wallet structured reference, applied from verified Wallet webhooks; **no** Super-Admin manual payment recording. (Scope §10; Correction 14; as amended by ADR‑012) |
| A-07 | Merchant creation | Self-registration only; Super Admin governs post-creation; forbidden creation routes must 404/not exist. (Scope §11; Section 2.1) |
| A-08 | Migrations | Expand-and-contract; forward-repair for irreversible production changes; no destructive `down()` as production rollback. (Correction 12) |
| A-09 | Framework | Target Laravel 12.60+; verify exact patched version from lock files; do not call any Laravel version "LTS". (Correction 5; ADR-001) |
| A-10 | Overpayment semantics | Merchant→Servana billing overpayment creates account credit; merchant-client service overpayment is **rejected by default** at launch. (Correction 14.4, 18.4) |
| A-11 | Salary-on-suspension | **Settled default `suspension_salary_policy = continue`.** Merchants may set a **prospective** override to `pause` (effective from a timestamp; never rewrites already-accrued salary); resumption and termination behaviors are defined in §60. Where `SERVANA COMBINED.txt` specifies a different settled value, the scope value controls and is documented as such. (Correction 8 / scope §19.5) |
| A-12 | Receipt policy | One receipt per validation event containing component methods/amounts; receipts only after validation; reissue creates a new tracking record. (Correction 17.3, 18.7) |
| A-13 | Brand contrast | Primary action uses `text-brand-deep` on brand orange (WCAG AA); recorded in ADR-009 (Phase R7). (CHANGELOG Phase 4) |
| A‑14 | Payment orchestration | All payment-provider interaction (credentials, STK submission, C2B validation/confirmation, provider identifiers, receipt uniqueness, provider status queries, provider/bank reconciliation, provider exceptions, financial double-entry ledger) is owned by **Wallet by Citrus**. Servana integrates through Wallet's product API and signed webhooks only. (ADR‑012; Wallet scope §2, §4, §21–§22, §34–§35.) |
| A‑15 | Wallet availability sequencing | Phases 20A–20C proceed with **no** Wallet dependency. Phase 20D‑W requires the **Wallet Servana Collections Slice** to pass External Gate W (§80.2) in sandbox before integration testing and in production before launch. If Gate W is not open when 20C exits, the agent proceeds to 20E/20F (no Wallet dependency) and returns to 20D‑W when the gate opens; 20D‑W must complete before Phase 25 exit. |
| A‑16 | Referral integration authority | Servana is a **source product** of Citrus R&E. Servana emits signed, idempotent product events (R&E dev plan §11.7–§11.8), captures referral codes at self-registration without ever blocking registration, and is **final authority** for its own `activity.qualification_decided` decisions using the Servana active-use rule (≥10 completed service sessions, ≥3 validated merchant-client invoices in the qualification period, subscription obligation fully paid, no fraud/manual suspension — R&E scope §11.2). Servana never stores referrer PII, reward amounts, or payout data. (ADR‑013.) |
| A‑17 | Personnel payout funds | Unchanged: Servana records personnel payout runs but does not move personnel funds at launch. Wallet **payout** integration (B2C, bulk payouts) is explicitly out of scope for launch; it is reserved as a post-launch enhancement gated on Wallet Phase Three. R&E referrer payouts are R&E's responsibility entirely and never touch Servana. |
| A‑18 | Qualification calendar | R&E qualification periods for Servana are **calendar months in `Africa/Nairobi`** (consistent with A‑04). The monthly evaluation runs after period close plus a configurable clearing-grace window (default 5 days) so that `subscription.payment_cleared` facts for the period can arrive first. Late clearing after evaluation triggers a corrected decision (higher `decision_version`), never a silent edit. |
| A‑19 | Attribution confirmation timing | Referral capture at registration stores an immutable local snapshot and enqueues validation/confirmation asynchronously. Registration **never** blocks or fails because R&E is slow, down, or rejects the code. A rejected/expired code results in `snapshot_status='rejected'` and no further events; the merchant proceeds unreferred. (R&E dev plan integration case: source product stores the snapshot and retries the signed event; merchant registration continues per product policy.) |

Any assumption that the product owner has not settled is implemented only after the configuration value is adopted; otherwise the agent records a blocking ambiguity. (A-11 is now settled with a concrete default and no longer requires a pre-implementation decision.)

---

## 8. Architecture Decision Records (ADRs)

ADRs live in `docs/architecture/adr/NNNN-title.md`. The following must exist and be merged before or within the phase noted.

| ADR | Title | Decision summary | Required by |
|---|---|---|---|
| ADR-001 | Framework upgrade target | Upgrade to Laravel 12.60+ on PHP 8.3 pinned everywhere; remove suppressed advisory; verify exact version from lock files. | R1 |
| ADR-002 | Tenancy enforcement model | Global scopes + scoped route binding + `merchant_id`/`branch_id` ownership + job tenant context; `withoutTenancy()` is the only sanctioned escape, banned outside Tenancy/Platform by static analysis. | R5 |
| ADR-003 | Idempotency + replay protection | Corrected `idempotency_keys` schema; deterministic scope; SHA-256 key hash; encrypted replay-safe responses; provider dedupe via unique provider IDs/receipts. | R4 |
| ADR-004 | Migration strategy | Expand-and-contract; migration manifest; forward-repair; image rollback only within schema compatibility; restoration only via tested PITR. | Phase 10 |
| ADR-005 | Money + rounding | Integer minor units. **Single deterministic rule: round half up** to the nearest integer minor unit, applied everywhere (percentage platform fees, commission, preferred-personnel percentage fees, promotions, discounts, future tax, allocation residuals, negative reversals, adjustments, reports, frontend previews). Proportional-allocation **residual minor units are assigned by the largest-remainder method, ties broken by ascending source-line ULID.** A reversal stores the **exact negative of the original stored amount** (never a recomputed percentage). | Phase 13 / 20E / 20G |
| ADR-006 | M-Pesa callback security (**superseded by ADR‑015**) | Defense-in-depth using only provider-supported controls; no invented HMAC. Retained only as history of the abandoned direct-Daraja design: Wallet signs its webhooks, so HMAC verification is mandatory (SUP‑03). | — (historical) |
| ADR-007 | Maker/checker + period locks | Front Office maker, Finance checker; Finance owns period locks; Merchant Admin only approves exceptional reopen; same user cannot maker+checker where separation applies. | 18A/18B |
| ADR-008 | Audit immutability + chain | Append-only `audit_logs` with DB trigger and hash chain; verifier command; masked read API; branch/platform policies. | R2 / 19 |
| ADR-009 | Brand contrast tokens | Primary/CTA contrast meets WCAG AA; documents the contrast decision and token values. | R7 |
| ADR-010 | Personnel contact protection | No export channel for personnel contacts; bulk endpoints never return full phone lists; guessed export routes 404 + high-severity audit. | 21S |
| ADR-011 | Plan-price source of truth | `subscription_plan_prices` is the sole price source; invoices capture the price at issuance; no automatic grandfathering; scheduled prices via effective dates. (PART B §6B) | 20A/20B |
| ADR-012 | Wallet by Citrus payment-orchestration boundary | All merchant→Citrus collections flow through Wallet; Servana keeps billing truth, Wallet keeps money-movement truth; no provider logic in Servana. Full record in §8.1. | Plan-adoption PR / 20D‑W |
| ADR-013 | Citrus R&E integration authority + event contract | Servana is a source product: referral capture, signed outbox event emission, reconciliation surface, final-authority qualification decisions; no reward logic in Servana. Full record in §8.1. | Plan-adoption PR / 21R‑A, 21R‑B |
| ADR-014 | Structured payment reference + invoice→Wallet registration | Each issued subscription invoice registers a Wallet payment resource; Wallet issues the immutable `SRV-PAY-…` reference stored as `account_reference`; lazy-with-eager-preference registration. Full record in §8.1. | 20B / 20D‑W |
| ADR-015 | Cross-platform machine identity + webhook signing | Four machine identities (Servana→Wallet, Wallet→Servana, Servana→R&E, R&E→Servana) with algorithm-aware signing contracts (verification selected by algorithm identifier + key ID + contract version; HMAC or asymmetric per each partner's authoritative contract), dual-key rotation, disjoint per-environment secrets. Full record in §8.1. | 20D‑W / 21R‑A |

### 8.1 ADR‑012 – ADR‑015 (full decision records)

**ADR‑012 — Wallet by Citrus as the Central Payment-Orchestration Boundary.**
- **Status:** Accepted. **Supersedes for Servana:** the v3 §§55–58 direct-Daraja design (see §1.2); marks ADR‑006 superseded-by‑ADR‑015.
- **Decision:** Servana integrates all merchant→Citrus subscription and platform-fee collections through Wallet by Citrus. Servana holds business-billing truth (plans, prices, entitlements, subscriptions, invoices, promotions, grace/suspension, invoice allocation, billing credits, billing-status projection). Wallet holds money-movement truth (provider credentials/accounts, routing, STK submission, C2B endpoints, raw callbacks, provider identifiers, receipt uniqueness, replay protection, transaction status, settlement, provider/bank reconciliation, double-entry ledger, signed product webhooks).
- **Servana stores from Wallet (local projection only):** `wallet_payment_id`, `wallet_attempt_id`, `wallet_event_id`s, payment/settlement status projections, `provider_method`, `provider_reference_masked`, amounts, timestamps. **Servana never stores:** raw provider callbacks, Daraja credentials/tokens, provider callback endpoints, provider reconciliation records, provider balances, Wallet ledger rows, provider-specific state machines.
- **Consequences:** Phase 20D is defined as 20D‑W; §13.11 carries Wallet-shaped tables; the callback path is `Safaricom → Wallet → Wallet ledger/recon → signed webhook → Servana invoice allocation → Servana billing-status update`; Servana gains an external launch dependency (Gate W, §80.2) mitigated by sequencing (A‑15).

**ADR‑013 — Citrus Refer & Earn Integration Authority and Event Contract.**
- **Status:** Accepted.
- **Decision:** Servana is a source product of the central R&E platform and retains exactly the four product-side responsibilities the R&E architecture assigns (R&E dev plan §1.1): (1) product-native referral capture at self-registration with local immutable snapshot; (2) signed, idempotent product-event emission via transactional outbox for the R&E-required event catalogue (§58B.1); (3) an authenticated reconciliation surface for fact re-verification (§58B.4); (4) final-authority activity-qualification decisions computed inside Servana from Servana facts, emitted as `activity.qualification_decided`/`activity.qualification_corrected` with monotonically increasing `decision_version`. Servana implements **no** referrer accounts, reward calculation, reward ledger, or referrer payouts.
- **Consequences:** two new phases (21R‑A, 21R‑B); new bounded context `app/Domain/Integrations/ReferEarn` (§10.1); new tables (§13.17); the Phase 6-built registration gains a small, additive referral-capture extension delivered in 21R‑A (verified non-breaking against as-built Phase 6 tests).

**ADR‑014 — Structured Payment Reference and Invoice→Wallet Registration Lifecycle.**
- **Status:** Accepted.
- **Decision:** Each issued `subscription_invoice` is registered with Wallet as a payment resource (`POST /api/v1/payments`) carrying `external_reference = invoice ULID` (unique within the Servana application per Wallet §20.2), expected amount = invoice `balance_minor` at registration, currency, and the owning Wallet merchant account. Wallet returns the immutable structured reference `SRV-PAY-<id>` (Wallet §21.1), which Servana stores as `subscription_invoices.account_reference` and displays in PayBill/Till instructions. Registration is **lazy-with-eager-preference**: in Phase 20D‑W, newly issued invoices are registered after commit and existing unregistered payable invoices are idempotently backfilled; registration is guaranteed before any payment instruction or STK initiation is served (both endpoints trigger and await registration if missing, §56.1). Phase 20B ships only the nullable projection columns and no registration mechanism (§49). Registration is idempotent (`Idempotency-Key = srv:pay-reg:{invoice_ulid}`). Amount changes from partial payments do **not** re-register (Wallet tracks received vs expected; partial receipt is a supported Wallet state).
- **Rejected alternative:** registering at first payment intent only — rejected because C2B PayBill payment requires a valid reference the merchant may use without opening Servana; instructions must be printable on the issued invoice PDF once registered.

**ADR‑015 — Cross-Platform Machine Identity and Webhook Signing.**
- **Status:** Accepted. Supersedes ADR‑006 (Daraja-specific rationale no longer applies; both partner platforms support real signatures).
- **Decision:** (a) Servana→Wallet calls authenticate with per-environment machine credentials issued by Wallet's application registry (Wallet §7.4, §14); transport is TLS with certificate verification; requests carry `Idempotency-Key` on every money-adjacent create. (b) Wallet→Servana webhooks are verified per §9 rule 21 using an **algorithm-aware** verifier: Wallet scope §35 permits HMAC **or** asymmetric signatures, so Servana selects the verification routine by the published algorithm identifier + key ID + contract version (pinned at Gate W, §80.2) and never hardcodes HMAC-SHA-256; the contract also supplies timestamp, replay window, per-application credentials, and rotation support. Constant-time comparison applies where the algorithm is secret-keyed; credentials and signatures are never logged. (c) Servana→R&E events use the `X-Citrus-*` header contract and canonical signing string verbatim from R&E dev plan §11.7. (d) R&E→Servana reconciliation requests use the same canonical construction with a distinct inbound secret. All secrets: secrets-manager custody, disjoint per environment, rotation with overlapping dual-key windows (old + new accepted during the rotation window; key ID selects the secret) per the runbooks in §77.1.

---

## 9. Non-Negotiable Security Rules

These rules are enforced server-side and tested. Frontend never substitutes for any of them.

1. **Tenant isolation:** every tenant-owned query is `merchant_id`-scoped via global scope; foreign-tenant resource access returns 404 with no existence leak and writes a high-severity `unauthorized_access` audit row.
2. **Branch isolation:** branch-owned resources additionally require an active branch assignment; same-tenant out-of-branch access returns the documented 403 posture.
3. **Personnel own-scope:** own-scope resources derive `staff_profile_id` from the authenticated membership; no route accepts another personnel identifier; guessed cross-personnel routes 404 + audit.
4. **Authorization order (every protected action):** authenticated+active session → MFA where role requires → tenant/platform context → active membership+role → branch assignment (if branch-owned) → permission resolution → resource tenant/branch ownership → personnel own-scope → billing-status mutation gate → plan entitlement → financial-period lock → maker/checker incompatibility → step-up freshness → request validation → idempotency+transactional execution → audit event. (Correction 16.4)
5. **MFA + step-up:** TOTP mandatory for Super Administrator, Merchant Administrator, and Finance; fresh step-up required for billing configuration, refund finalization, period reopen, payout approval, payout mark-paid, reconciliation resolution, and sensitive compensation changes.
6. **Magic Links:** hashed at rest (SHA-256), single-use, short expiry; invalidated on logout, suspension/deactivation, and replacement.
7. **Session/token revocation:** suspension/deactivation invalidates sessions, tokens, unconsumed Magic Links, and pending invitations; the next authenticated request after suspension is denied.
8. **Rate limiting + enumeration resistance:** purpose-specific limiters; uniform accepted responses for enumeration-sensitive public flows; structured 429 with retry info.
9. **CSRF/XSS/SQLi/mass-assignment/SSRF:** Sanctum CSRF for browser session mutations; output escaping; parameterized queries only (raw-SQL concatenation banned by static analysis); Form Request allowlists; outbound request allowlisting where servers fetch URLs.
10. **File uploads:** authorize purpose before bytes; per-purpose size/extension allowlists; private quarantine; magic-byte MIME detection (never trust browser MIME/filename); reject executables/scripts/active-SVG/macro-office/polyglot unless an approved sanitizer exists; ClamAV scan; signed short-lived downloads through an authorized endpoint; never expose storage paths.
11. **Secrets:** stored in a secrets manager/encrypted store; never in source, `.env.example`, frontend assets, CI logs, or audit values; rotation runbooks; provider credentials restricted to the integration service identity.
12. **Encryption + masking:** TLS in transit; encryption at rest for sensitive payloads (raw callbacks, original filenames, phone/reference display fields, TOTP secrets, stored idempotent responses); PII masked at read time per permission.
13. **Log redaction:** never log passwords, Magic-Link tokens, MFA secrets, recovery codes, session IDs, partner credentials (Wallet API credentials/webhook secrets, R&E signing keys), raw webhook/callback payloads, phone numbers, payment references, signed URL tokens, or email headers (full binding list: §24.5).
14. **Audit immutability + chain:** append-only with DB trigger; hash chain; verifier; immutable financial/compensation/audit history.
15. **Idempotency + replay:** every financial mutation requires `Idempotency-Key`; webhooks add provider-unique IDs and receipt uniqueness; duplicate/concurrent requests produce exactly one effect.
16. **Maker/checker + financial integrity:** transactions, row locks, immutable ledgers, reversal/adjustment-only corrections, period-lock checks (Sections 9 and 25).
17. **Export controls:** finance/audit exports are async, reason-gated, permission-masked, signed, expiring, download-counted, and audited; **no** personnel contact-export channel exists.
18. **Dependency/secret/container scanning:** clean `composer audit`/`npm audit`, `gitleaks`, and image scans are required; suppressions require an approved, time-bound rationale and a guard test.
19. **Incident response:** severities, escalation, runbooks; never repair financial/audit data through ad hoc SQL without a reviewed script and before/after evidence (Section 78).
20. **No direct provider integration.** The Servana codebase must contain no Safaricom/Daraja/bank/card credentials, SDKs, OAuth token handling, callback routes, or provider-payload parsers. Enforced by static analysis (banned namespaces/strings: `daraja`, `safaricom`, `mpesa_consumer_key`, `oauth/v1/generate`, provider hostnames) and by `NoDirectProviderIntegrationTest`, which fails if any route matches `*/mpesa/*` callbacks or any config key under `services.mpesa.*` exists.
21. **Inbound partner webhooks are verified before parse — and before any canonical storage.** Every Wallet webhook and every R&E reconciliation request must pass, in order: HTTPS/transport requirement, strict content-type, body-size limit (64 KB default), required-header syntax validation, key-ID resolution without key-inventory disclosure, timestamp tolerance (±300 s), content-SHA‑256 match, constant-time signature verification (selected by algorithm identifier + key ID + contract version, per ADR‑015), **then** JSON parsing, **then** event schema validation, **then** canonical first-seen `wallet_event_id` insertion into the verified inbox (the DB unique constraint is the replay decision), fast acknowledgement, and asynchronous processing. An unverified request must never occupy the canonical verified `wallet_event_id` uniqueness constraint — replay/first-seen insertion happens only **after** signature verification succeeds, so a forged request cannot squat a real event ID. A failed verification writes a high-severity security audit event carrying the body/request hash and minimal non-sensitive metadata, emits metrics/alerts, and returns a uniform 401 with no detail about which check failed; it creates **no** verified-inbox row.
22. **Outbound event integrity.** Every event Servana sends to R&E is signed per the canonical string in R&E dev plan §11.7 (`METHOD\nPATH\nTIMESTAMP\nNONCE\nCONTENT_SHA256\nEVENT_ID\nEVENT_TYPE\nEVENT_VERSION`), carries a ULID `X-Citrus-Event-Id` generated once at outbox-insert time (stable across retries), and is retried with the **same** event ID and body hash. Mutating a queued outbox payload after insert is forbidden and prevented by an append-only trigger.
23. **Cross-platform data minimization.** Servana never transmits to R&E: client names/phones, service-session content, staff PII, invoice line detail, or raw payment references. Events carry only the minimal facts defined in §58B.2. Servana never persists from Wallet: raw provider payloads, unmasked MSISDNs of counterparties other than the initiating merchant user's own submitted phone (which Servana already holds encrypted), provider credentials, or ledger internals. Servana never persists from R&E: referrer identity, payout methods, or reward amounts.
24. **Machine-credential custody.** Wallet product-API credentials, Wallet webhook secrets, R&E service-account signing keys, and the R&E→Servana inbound verification secret live only in the secrets manager under per-environment paths (`servana/{env}/wallet/*`, `servana/{env}/refer-earn/*`); they are loaded at runtime, never cached to disk, never logged (§24.5), rotated per the runbooks in §77.1, and each rotation writes an `integration.credential_rotated` critical audit event. Sandbox, staging, and production use disjoint credentials; a startup guard refuses to boot production with a key ID carrying a `sandbox`/`staging` prefix.

### 9.1 Per-Workflow Attacker Model (applied in owning phases)
For each sensitive workflow the owning phase documents what a malicious tenant user, over-privileged staff member, suspended user, compromised email account, replayed request, duplicated/forged partner webhook, or concurrent request would attempt and how the design prevents it. Representative cases: a suspended Finance user retaining a session (denied at request-time membership check); a duplicated/replayed Wallet webhook (signature verification first, then unique first-seen `wallet_event_id` + idempotent apply); two Front Office users recording against the same invoice balance concurrently (row lock + validated-amount check); a Personnel user enumerating served-client phones via crafted routes (own-scope derivation + masked display + 404+audit on export-shaped routes); a malicious tenant requesting a foreign file by ULID (tenant/branch/purpose checks + 404).

Integration-specific attacker models (verified in Phases 20D‑W/21R‑A/21R‑B and re-verified at Phase 23):

| Attacker scenario | Design that defeats it |
|---|---|
| Forged "payment succeeded" webhook POSTed to Servana | Algorithm-aware signature verification with per-application credentials (ADR‑015); unknown key ID → uniform 401 + high-severity security audit (body/request hash + minimal metadata; **no inbox row is created**, so a forged request can never squat a `wallet_event_id` — Correction 14.7); no invoice mutation path exists without a verified, first-seen `wallet_event_id` that correlates to a registered Wallet payment for that exact invoice. |
| Replayed genuine Wallet webhook (captured and re-sent) | Timestamp tolerance + unique `wallet_event_id` in `wallet_webhook_inbox` (DB unique constraint is the final protection); replay returns 200 fast-ack, marks `duplicate`, causes zero domain effect (idempotent apply keyed on event ID). |
| Malicious merchant user initiating STK against another merchant's invoice | Unchanged pipeline: tenant scope → 404 on foreign invoice; plus Wallet-side merchant-account binding — the Wallet payment is registered under the owning merchant's Wallet merchant account, so even a confused-deputy call cannot cross tenants at Wallet. |
| Compromised Servana signing key used to fabricate R&E qualification events | Blast radius limited to Servana-scoped events (R&E binds product/environment/scope from the key, not the payload); rotation runbook §77.1; R&E's `409 EVENT_ID_PAYLOAD_MISMATCH` + critical security event on tamper; Servana emits qualification only from the deterministic engine with append-only decision rows, so internal fabrication requires code-path compromise, which reconciliation queries expose. |
| R&E outage during merchant registration | Referral snapshot stored locally in the registration transaction; validation/confirmation retried by outbox with backoff; registration UX unaffected (A‑19). |
| Wallet outage during subscription payment | Invoice remains payable; initiation returns a transparent `provider_unavailable` retry state; no attempt row is stranded in a paying state (§25.4 timeout path); scheduled status-query job reconciles once Wallet recovers. |
| Duplicate/out-of-order Wallet events (e.g., `payment.succeeded` arriving after Servana already applied via status query) | Application keyed on (`wallet_payment_id`, monotonic event ordering via `occurred_at` + event ID first-seen); apply-under-lock checks invoice allocation state and is a no-op when funds are already applied; out-of-order stale states never regress a terminal local state (§57 ordering rules). |
| Same Wallet payment referenced by events for two different invoices (Wallet-side defect) | `subscription_payments.wallet_payment_id` binding to the invoice's registered payment; second application attempt opens a `billing_reconciliation_exceptions` row (`reason='wallet_payment_reused'`, severity critical) instead of double-crediting. |

---

## 10. System Architecture

Servana is a single deployable monolith (modular by bounded context) exposing a versioned JSON API consumed by a Vue SPA, backed by PostgreSQL, Redis, private object storage, and external partners (Wallet by Citrus for payments, Citrus Refer & Earn for referrals, SMS, email). Asynchronous work runs on class-separated queues; scheduled work runs on a singleton scheduler. Servana never communicates with Safaricom/Daraja directly (§9 rule 20).

```text
[Vue SPA] --HTTPS/JSON /api/v1--> [Load Balancer] --> [Web/App replicas (Laravel, Sanctum)]
                                                         |  |  |
              +------------------------------------------+  |  +------------------------------+
              |                                             |                                 |
        [PostgreSQL 16 HA]                          [Redis 7 (sessions,                 [Private S3-compatible
        (tenant + financial                          cache, queues, locks,               object storage,
         + audit data)                               rate limits)]                        versioned, lifecycle)]
              |                                             |
        [Queue workers by class:                     [Scheduler (singleton/leader):
         critical-billing, notifications,             trial/grace/suspension, salary accrual,
         reports-exports, file-scanning, default]     reconciliation retries, day-close reports]
                                                            |
                                         [External partners: Wallet by Citrus (payments), Citrus R&E (referrals),
                                          SMS provider, SMTP/email; ClamAV scanner]
```

### 10.1 Bounded Contexts (`app/Domain/*`)
`Auth`, `Identity` (users/membership), `Tenancy`, `Platform` (governance), `Branches`, `Staff`/`HR`, `Catalogue` (services/eligibility), `Clients`, `Scheduling` (appointments/walk-ins/queues/sessions), `Invoicing`, `Payments` (merchant-client), `Receipts`, `Refunds`, `CashUp`, `PeriodLocks`, `Billing` (plans/entitlements/subscriptions/invoices/promotions), `PlatformFee` (percentage engine), `Integrations\Wallet` (Wallet by Citrus payment integration), `Integrations\ReferEarn` (Citrus R&E source-product integration), `Compensation` (plans/commission/salary/payouts/earnings), `Sms`, `Files`, `Notifications`, `Reporting`, `Audit`, `Support` (money, correlation, redaction). Each context owns its models, actions, policies, events, jobs, and tests. Cross-context calls go through actions/services, never by reaching into another context's Eloquent models.

The two integration contexts (owning phases 20D‑W, 21R‑A, 21R‑B):

```text
app/Domain/Integrations/Wallet/
  Actions/       RegisterInvoicePayment, InitiateWalletStkAttempt, QueryWalletPaymentStatus,
                 ProcessWalletWebhookEvent, ApplyConfirmedWalletPayment, RecordWalletReversal,
                 RecordExternalRefund, OpenBillingReconciliationException, ResolveBillingReconciliationException,
                 ReconcileInvoiceAllocationsAgainstWallet, SyncMerchantWalletAccount
  Clients/       WalletClientInterface, HttpWalletClient, FakeWalletClient (tests/sandbox-sim)
  DTOs/          WalletPayment, WalletAttempt, WalletWebhookEvent (parse/validate; never Eloquent)
  Jobs/          ProcessWalletWebhookJob (queue: wallet-events),
                 QueryStaleWalletAttemptsJob, NightlyWalletAllocationReconciliationJob (queue: critical-billing)
  Http/          WalletWebhookController (single POST endpoint)
  Support/       WalletSignatureVerifier, WalletEventOrdering

app/Domain/Integrations/ReferEarn/
  Actions/       CaptureReferralSnapshot, ValidateReferralCode, ConfirmAttribution,
                 EnqueueProductEvent, DeliverProductEvent, EvaluateQualificationPeriod,
                 CorrectQualificationDecision, AnswerReconciliationQuery
  Clients/       ReferEarnClientInterface, HttpReferEarnClient, FakeReferEarnClient
  Jobs/          DeliverReOutboxJob (queue: re-outbox), EvaluateMonthlyQualificationJob (queue: re-qualification),
                 ReconcileReEventGapsJob
  Http/          ReferEarnReconciliationController (inbound, signed)
  Support/       CitrusEventSigner, InboundSignatureVerifier, QualificationRuleResolver
```

Controllers parse/verify and hand to Actions; Actions own transactions and locks; no settlement or qualification logic in controllers (§11 pattern).

### 10.2 Request Lifecycle
Correlation-ID middleware → rate limiter (per route) → Sanctum auth (except public/webhook/health) → MFA/step-up gate (privileged) → `ResolveTenantContext` (pinned before `SubstituteBindings`) → `EnsureMerchantActive`/billing gates → `EnsureBranchScope` (branch routes) → permission middleware/policy → Form Request validation → idempotency middleware (financial) → controller → domain action (transactional, locked) → resource response. `terminate()` resets tenant context per request.

Integration lifecycles (partner routes bypass Sanctum and tenant middleware — §24.1 partner classes):
- `POST /api/v1/integrations/wallet/webhooks`: verify (§9 rule 21) → insert encrypted `wallet_webhook_inbox` row (unique `wallet_event_id`) → **200 fast-ack** → `ProcessWalletWebhookJob` async. Target ack p95 < 250 ms; no domain work pre-ack.
- `POST /api/v1/integrations/refer-earn/reconciliation/query`: verify → answer synchronously from read models (bounded query classes only, §58B.4) → log to `re_inbound_requests`.
- All outbound Wallet/R&E HTTP: 10 s connect / 20 s total timeout; retries only where idempotent-by-contract; circuit-breaker per partner host (open after 5 consecutive transport failures; half-open probe each 60 s) with breaker state exported as a metric.

### 10.3 Error Envelope
All API errors use `{ error: { code, message, fields, meta } }`. 5xx responses carry a generic message + correlation ID only; never stack traces, SQL, provider secrets, or raw callbacks. Standard codes: 401 `unauthenticated`; 403 `permission_denied`/`no_branch_scope`/`merchant_suspended`/`pending_setup_only`; 404 foreign-tenant; 409 `idempotency_key_reused_with_different_request`/`request_in_progress`; 422 validation; 423 `financial_period_locked`; 429 rate limited; 503 `provider_unavailable` (Wallet unreachable at initiation/registration).

### 10.4 System-of-Systems View (Citrus Platform Integrations)

```text
                                   ┌──────────────────────────────┐
                                   │        Safaricom / banks     │
                                   └──────────────┬───────────────┘
                                                  │ provider APIs + callbacks
                                                  ▼
┌───────────────┐   signed product events   ┌──────────────────────────────┐
│ Citrus        │◄──────────────────────────│      Wallet by Citrus        │
│ Refer & Earn  │   (from Wallet: none at   │  credentials, STK, C2B,      │
│ (central      │    launch)                │  provider recon, ledger,     │
│ referral      │                           │  settlement, signed product  │
│ system of     │                           │  webhooks                    │
│ record)       │                           └──────────────┬───────────────┘
└──────┬────────┘                                          │
       ▲   ▲                                               │  ① Servana → Wallet:
       │   │  ② Servana → R&E: signed product events       │     authenticated product API
       │   │     (X-Citrus-* HMAC contract)                │     (register payment, STK
       │   │  ③ R&E → Servana: signed reconciliation       │     attempt, status query)
       │   │     queries (replay-protected)                │  ④ Wallet → Servana: signed
       │   │                                               │     webhooks (contract-signed,
       │   │                                               │     replay window, per-app creds)
┌──────┴───┴───────────────────────────────────────────────▼───────────────┐
│                            Servana by Citrus                             │
│  Billing truth: plans, prices, entitlements, subscriptions, invoices,    │
│  promotions, grace/suspension, billing credits, invoice allocation,      │
│  billing-status projection, platform-fee engine, compensation, payouts   │
│  (recorded, not moved), referral capture + activity qualification        │
└───────────────────────────────────────────────────────────────────────────┘
```

Four integration channels exist, all machine-to-machine, all authenticated, all idempotent, all replay-protected:

1. **Servana → Wallet product API** (outbound HTTPS, per-environment machine credentials): register payments, initiate STK attempts, query status, list attempts (§56.2).
2. **Wallet → Servana webhooks** (inbound HTTPS, signed per the Wallet contract — HMAC or asymmetric, algorithm-aware verification per ADR‑015 — per-application credentials, timestamp + replay window): payment/attempt lifecycle events (§57).
3. **Servana → R&E product event API** (outbound HTTPS, `X-Citrus-*` HMAC contract with nonce + content hash): merchant lifecycle, subscription financial facts, activity-qualification decisions — delivered through a transactional outbox (§58A).
4. **R&E → Servana reconciliation API** (inbound HTTPS, HMAC-signed, replay-protected): read-only fact re-verification (§58B.4).

Ownership across the three systems is governed by the §2.2 matrix.

---

## 11. Backend Architecture

- **Framework:** Laravel 12.60+ (ADR-001), PHP 8.3 pinned across local/CI/worker/scheduler/production images.
- **Layering:** Controller (thin: authorize, validate, delegate) → Action/Service (transactional domain logic) → Model (Eloquent, tenant-scoped) → Event/Listener/Job (side effects). Controllers never assign status strings or contain settlement logic.
- **Transition actions:** every stateful aggregate has named Action classes (e.g., `ValidatePayment`, `SubmitCashUp`, `ApprovePayoutRun`, `RecoverBillingSuspendedMerchant`) that lock the row `FOR UPDATE`, assert current state in the `WHERE`/locked model, write, emit post-commit events, and record transition history for high-value aggregates.
- **Money:** `App\Support\Money` integer-minor-unit value object with currency-checked arithmetic; round-half-up with largest-remainder residual allocation (ADR-005).
- **Enums:** PHP backed enums for every status/mode, mirrored by DB `CHECK` constraints and by generated TypeScript unions. Unknown status strings are forbidden.
- **Idempotency:** middleware (Section 13/24) on every `financial_mutation` route; coverage test prevents a financial route from existing without it.
- **Tenancy:** `BelongsToMerchant`/`BelongsToBranch` traits + global scopes; `withoutTenancy()` banned outside `Tenancy`/`Platform` by PHPStan rule; `TenantAwareJob` rehydrates and re-validates context.
- **Audit:** `AuditRecorder` contract + `DatabaseAuditRecorder` (append-only, hash-chained); every transition action emits a typed audit event with severity.
- **Validation:** Form Requests with allowlists; JSON columns validated by Form Requests and value objects (never replacing normalized relational data for permissions, pricing, targets, or line items).
- **Static analysis:** Larastan level 8 with custom rules (`NoWithoutTenancyOutsidePlatformRule`, `NoRawSqlConcatRule`, `NoDirectStatusAssignmentRule`) + a source-scan test for escape hatches, `::find()` in controllers, raw-SQL concatenation, and direct status assignment outside transition actions. Integration guards: (1) only `Integrations\Wallet\Clients` may reference the Wallet base URL config; (2) only `Integrations\ReferEarn\Support\CitrusEventSigner` may read the R&E signing secret; (3) the banned-symbol list of §9 rule 20; (4) `re_outbound_events` and `wallet_webhook_inbox` models are guarded append-only (no `update()` on payload columns; DB trigger enforces).

---

## 12. Frontend Architecture

- **Stack:** Vue 3 + TypeScript + Vite SPA; Pinia stores; Vue Router with guards that are **UX-only** (backend always enforces). Tailwind with brand tokens; breakpoints `md: 768px`, `lg: 1025px`; class-based dark mode with pre-paint flash-prevention.
- **API client:** single axios instance (`baseURL=/api/v1`, `withCredentials`, CSRF priming) mapping the error envelope to a typed `ApiError { code, message, fields, meta }`.
- **Forms:** `useForm<T>` composable with dirty/touched/errors/submitting, server-422 merge, and duplicate-submit prevention.
- **Permissions:** `permissionStore` sourced from `/api/v1/me`; `useCan` composable and `PermissionGate` component drive visibility only.
- **State boundaries:** every data surface renders explicit loading/empty/error/success/permission-denied/read-only states via shared components.
- **Types:** `ApiError`, `Paginated<T>`, models, and enums generated from or parity-checked against the backend contract (no manually divergent enum lists).
- **Money/dates:** integer-minor-unit formatting and `Africa/Nairobi` date helpers; never compute money in floating point client-side.
- **Generated contract:** TypeScript billing-mode, billing-interval (`weekly`|`bi_weekly`|`monthly`|`quarterly`|`annual`), status, and permission types are produced from the OpenAPI/contract and verified by a parity test (Corrections 4.4, 16.5).

### 12.1 Integration-Driven Frontend Changes (Phases 20D‑W, 21R‑A, 21R‑B)

No new top-level navigation. Changes are contained:

1. **Subscription payment flow (Merchant Admin / Branch Manager / Finance / Front Office):** identical screen inventory and role exposure as §58, with state labels driven by the revised attempt machine (§25.4): `initiating → prompt_sent (polling) → confirmed → applied` plus `customer_cancelled | timeout | failed | provider_unavailable (retry)`; `submitting_to_wallet`/`submitted_to_wallet`/`submission_unknown` all render as the same "initiating/awaiting prompt" polling label — the UI never invites a fresh attempt while `submission_unknown` is unresolved. Polling reads `GET /api/v1/billing/payment-attempts/{attempt}` exactly as before; the payload shape is preserved except `provider_channel` values now come from Wallet `provider_method` and a `wallet_status` field is added for the Finance-detail view only. Front Office continues to see only simple amount-due + progress (no wallet_status, no masked phone).
2. **PayBill/Till instructions panel:** renders `paybill_or_till` + `account_reference` (now `SRV-PAY-…`) + amount from `GET …/payment-instructions`. New UI state: `instructions_pending` (registration not yet confirmed) with an explanatory message and auto-refresh; never shows a stale internal invoice number as a payable reference.
3. **Platform → Billing Reconciliation screen (Super Admin):** lists `billing_reconciliation_exceptions` with reason/severity filters; resolve dialog offers `link_to_invoice` (choose invoice; shows a Wallet payment summary fetched read-only) or `dismiss`, requires reason + step-up, and shows a before/after audit preview. No manual "record payment" control exists anywhere (guard test).
4. **Platform → Integrations Health screen (Super Admin, new, small):** Wallet webhook lag, inbox failure count, circuit-breaker state; R&E outbox depth, oldest undelivered event age, last delivery error, dead-letter count; last qualification run per period. Read-only; links to runbooks.
5. **Merchant registration page:** accepts `?ref=SERVANA-XXXXX` (and central-redirect equivalents), shows a dismissible "Referral code applied: SERVANA‑XXXXX" notice on the form, includes the code in the self-register POST. No referrer identity is ever displayed (Servana doesn't have it). Invalid-format codes show inline validation but never block submission (the code is simply omitted / snapshot marked `invalid_format`).
6. All new/changed screens pass the per-feature responsive/dark/axe gate (§§28–30) as usual.

---

## 13. Database Architecture and Complete Data Dictionary

### 13.1 Canonical Schema Rules (apply to every table)
- Internal PK: `bigint generated by default as identity` (unless the project standardized otherwise).
- Public identifier: `ulid char(26)` unique, immutable, used in all external references.
- Merchant-owned: non-null `merchant_id` FK + an index beginning with `merchant_id`.
- Branch-owned: non-null `merchant_id` and `branch_id`; branch-belongs-to-merchant enforced in application validation and, where practical, a composite FK.
- Actor columns: `created_by`/`updated_by`/`approved_by`/`rejected_by` nullable FKs per lifecycle.
- Money: `bigint` minor units + `char(3)` currency with uppercase check.
- Time: `timestamptz` for events; `date` for business dates (pay-period boundaries, effective dates).
- JSON: `jsonb`, validated in Form Requests/value objects; never replaces normalized data for permissions, pricing, targets, or financial line items.
- Statuses: application enums backed by DB `CHECK`; unknown strings forbidden.
- Financial/audit rows: no destructive cascade — use `RESTRICT` or nullable actor links; append-only ledgers and audit events are never soft-deleted.
- Every FK used for filtering/joining is indexed.

### 13.2 Data-Dictionary Specification Files (mandatory, version-controlled)
Create `/docs/architecture/data-dictionary/` with: `README.md`, `core-identity-and-tenancy.md`, `branches-and-staff.md`, `services-clients-scheduling.md`, `invoicing-and-payments.md`, `billing-and-wallet.md` (replaces the former `billing-and-mpesa.md`), `refer-earn-integration.md`, `compensation.md`, `audit-files-notifications.md`. The **version-controlled data dictionary is the single canonical DDL authority**; the inline schema blocks in §13.5–§13.16 are a concise navigational *inventory and summary only* and are explicitly **not** the full DDL — no statement in this plan should be read as claiming an inline summary is a complete table specification. **Each table** gets a data-dictionary entry that defines, completely: table name; domain owner; purpose + scope refs; primary key; public identifier; every column with PostgreSQL type, length/precision, nullability, default, and semantic meaning; enum values; foreign keys with `ON DELETE` and `ON UPDATE`; unique, partial-unique, check, and exclusion constraints; indexes and composite indexes with query patterns; tenant ownership, branch ownership, and own-scope derivation; entitlement/billing-status/period-lock rules; effective-date, settlement, lock, and expiry fields; soft-delete policy; immutability; encryption, hashing, masking, and log-redaction; retention and archival; migration order, backfill, expand-and-contract sequence, and forward-repair strategy; locking and concurrency; audit events; Eloquent relationships; factories; seeders; and unit/feature/isolation/migration tests. **The IDE agent must stop and create (and have reviewed) the complete data-dictionary entry before writing the migration for any business table; no migration may be authored while its entry is missing or incomplete.** The inline blocks below give each table a binding canonical data-dictionary path and enough structure to navigate; they satisfy "no table is referenced without a specification path," while the dictionary entry carries the implementable DDL.

### 13.3 Coverage Guards (CI)
- `DataDictionaryCoverageTest` fails when a migration creates a non-framework business table absent from the data dictionary.
- `TenantColumnCoverageTest` fails when a tenant-owned table lacks `merchant_id` or a branch-owned table lacks `merchant_id`+`branch_id`.
- Migration tests run on **PostgreSQL** (not SQLite) because partial indexes, exclusion constraints, JSONB, advisory locks, and triggers are PostgreSQL-specific.
- `php artisan schema:dump` output is stored as CI evidence, never as the source of truth.

### 13.4 Launch Table Inventory (by domain)
The complete launch set. Tables marked **(as-built)** are reported implemented (verify in Phase V); **(R)** are remediation-owned; **(feature)** are owned by the feature phase noted.

```text
Core/identity/tenancy: users(as-built), magic_login_tokens(as-built), merchants(as-built),
  merchant_profiles(as-built), merchant_users(as-built), merchant_status_histories(as-built),
  permissions(as-built), roles(as-built), role_permission_assignments(as-built),
  merchant_user_permission_overrides(as-built), mfa_credentials(R3), mfa_recovery_codes(R3),
  idempotency_keys(R4), audit_logs(as-built), audit_flagged_events(19), audit_exports(19)
Branches/staff: merchant_branches(as-built), branch_user_assignments(as-built),
  branch_operating_hours(as-built), branch_calendar_exceptions(as-built),
  branch_day_records(as-built), staff_invitations(as-built), staff_profiles(as-built),
  staff_history(as-built), branch_cash_ups(seam→18B)
Catalogue/clients/scheduling: service_categories(15A), services(15A), service_personnel_eligibility(15A),
  personnel_availability(16A), clients(15A), client_consents(15A/21S), appointments(16A),
  walk_ins(16B), queue_entries(16B), service_sessions(16C)
Invoicing/payments: invoices(17), invoice_items(17), invoice_number_sequences(17), receipt_number_sequences(18B),
  payment_recording_groups(18A), payment_records(18A), payment_allocations(18A), payment_reference_checks(18A),
  payment_validation_events(18B), receipts(18B), refunds(18B), finance_disputes(18B),
  cash_up_lines(18B), financial_period_locks(18B), finance_exports(18B/23)
Billing/subscription/promotions: platform_billing_settings(20A), subscription_plans(20A),
  subscription_plan_prices(20A), plan_entitlements(20A), merchant_subscriptions(20B),
  scheduled_plan_changes(20B), subscription_invoices(20B), subscription_invoice_items(20B), billing_escalation_events(20B),
  promotional_discounts(20C), promotional_discount_targets(20C), free_period_offers(20C),
  free_period_offer_targets(20C)
Percentage platform-fee engine: platform_fee_configurations(20E), platform_fee_ledger_entries(20E),
  platform_fee_adjustments(20E), platform_fee_disputes(20E), preferred_personnel_fee_rules(20A)
Wallet billing payments: wallet_merchant_account_links(20D-W), subscription_payment_attempts(20D-W),
  subscription_payments(20D-W), subscription_payment_receipts(20D-W), subscription_payment_reversals(20D-W), wallet_webhook_inbox(20D-W),
  billing_reconciliation_exceptions(20D-W), subscription_invoice_payment_locks(20D-W), merchant_billing_credits(20D-W)
Refer & Earn integration: referral_snapshots(21R-A), re_outbound_events(21R-A), re_event_deliveries(21R-A),
  re_activity_rule_versions(21R-B), re_qualification_periods(21R-B), re_qualification_decisions(21R-B),
  re_inbound_requests(21R-B)
Compensation: personnel_compensation_plans(20F), compensation_plan_history(20F), commission_rules(20F),
  commission_ledger(20G), salary_ledger(20G), compensation_adjustments(20G/20H),
  personnel_payout_runs(20H), personnel_payout_items(20H), earnings_queries(20H)
Files/SMS/notifications: uploaded_files(10F), file_scan_events(10F), personnel_sms_campaigns(21S),
  personnel_sms_recipients(21S), sms_delivery_attempts(21S), sms_billing_entries(21S),
  notifications(21N), scheduled_report_runs(21N)
```

### 13.5 Schema Summary (canonical DDL: data dictionary) — Core, Identity, Tenancy

```text
users (as-built; verify)
- id bigint identity PK; ulid char(26) unique not null
- name varchar; email citext/varchar unique not null; password varchar nullable (passwordless, Plan A-03)
- status varchar(20) not null CHECK in ('active','suspended','deactivated')
- is_platform_staff boolean not null default false
- last_login_at timestamptz nullable; created_at/updated_at timestamptz
- Notes: not tenant-scoped; membership lives in merchant_users. Suspension/deactivation revokes sessions/links.

magic_login_tokens (as-built; verify)
- id; ulid; user_id bigint FK users RESTRICT
- token_hash char(64) not null (SHA-256 of 64-byte random); expires_at timestamptz not null
- consumed_at timestamptz nullable; created_at
- Unique active token per user via partial index; single-use via atomic conditional UPDATE. Raw token never stored/logged.

merchants (as-built; verify)
- id; ulid; name varchar; service_fee_tier varchar (legacy tier seam — superseded by plans)
- status varchar(20) not null CHECK in ('pending_setup','active','suspended','deactivated')
    -- operational/governance lifecycle (the operational-status machine, §21/§25, operates on merchants.status).
    -- As-built column may be named operational_status; Phase V/R5 reconciles the name via expand-and-contract if it differs.
- billing_status varchar(20) not null default 'trialing' CHECK in ('trialing','read_only_grace','active','overdue','suspended_billing')
    -- current billing-ACCESS state used by request authorization (§22). Projected/synchronized transactionally from the
    -- active merchant_subscription by the billing-status projection service; it is the authority for the billing-status gate,
    -- NOT merchant_subscriptions.status.
- status_reason text nullable; billing_status_reason text nullable; suspended_at/deactivated_at timestamptz nullable
- created_at/updated_at; soft-delete forbidden (use deactivated)
- Index (status), Index (billing_status). Operational status (merchants.status) and billing status (merchants.billing_status)
  are separate machines; a billing payment changes only merchants.billing_status and never clears a non-billing merchants.status suspension.

merchant_profiles (as-built; verify) — merchant_id FK; logo_path nullable (file seam→10F); contact/address fields; settings jsonb
merchant_status_histories (as-built; verify) — merchant_id; from_status; to_status; reason; actor user_id; created_at (append-only)

merchant_users (as-built; verify)
- id; ulid; merchant_id FK merchants RESTRICT; user_id FK users RESTRICT
- role varchar CHECK in ('merchant_admin','branch_manager','hr','front_office','finance','personnel','audit')
- status varchar CHECK in ('invited','active','suspended','deactivated'); created_at/updated_at
- Unique (merchant_id, user_id); index (merchant_id, role, status). Active membership+role re-checked every request (R6).

permissions / roles / role_permission_assignments / merchant_user_permission_overrides (as-built; verify, extend to canonical matrix)
- permissions: id; key varchar unique; description; scope; flags per Correction 16.3
- roles: id; key; label
- role_permission_assignments: role_id; permission_id (unique pair)
- merchant_user_permission_overrides: merchant_id; merchant_user_id; permission_id; effect CHECK in ('grant','revoke'); created_by; reason
- Resolver: role defaults ± overrides; deny beats grant; suspended/deactivated → none; audit role can never gain a mutating key; non-overridable rules (Correction 16.3) enforced in code + tests.

mfa_credentials (R3)
- id; ulid; user_id FK users RESTRICT; type varchar CHECK in ('totp')
- secret_encrypted text not null; confirmed_at timestamptz nullable; last_used_at timestamptz nullable
- created_at/updated_at; Unique (user_id, type). Secret encrypted at rest; never logged.

mfa_recovery_codes (R3)
- id; user_id FK users RESTRICT; code_hash char(64) not null; used_at timestamptz nullable; created_at
- One-time; stored hashed; Index (user_id, used_at).

idempotency_keys (R4) — corrected schema (Correction 3.2)
- id; ulid; idempotency_scope varchar(191) not null; key_hash char(64) not null
- actor_user_id FK users SET NULL nullable; merchant_id FK merchants RESTRICT nullable; branch_id FK merchant_branches RESTRICT nullable
- route_name varchar(191) not null; http_method varchar(10) not null; request_content_type varchar(100) nullable
- request_hash char(64) not null; state varchar(20) not null CHECK in ('processing','completed','failed')
- response_status smallint nullable; response_headers jsonb nullable; response_body_encrypted text nullable
- locked_at timestamptz not null; lock_expires_at timestamptz not null; completed_at/failed_at timestamptz nullable
- last_error_code varchar(100) nullable; expires_at timestamptz not null; created_at/updated_at
- UNIQUE (idempotency_scope, key_hash); indexes on (state, lock_expires_at) and expires_at for cleanup.
- scope examples: merchant:{ulid}:user:{ulid} | platform:user:{ulid} | webhook:wallet:{environment}. Store SHA-256(raw key) only.

audit_logs (as-built; verify; complete R2/19)
- id; ulid; merchant_id nullable; branch_id nullable; actor_user_id nullable
- event varchar not null; severity varchar CHECK in ('info','warning','high','critical')
- subject_type/subject_id; context jsonb (masked); correlation_id; previous_hash char(64); row_hash char(64); created_at
- Append-only; DB trigger blocks UPDATE/DELETE; hash chain (row_hash = H(previous_hash || canonical-row)). Verifier command (R2). Branch-scoped, field-masked reads.

audit_flagged_events (19)
- id; ulid; merchant_id; branch_id; audit_log_id FK audit_logs RESTRICT; status varchar CHECK in ('open','under_review','resolved','dismissed','reopened')
- review_notes text; assigned_to nullable; resolved_by nullable; created_at/updated_at
- Only review metadata is mutable; source audit_logs row stays immutable.

audit_exports (19) — async, reason-gated, permission-masked, signed/expiring, download-counted Audit export
  (product-owner decision 2026-07-04 resolving REM-AUDEXP-001; the Plan's export-controls invariant requires
  Audit exports to be async/reason-gated/masked/signed/expiring/download-counted/audited, and uploaded_files
  cannot persist that request lifecycle while finance_exports is Finance-owned with an export_type CHECK that
  excludes audit — see docs/proof/phase-19.md. Phase 23 remains final release-wide export hardening, not the
  initial Audit-export build.)
- id; ulid; merchant_id; branch_id NOT NULL (branch-owned; requested branch must be an assigned Audit branch);
  requested_by_user_id FK users RESTRICT
- reason varchar not null (non-empty CHECK); scope_json jsonb (validated filter snapshot only)
- status varchar CHECK in ('queued','processing','ready','failed','expired','revoked') (state-machine only)
- file_id FK uploaded_files RESTRICT nullable; row_count int nullable (null before ready, >=0 after);
  download_count int default 0 (>=0); first_downloaded_at/last_downloaded_at nullable (coherent with count)
- requested_at; processing_started_at; generated_at; failed_at; expires_at; revoked_at; expired_at
- failure_code varchar nullable; failure_message_redacted varchar nullable; created_at/updated_at
- Branch-scoped (audit.export, SU Y). Merchant-level (branch_id null) audit rows are NEVER exported.
  ready requires file_id+generated_at+expires_at+row_count; failed requires failed_at+failure_code;
  revoked requires revoked_at; expired requires expired_at. Composite (branch_id,merchant_id) FK; UNIQUE(id,merchant_id);
  no soft delete; no destructive delete. Generated via GenerateAuditExport (reports-exports, TenantAwareJob) writing a
  private CSV through the Phase 10F file domain (FilePurpose::AuditExport). Download accounting is on the authorized
  file STREAM (not link issuance). No raw contents/paths/signatures/contacts/references/tokens/SQLSTATE/stack traces stored.
```

### 13.6 Schema Summary (canonical DDL: data dictionary) — Branches and Staff

```text
merchant_branches (as-built; verify) — id; ulid; merchant_id FK RESTRICT; name; timezone default 'Africa/Nairobi';
  status varchar CHECK in ('active','suspended','archived'); status_reason; suspended_at/archived_at; updated_by; created_at/updated_at; index (merchant_id, status)
branch_user_assignments (as-built) — id; ulid; merchant_id; branch_id FK RESTRICT; merchant_user_id FK RESTRICT;
  active boolean; partial-unique (one active assignment per member+branch); index (branch_id, active)
branch_operating_hours (as-built) — id; merchant_id; branch_id; weekday smallint CHECK 0..6; opens_at time; closes_at time; closed boolean; unique (branch_id, weekday)
branch_calendar_exceptions (as-built) — id; merchant_id; branch_id; date; type CHECK in ('closed','special_hours'); opens_at/closes_at nullable; reason
branch_day_records (as-built) — id; ulid; merchant_id; branch_id; business_date date;
  status varchar CHECK in ('not_opened','open','paused','closed','reopened'); opened_by/closed_by/reopened_by; opened_at/closed_at; reopen_reason; unique (branch_id, business_date)
staff_invitations (as-built) — id; ulid; merchant_id; branch_id; email; role; token_hash char(64) (72h);
  status varchar CHECK in ('pending','accepted','revoked','expired'); invited_by; accepted_at; resend_count; partial-unique (one pending per merchant+email+role+branch)
staff_profiles (as-built) — id; ulid; merchant_id; branch_id; user_id FK RESTRICT; display_name; phone_encrypted; phone_last_four;
  profile_photo_path nullable(→10F); status varchar CHECK in ('invited','active','suspended','deactivated'); created_at/updated_at; partial-unique active phone platform-wide
staff_history (as-built) — id; merchant_id; staff_profile_id FK RESTRICT; event varchar; from_status/to_status; actor; reason; created_at (append-only)
```

### 13.7 Schema Summary (canonical DDL: data dictionary) — Catalogue, Clients, Scheduling

```text
service_categories (15A) — id; ulid; merchant_id; branch_id; name; sort_order; archived_at nullable; unique (branch_id, name) where not archived
services (15A) — id; ulid; merchant_id; branch_id; category_id FK RESTRICT; name; description;
  price_minor bigint; currency char(3); duration_minutes int;
  preferred_personnel_fee_minor bigint nullable (LEGACY fixed default; superseded by `preferred_personnel_fee_rules` per Correction 12; retained read-only during expand-and-contract, then contracted);
  status varchar CHECK in ('active','archived'); created_by/updated_by; index (branch_id, status). Owned by Branch Manager; effective preferred-personnel fee resolves from the active rule (§13.10) and is snapshotted onto invoices at finalization.
service_personnel_eligibility (15A) — id; merchant_id; branch_id; service_id FK RESTRICT; staff_profile_id FK RESTRICT; active boolean; unique (service_id, staff_profile_id)
personnel_availability (16A) — id; merchant_id; branch_id; staff_profile_id; weekday/date; start_time; end_time; type CHECK in ('recurring','exception'); available boolean
clients (15A) — id; ulid; merchant_id; branch_id; full_name; phone_encrypted; phone_last_four; email_encrypted nullable;
  notes; created_by; status CHECK in ('active','archived'); index (branch_id); branch-scoped; contact masked at read.
client_consents (15A/21S) — id; merchant_id; branch_id; client_id FK RESTRICT; channel CHECK in ('sms'); state CHECK in ('opted_in','opted_out'); source; changed_at; unique (client_id, channel)
appointments (16A) — id; ulid; merchant_id; branch_id; client_id FK RESTRICT; service_id; staff_profile_id nullable;
  scheduled_start/scheduled_end timestamptz; status varchar CHECK in ('scheduled','confirmed','checked_in','queued','in_service','rescheduled','cancelled','no_show');
  created_by; cancellation_reason; index (branch_id, scheduled_start, status). Eligibility/availability revalidated on assign/transfer.
walk_ins (16B) — id; ulid; merchant_id; branch_id; client_id nullable; service_id; created_by; created_at; converts to queue_entry
queue_entries (16B) — id; ulid; merchant_id; branch_id; client_id; service_id; staff_profile_id nullable; appointment_id nullable;
  status varchar CHECK in ('waiting','assigned','called','in_service','completed','transferred','cancelled','no_show');
  position int; queued_at; called_at; transferred_to_staff_profile_id nullable; index (branch_id, status, position). Personnel cannot access others' entries.
service_sessions (16C) — id; ulid; merchant_id; branch_id; queue_entry_id nullable; client_id; staff_profile_id FK RESTRICT;
  status varchar CHECK in ('pending','in_progress','completed','cancelled'); started_at/completed_at;
  index (branch_id, status); partial-unique active session per staff (duplicate-active protection). Eligibility+branch-assignment required per service item.
```

### 13.8 Schema Summary (canonical DDL: data dictionary) — Invoicing and Merchant-Client Payments

```text
invoices (17) — id; ulid; merchant_id; branch_id; client_id FK RESTRICT; invoice_number varchar unique-per-merchant (allocated at finalization);
  status varchar CHECK in ('draft','issued','partially_paid','paid','void_pending','voided','adjusted','refund_pending','adjustment_required');
  subtotal_minor; discount_minor; tax_minor; total_minor; validated_paid_minor default 0; currency char(3);
  preferred_personnel_fee_snapshot_minor nullable; percentage_fee_config_snapshot jsonb nullable; finalized_at; created_by;
  index (branch_id, status), (merchant_id, invoice_number). Finalization snapshots prices/fees; void of paid invoice creates adjustments, never deletes ledger rows.
invoice_items (17) — id; ulid; merchant_id; branch_id; invoice_id FK RESTRICT; service_id FK RESTRICT; staff_profile_id nullable;
  description; quantity int; unit_price_minor; line_total_minor; preferred_personnel_fee_minor nullable; eligible_for_commission boolean; currency
payment_records (18A) — id; ulid; merchant_id; branch_id; invoice_id FK RESTRICT; payment_recording_group_id FK payment_recording_groups RESTRICT (every recording belongs to exactly one group, §13.15); recorded_by FK users RESTRICT; payer_client_id nullable;
  method varchar CHECK in ('cash','mpesa_offline','bank_transfer','card_terminal','voucher','split_payment','other');
  amount_minor bigint; currency char(3); reference_normalized varchar nullable; reference_display_encrypted text nullable;
  paid_at timestamptz; status varchar CHECK in ('pending_validation','validated','rejected','correction_required','reversed','adjusted');
  maker_user_id FK users RESTRICT; validated_amount_minor bigint nullable; created_at/updated_at;
  index (invoice_id, status), (merchant_id, method, reference_normalized), (payment_recording_group_id). Method-specific reference rules (Section 41). A split_payment workflow creates one `payment_recording_groups` row with multiple component payment_records; a single-method payment is a group of one.
payment_allocations (18A) — id; merchant_id; branch_id; payment_record_id FK RESTRICT; invoice_id FK RESTRICT; invoice_item_id nullable; amount_minor; sum-constrained ≤ balance
payment_validation_events (18B) — id; ulid; merchant_id; branch_id; payment_record_id FK RESTRICT; invoice_id FK RESTRICT;
  checker_user_id FK users RESTRICT; decision varchar CHECK in ('validated','rejected','correction_required'); validated_amount_minor; reason; created_at (immutable). Commission earned at 'validated'.
receipts (18B) — id; ulid; merchant_id; branch_id; invoice_id FK RESTRICT; payment_validation_event_id FK RESTRICT nullable; receipt_number unique-per-merchant;
  amount_minor; currency; components jsonb (methods/amounts); reissue_of_receipt_id nullable; file_id nullable(→10F); created_at. Issued only after validation; reissue creates a new tracking row.
refunds (18B) — id; ulid; merchant_id; branch_id; invoice_id FK RESTRICT; payment_record_id nullable; amount_minor; currency; method; external_reference_encrypted nullable;
  reason; status varchar CHECK in ('requested','approved','finalized','rejected'); requested_by; approved_by; finalized_by; created_at/updated_at. External refund record; reduces recognized paid balance only via adjustment/reversal entries; commission reversal proportional.
finance_disputes (18B) — id; ulid; merchant_id; branch_id; invoice_id nullable; payment_record_id nullable; status CHECK in ('open','under_review','resolved','rejected'); reason; resolution_note; created_by; resolved_by; created_at/updated_at
cash_up_lines (18B) / branch_cash_ups (as-built seam→18B) — branch_cash_ups: id; ulid; merchant_id; branch_id; branch_day_record_id FK RESTRICT; business_date;
  status varchar CHECK in ('draft','submitted','approved','rejected','correction_requested','locked'); expected_minor; counted_minor; variance_minor; submitted_by; approved_by; submitted_at/approved_at; notes.
  cash_up_lines: id; cash_up_id FK RESTRICT; method; expected_minor; counted_minor; variance_minor. Branch Manager submits; Finance approves; maker≠checker.
financial_period_locks (18B) — id; ulid; merchant_id; branch_id nullable; period_start date; period_end date;
  status varchar CHECK in ('open','locked','reopened'); locked_by; reopened_by; reopen_reason; locked_at; index (merchant_id, branch_id, period_end). Finance-owned; mutations in a locked period return 423.
finance_exports (18B/23) — Correction 2.4 exact DDL:
  id; ulid; merchant_id; branch_id nullable; requested_by FK users RESTRICT;
  export_type CHECK in ('invoices','payments','receipts','cash_up','refunds','disputes','compensation','payouts','billing');
  scope_json jsonb; reason text not null; status CHECK in ('queued','processing','ready','failed','expired','revoked');
  file_id FK uploaded_files RESTRICT nullable; row_count int nullable; expires_at; first_downloaded_at/last_downloaded_at; download_count int default 0;
  failure_code; failure_message_redacted; created_at/updated_at. Async, scoped, masked, signed, audited on request/generate/download/expiry/revoke.
```

### 13.9 Schema Summary (canonical DDL: data dictionary) — Billing, Subscriptions, Promotions

```text
platform_billing_settings (20A) — id; ulid; billing_mode varchar CHECK in
  ('fixed_amount','percentage_on_merchant_client_invoice','fixed_amount_plus_percentage_on_merchant_client_invoice');
  default_trial_days int; grace_days int; currency char(3); updated_by; effective_from; settings jsonb; (platform-scoped; single active row via effective dates)
subscription_plans (20A) — id; ulid; key varchar unique; name; description; tier metadata (non-price); status CHECK in ('active','retired'); sort_order
subscription_plan_prices (20A) — id; ulid; plan_id FK RESTRICT; amount_minor bigint; currency char(3); billing_interval CHECK in ('weekly','bi_weekly','monthly','quarterly','annual');
  effective_from date; effective_to date nullable; created_by. SOLE price source (ADR-011); exclusion constraint prevents overlapping effective ranges per (plan, interval). Interval date math per §49 (month-end clamp; leap-year safe).
plan_entitlements (20A) — id; plan_id FK RESTRICT; entitlement_key varchar; limit_int nullable; enabled boolean; unique (plan_id, entitlement_key)
merchant_subscriptions (20B) — id; ulid; merchant_id FK RESTRICT; plan_id FK RESTRICT; price_id FK RESTRICT (captured at issuance);
  status varchar CHECK in ('trialing','active','read_only_grace','overdue','suspended_billing','cancelled','expired');
    -- lifecycle of THIS subscription record. The merchant-level billing-ACCESS authority is merchants.billing_status,
    -- which the billing-status projection service synchronizes transactionally from the active subscription's status
    -- (cancelled/expired records project to the appropriate merchants.billing_status). Request authorization reads
    -- merchants.billing_status only; merchant_subscriptions.status is never the sole access authority.
  billing_interval CHECK in ('weekly','bi_weekly','monthly','quarterly','annual');
  trial_days_snapshot int; trial_started_at; trial_ends_at; current_period_start/current_period_end date; high_value_payout_threshold_minor bigint nullable; created_at/updated_at; index (merchant_id), (status). Trial starts at Merchant Admin creation.
scheduled_plan_changes (20B) — id; ulid; merchant_id; merchant_subscription_id FK RESTRICT; target_plan_id; target_price_id; effective_at date;
  status CHECK in ('scheduled','applied','cancelled'); created_by. No proration; applied at next cycle.
subscription_invoices (20B) — id; ulid; merchant_id FK RESTRICT; plan_id; price_id; invoice_number unique-per-merchant;
  period_start/period_end date; subtotal_minor; discount_minor; total_minor; currency; balance_minor;
  status varchar CHECK in ('draft','issued','pending_payment','partially_paid','paid','overdue','payment_failed','reconciliation_required','void');
  account_reference varchar nullable (the Wallet structured payment reference SRV-PAY-<ULID26>, immutable once set;
    null until Wallet registration succeeds — SUP-05/ADR-014);
  wallet_payment_id varchar nullable unique; wallet_registration_status CHECK in ('unregistered','pending','registered','failed') default 'unregistered';
  wallet_registered_at timestamptz nullable;   -- Wallet columns ship in 20B (nullable, forward-compatible); populated in 20D-W
  issued_at; due_at; index (merchant_id, status). Issued invoices are immutable (registration-status fields are an
  orthogonal technical projection, not part of the financial snapshot, and never block issuance); discounts/free periods
  snapshot at issuance. Cancellation terminology: subscription invoices use `void` only (never `cancelled`; §25.4).
subscription_invoice_items (20B) — id; subscription_invoice_id FK RESTRICT; description; amount_minor; type CHECK in ('plan_fee','platform_fee_rollup','sms_rollup','adjustment')
promotional_discounts (20C) — id; ulid; name; type CHECK in ('percentage','fixed_amount'); value (bp or minor); currency nullable;
  target_scope CHECK in ('all_new_merchants','selected_merchants','selected_plans','billing_mode'); starts_at; ends_at nullable;
  status CHECK in ('draft','scheduled','active','paused','expired','cancelled'); created_by; approved_by; change_reason
promotional_discount_targets (20C) — id; promotional_discount_id FK RESTRICT;
  target_type CHECK in ('merchant','plan','billing_mode'); merchant_id FK merchants RESTRICT nullable;
  subscription_plan_id FK subscription_plans RESTRICT nullable;
  billing_mode varchar nullable CHECK (billing_mode is null OR billing_mode in ('fixed_amount','percentage_on_merchant_client_invoice','fixed_amount_plus_percentage_on_merchant_client_invoice'));
  CHECK (exactly one of merchant_id / subscription_plan_id / billing_mode is non-null AND matches target_type)
  -- explicit normalized rows (not JSON). Global ('all_new_merchants') is expressed on the parent target_scope.
free_period_offers (20C) — Correction 2.5 exact DDL:
  id; ulid; name; free_period_days int CHECK 1..365; target_scope CHECK in ('all_new_merchants','selected_merchants','selected_plans','billing_mode');
  starts_at; ends_at nullable; status CHECK in ('draft','scheduled','active','paused','expired','cancelled'); created_by; approved_by; approved_at; change_reason; created_at/updated_at.
  Trial begins at Merchant Admin creation; applied days snapshotted onto the subscription so later edits never rewrite an existing trial.
free_period_offer_targets (20C) — id; free_period_offer_id FK RESTRICT;
  target_type CHECK in ('merchant','plan','billing_mode'); merchant_id FK merchants RESTRICT nullable;
  subscription_plan_id FK subscription_plans RESTRICT nullable;
  billing_mode varchar nullable CHECK (billing_mode is null OR billing_mode in ('fixed_amount','percentage_on_merchant_client_invoice','fixed_amount_plus_percentage_on_merchant_client_invoice'));
  CHECK (exactly one of merchant_id / subscription_plan_id / billing_mode is non-null AND matches target_type)
  -- explicit normalized rows (not JSON). Global ('all_new_merchants') is expressed on the parent target_scope.
```

### 13.10 Schema Summary (canonical DDL: data dictionary) — Percentage Platform-Fee Engine (launch-capable, activated only when configured)

```text
platform_fee_configurations (20E) — id; ulid; billing_mode; fixed_component_minor nullable; percentage_basis_points nullable;
  tier_behavior CHECK in ('customer_centric','shared','business_centric') nullable; effective_from; created_by; snapshotted onto invoices at finalization
platform_fee_ledger_entries (20E) — id; ulid; merchant_id FK RESTRICT; branch_id nullable; source_invoice_id FK RESTRICT; source_invoice_item_id nullable;
  basis_minor bigint; rate_basis_points int nullable; fixed_component_minor nullable; amount_minor bigint; currency;
  entry_type CHECK in ('earned','reversal','adjustment'); status CHECK in ('pending','aggregated','invoiced','reversed','adjusted'); subscription_invoice_item_id nullable; created_at (append-only)
platform_fee_adjustments (20E) — id; ulid; merchant_id; platform_fee_ledger_entry_id FK RESTRICT; amount_minor; reason; approved_by; created_at
platform_fee_disputes (20E) [Correction 3 entity] — id; ulid; merchant_id FK RESTRICT; platform_fee_ledger_entry_id FK RESTRICT nullable; subscription_invoice_id FK RESTRICT nullable;
  reason text; status CHECK in ('open','under_review','resolved','rejected'); resolution_note; created_by; resolved_by; created_at/updated_at.
  Platform-side dispute case over a percentage platform-fee charge; resolution that changes money creates a platform_fee_adjustment (never edits a ledger row). Permission platform.billing_reconciliation.* family / platform billing perms; step-up on resolve; audited.
preferred_personnel_fee_rules (20A) [Correction 12 + Correction 3 entity] — id; ulid;
  calculation_type CHECK in ('fixed_amount','percentage'); fixed_amount_minor bigint nullable; percentage_basis_points int nullable CHECK (percentage_basis_points between 0 and 10000);
  currency char(3) nullable; calculation_basis CHECK in ('service_item_net_amount','service_item_gross_amount');
  scope CHECK in ('platform_default','service'); service_id FK services RESTRICT nullable;
  effective_from date; effective_to date nullable; status CHECK in ('draft','scheduled','active','superseded','expired','cancelled');
  created_by; approved_by nullable; approved_at nullable; change_reason text not null; created_at/updated_at.
  Constraints: fixed_amount requires fixed_amount_minor + currency and null bp; percentage requires percentage_basis_points and null fixed/currency; exactly one calculation value; exclusion constraint prevents overlapping active/scheduled effective ranges per scope (and per service_id when scope='service'); active monetary terms immutable (supersede with a new version). Super-Admin governed (platform.preferred_personnel_fee.manage, MFA + step-up). Invoices snapshot the resolved effective fee at finalization; existing invoices are NEVER recalculated when a rule changes. Percentage uses round-half-up to integer minor units (ADR-005). Migration: expand-and-contract from the legacy fixed `services.preferred_personnel_fee_minor` (seed a `fixed_amount`/`service` rule per service that has a value, then resolve from rules; retain the legacy column read-only until contract).
- Only the percentage-fee ledger tables are created when a percentage component is active (fixed-only mode creates NO percentage-fee entries — tested). Aggregated into subscription_invoice lines. `preferred_personnel_fee_rules` is launch-active and independent of the platform billing mode.
```

### 13.11 Schema Summary (canonical DDL: data dictionary) — Billing Payments via Wallet by Citrus (Phase 20D‑W; Corrections 14, 15 as amended by ADR‑012)

Data-dictionary file: `docs/architecture/data-dictionary/billing-and-wallet.md`. The v3 tables `mpesa_callback_inbox` and `mpesa_reconciliation_events` were removed from the plan before ever being built (SUP‑02) — no migration is needed.

```text
wallet_merchant_account_links (20D-W) — id; ulid; merchant_id FK merchants RESTRICT unique;
  wallet_merchant_account_id varchar not null unique; environment CHECK in ('sandbox','staging','production');
  sync_status CHECK in ('pending','active','failed'); last_synced_at; failure_code nullable; created_at/updated_at.
  Purpose: maps a Servana merchant to its Wallet merchant-account identity (Wallet Foundation
  "product merchant-account registration or synchronization"). Created on demand by SyncMerchantWalletAccount
  (idempotent; Idempotency-Key srv:ma:{merchant_ulid}) before first payment registration.
  Security: wallet_merchant_account_id is a public-safe Wallet identifier; no provider data stored.
  Failure if omitted: payments cannot be registered under the correct owning account; cross-tenant
  routing at Wallet becomes impossible to guarantee.

subscription_payment_attempts (20D-W) — id; ulid; merchant_id FK RESTRICT;
  subscription_invoice_id FK RESTRICT;
  initiated_by_user_id FK users RESTRICT; initiated_by_role_snapshot varchar (role at initiation; do NOT rely
  on current role for historical reconstruction);
  initiated_from_branch_id FK merchant_branches RESTRICT nullable; initiated_ip_hashed char(64) nullable;
  initiated_user_agent_redacted varchar nullable;                    -- initiator snapshots as in v3
  channel CHECK in ('stk_push');                                     -- attempts are USER/PRODUCT-INITIATED only
                                                                     -- (Correction 14.9): a direct PayBill/Till (C2B)
                                                                     -- payment confirmed by Wallet has NO attempt row and
                                                                     -- fabricates no initiator snapshot or Servana
                                                                     -- idempotency key; it is recorded directly in
                                                                     -- subscription_payments, and is linked to an attempt
                                                                     -- ONLY when Wallet correlates the confirmation to an
                                                                     -- existing product-created attempt
  phone_msisdn_encrypted nullable; amount_minor bigint; currency char(3);
  servana_idempotency_key char(64);                                  -- key sent to Wallet as Idempotency-Key
  wallet_payment_id varchar not null; wallet_attempt_id varchar nullable unique;
  provider_method varchar nullable;                                  -- projection from Wallet (e.g. 'mpesa_stk','mpesa_paybill')
  provider_reference_masked varchar nullable;                        -- masked receipt from Wallet events; never raw
  status varchar CHECK in ('initiated','submitting_to_wallet','submitted_to_wallet','submission_unknown',
    'prompt_sent','confirmed','applied_to_invoice',
    'customer_cancelled','timeout','failed','provider_unavailable','duplicate','reconciliation_required',
    'reversed','refunded_externally');
  -- submission_unknown (Correction 14.6): entered when the Wallet submission call times out or fails
  -- ambiguously — an ambiguous transport failure is NOT proof the request was not accepted. The attempt
  -- RETAINS its original servana_idempotency_key; no new attempt may be created for the same invoice under a
  -- new key while one is in submission_unknown (bounded cooldown/lock via subscription_invoice_payment_locks);
  -- retries/queries reuse the original identity; resolution comes only from authoritative Wallet status
  -- (GET /payments/{p} or a verified webhook event).
  wallet_status_snapshot varchar nullable;                           -- last raw Wallet state string, for Finance detail
  last_wallet_event_id varchar nullable; last_event_at timestamptz nullable;
  expires_at; created_at/updated_at.
  Indexes: (merchant_id,status), (subscription_invoice_id), (wallet_payment_id), unique(servana_idempotency_key).
  Provider identifiers deliberately absent (no provider_channel/short-code snapshot/provider_environment/
  merchant_request_id/checkout_request_id) — provider identifiers are Wallet-owned (SUP-01).
  Role and branch are SNAPSHOTTED at initiation. Public-safe attempt ULID returned to the SPA;
  NEVER success-from-initiation. `refunded_externally` records an attempt whose confirmed payment was later
  refunded outside Servana (Wallet-event-driven; not a fund movement by Servana).

subscription_payments (20D-W; Correction 14.10 — ONE AGGREGATE ROW PER WALLET PAYMENT) — id; ulid;
  merchant_id FK RESTRICT; subscription_invoice_id FK RESTRICT;
  subscription_payment_attempt_id FK nullable;                       -- nullable: direct C2B has no attempt row (14.9)
  wallet_payment_id varchar not null UNIQUE;                         -- unconditional uniqueness is now coherent:
                                                                     -- exactly one aggregate per Wallet payment resource
  provider_reference_masked varchar nullable;                        -- replaces the v3 mpesa_receipt_number
  wallet_settlement_status varchar nullable;                         -- projection (e.g. SETTLEMENT_PENDING/SETTLED); feeds payment_cleared gating (§58B.1)
  total_confirmed_minor bigint;                                      -- maintained sum of child receipt rows
  currency char(3); first_confirmed_at timestamptz; last_confirmed_at timestamptz; created_at/updated_at.
  Partial receipts NEVER create additional payment rows; each confirmed receipt appends a child row below.
  Application invariant: sum(receipt rows) <= Wallet received amount, checked at apply time and by the
  nightly reconciliation job. Corrections only via subscription_payment_reversals rows, never edits.

subscription_payment_receipts (20D-W; Correction 14.10 — append-only child of subscription_payments) —
  id; ulid; merchant_id FK RESTRICT; subscription_payment_id FK RESTRICT;
  confirming_wallet_event_id varchar not null UNIQUE;                -- exactly-once application per Wallet event
  wallet_receipt_sequence varchar nullable;                          -- Wallet's receipt sequence when published;
  -- unique(wallet_payment_id, wallet_receipt_sequence) is added ONLY if the Gate W contract publishes it
  amount_minor bigint; currency char(3); paid_at timestamptz; created_at.
  Append-only (trigger); one row per confirming Wallet event; allocation to the invoice happens under the
  invoice row lock in the same transaction that inserts the receipt row (§57).

subscription_payment_reversals (20D-W) — id; ulid; merchant_id; subscription_invoice_id;
  subscription_payment_id FK RESTRICT; wallet_event_id varchar unique; kind CHECK in ('reversal','external_refund','chargeback');
  amount_minor bigint (positive; semantic negative); currency; occurred_at; created_at.
  Effect: reduces invoice paid allocation under lock; may move invoice paid→partially_paid→pending_payment,
  re-open balance, and (if grace exhausted) drive escalation via the normal projection service; every row
  audited high-severity; emits subscription.payment_reversed / subscription.refund_issued /
  subscription.chargeback_recorded to R&E (§58B.1). Never deletes or edits the original payment row.

wallet_webhook_inbox (20D-W) — id; ulid; wallet_event_id varchar not null unique; event_type varchar;
  event_version varchar; environment varchar; wallet_payment_id varchar nullable; wallet_attempt_id varchar nullable;
  occurred_at timestamptz;                                            -- Wallet's creation timestamp
  payload_hash char(64); payload_encrypted text;                      -- verified payload, encrypted at rest
  signature_key_id varchar; signature_verified_at timestamptz;
  received_at; processing_status CHECK in ('received','processed','duplicate','failed','ignored');
  processed_at nullable; failure_code varchar nullable; attempt_count int default 0; next_retry_at nullable.
  VERIFIED EVENTS ONLY (Correction 14.7): a row is inserted only AFTER constant-time signature verification
  succeeds, so an unverified request can never occupy the canonical wallet_event_id uniqueness constraint
  (no event-ID squatting). Failed verifications produce NO inbox row; they produce a high-severity security
  audit event with the body/request hash + minimal non-sensitive metadata, plus metrics/alerts (§9 rule 21).
  No separate runtime rejection table exists or is planned in the adoption PR.
  Append-only on payload columns (trigger). Retention: 13 months, then archived per §74 policy.
  Unique wallet_event_id (first-seen verified insertion) is the FINAL replay protection
  (DB constraint, per Wallet §37 principle).

billing_reconciliation_exceptions (20D-W; replaces the v3 mpesa_reconciliation_events) — id; ulid;
  merchant_id nullable; subscription_invoice_id nullable; wallet_payment_id varchar nullable;
  wallet_event_id varchar nullable; source CHECK in ('wallet_event','allocation_recon','stale_attempt','inbound_gap');
  reason CHECK in ('unknown_payment','unmatched_reference','amount_mismatch','wallet_payment_reused',
    'allocation_drift','underpayment_conflict','overpayment_review','stale_no_status','event_order_conflict',
    'reversal_exceeds_allocation','duplicate_confirmation');
  severity CHECK in ('normal','high','critical');
  resolution_status CHECK in ('open','resolved','dismissed'); resolved_by nullable; resolution_note nullable;
  resolution_action CHECK in ('link_to_invoice','credit_created','adjustment','dismissed') nullable;
  created_at/updated_at. Index (resolution_status, severity), (merchant_id).
  Super Admin resolves by LINKING an already-Wallet-confirmed payment to the correct invoice
  (reconciliation, not manual recording — unchanged principle); resolve requires reason + MFA step-up +
  before/after audit; maker/checker for critical severity.

subscription_invoice_payment_locks (20D-W) — id; subscription_invoice_id FK RESTRICT unique; locked_by;
  locked_at; lock_expires_at. Prevents concurrent STK initiation while unexpired. (Unchanged from v3.)

merchant_billing_credits (20D-W) — id; ulid; merchant_id FK RESTRICT; amount_minor; currency;
  source CHECK in ('overpayment','adjustment'); source_payment_id nullable; balance_minor; created_at.
  Overpayment credit for merchant→Servana billing only. (Unchanged from v3.)
```

### 13.12 Schema Summary (canonical DDL: data dictionary) — Compensation (Corrections 2.2, 2.3, 19)

```text
personnel_compensation_plans (20F) — id; ulid; merchant_id FK RESTRICT; branch_id FK RESTRICT; staff_profile_id FK RESTRICT;
  compensation_model varchar CHECK in ('commission_only','salary_plus_commission','salary_only');
  salary_amount_minor bigint nullable; salary_currency char(3) nullable; pay_period CHECK in ('monthly','weekly','daily','hourly','per_shift') nullable;
  commission_rule_id FK commission_rules RESTRICT nullable; high_value_threshold_minor bigint nullable; suspension_salary_policy varchar not null default 'continue' CHECK in ('pause','continue') (Plan A-11; prospective override only);
  status varchar CHECK in ('draft','pending_approval','scheduled','active','superseded','expired','rejected','cancelled'); effective_from date; effective_to date nullable;
  created_by; approved_by; approved_at; change_reason; created_at/updated_at. Date-range exclusion constraint for active/scheduled/pending per (staff_profile, branch). Active monetary terms immutable — supersede with a new version. Model rules enforced (no cross-model ledger leakage).
compensation_plan_history (20F) — id; merchant_id; personnel_compensation_plan_id FK RESTRICT; from_status/to_status; actor; reason; created_at (append-only)
commission_rules (20F) — Correction 2.2 exact DDL:
  id; ulid; merchant_id FK merchants RESTRICT; branch_id FK merchant_branches RESTRICT; name;
  calculation_type CHECK in ('percentage','fixed_amount'); calculation_basis CHECK in ('service_item_net_amount','service_item_gross_amount','validated_paid_allocation');
  percentage_basis_points int nullable (0..10000); fixed_amount_minor bigint nullable; currency char(3) nullable; applies_to_preferred_personnel_fee boolean default false;
  effective_from date; effective_to date nullable; status CHECK in ('draft','pending_approval','scheduled','active','expired','superseded','rejected','cancelled');
  created_by; approved_by; approved_at; rejected_by; rejected_at; rejection_reason; change_reason text not null; created_at/updated_at.
  Percentage requires bp 0..10000 + null fixed; fixed requires positive minor + currency + null bp; exclusion constraint prevents overlapping active/scheduled ranges per merchant/branch/scope; active rules with monetary effect are superseded, not edited.
commission_ledger (20G) — Correction 2.3 exact DDL:
  id; ulid; merchant_id; branch_id; staff_profile_id; compensation_plan_id; commission_rule_id; service_session_id nullable; invoice_id; invoice_item_id;
  payment_record_id nullable; payment_validation_event_id nullable; source_entry_id nullable (self-FK);
  entry_type CHECK in ('pending_preview','earned','reversal','adjustment'); reversal_reason CHECK in ('invoice_voided','payment_reversed','refund_finalized','manual_adjustment','correction') nullable;
  calculation_basis_minor bigint; rate_basis_points int nullable; fixed_rate_minor bigint nullable; amount_minor bigint; currency;
  earned_at nullable; status CHECK in ('pending','earned','included_in_payout','paid','reversed','adjusted','cancelled'); payout_item_id nullable; created_by; approved_by; created_at.
  Earned only after Finance validates the payment; salary_only plans never generate rows; reversals are new negative rows referencing the original; idempotency unique (payment_validation_event_id, invoice_item_id, staff_profile_id, entry_type) for earned; sum of allocations ≤ eligible validated allocation.
salary_ledger (20G) — id; ulid; merchant_id; branch_id; staff_profile_id; compensation_plan_id; pay_period_start date; pay_period_end date;
  amount_minor; currency; entry_type CHECK in ('accrual','adjustment','reversal'); status CHECK in ('pending','included_in_payout','paid','reversed','adjusted'); payout_item_id nullable; created_at (append-only).
  Scheduler-created per pay period; idempotency unique (compensation_plan_id, staff_profile_id, pay_period_segment, entry_type); mid-period changes split by effective dates.
compensation_adjustments (20G/20H) — id; ulid; merchant_id; branch_id; staff_profile_id; amount_minor; currency; reason; approved_by; created_at
personnel_payout_runs (20H) — id; ulid; merchant_id; branch_id; period_start/period_end date; high_value_threshold_snapshot_minor bigint;
  status varchar CHECK in ('draft','submitted','finance_verified','pending_merchant_admin_approval','approved','paid','rejected','cancelled');
  gross_total_minor; created_by(HR); submitted_by; verified_by(Finance); approved_by; paid_by; external_payment_reference_encrypted nullable; paid_at; created_at/updated_at. Frozen on submit; corrections via rejection→new draft or adjustment run.
personnel_payout_items (20H) — id; ulid; merchant_id; branch_id; payout_run_id FK RESTRICT; staff_profile_id; salary_amount_minor; commission_amount_minor; adjustment_amount_minor; gross_amount_minor; source_ledger_refs jsonb; status mirrors run; created_at
earnings_queries (20H) — id; ulid; merchant_id; branch_id; staff_profile_id (own); subject_type CHECK in ('commission_ledger','salary_ledger','payout_item'); subject_id; query_type; status CHECK in ('open','assigned','resolved','rejected'); assigned_to; resolution_note; created_at/updated_at. Resolution never mutates ledgers silently — monetary correction creates an adjustment entry.
```

### 13.13 Schema Summary (canonical DDL: data dictionary) — Files, SMS, Notifications (Corrections 13.3, 20.4)

```text
uploaded_files (10F) — Correction 13.3 exact DDL: id; ulid; merchant_id nullable; branch_id nullable; owner_user_id nullable;
  purpose CHECK in ('merchant_logo','profile_photo','dispute_evidence','audit_evidence','finance_export','invoice_pdf','receipt_pdf','billing_invoice_pdf','earnings_statement','day_close_report','cash_up_report');
  storage_disk; quarantine_path; final_path nullable; original_filename_encrypted; safe_download_filename; declared_mime_type nullable; detected_mime_type nullable; extension nullable;
  size_bytes bigint; sha256 char(64); scan_status CHECK in ('pending','clean','infected','scan_failed','rejected'); lifecycle_status CHECK in ('quarantined','available','revoked','expired','deleted');
  retention_until nullable; uploaded_by nullable; available_at nullable; revoked_at nullable; created_at/updated_at. Indexes (merchant_id, purpose, lifecycle_status), (branch_id, purpose), sha256, (scan_status, created_at).
file_scan_events (10F) — Correction 13.3: id; uploaded_file_id FK RESTRICT; scanner; engine_version nullable; signature_version nullable; result CHECK in ('clean','infected','error'); malware_name nullable; error_code nullable; scanned_at
personnel_sms_campaigns (21S) — Correction 20.4: id; ulid; merchant_id; branch_id; staff_profile_id; message_body_encrypted; message_template_id nullable;
  recipient_count int; estimated_cost_minor; final_cost_minor nullable; currency;
  status CHECK in ('draft','confirmed','queued','sending','completed','partially_failed','failed','cancelled'); consent_snapshot_at; created_by; confirmed_at; created_at/updated_at
personnel_sms_recipients (21S) — Correction 20.4: id; campaign_id FK RESTRICT; client_id FK RESTRICT; service_session_id nullable; phone_encrypted; phone_last_four;
  eligibility_snapshot_json jsonb; consent_status_snapshot; delivery_status CHECK in ('pending','sent','delivered','failed','opted_out','suppressed'); provider_message_id nullable; cost_minor nullable; created_at/updated_at; UNIQUE (campaign_id, client_id)
sms_delivery_attempts (21S) — Correction 20.4: id; recipient_id FK RESTRICT; attempt_number int; provider; status; provider_code; provider_message_redacted; attempted_at; next_retry_at nullable
sms_billing_entries (21S) — Correction 20.4: id; merchant_id; branch_id; campaign_id; quantity int; unit_cost_minor; amount_minor; currency; status CHECK in ('provisional','billable','invoiced','credited','cancelled'); billing_invoice_line_id nullable; created_at
notifications (21N) — id; ulid; merchant_id nullable; branch_id nullable; notifiable_user_id; channel CHECK in ('mail','database'); type; data jsonb (no secrets/PII beyond masked); read_at nullable; created_at
scheduled_report_runs (21N) — id; ulid; merchant_id; branch_id; report_type CHECK in ('day_close','cash_up'); business_date date; status CHECK in ('queued','generated','emailed','failed'); file_id nullable; created_at; UNIQUE (branch_id, business_date, report_type) idempotency key
```

### 13.14 Eloquent Relationships, Factories, Seeders, Tests (per table)
Each table's data-dictionary entry lists: example Eloquent relationships (e.g., `Invoice hasMany InvoiceItem`, `Invoice hasMany PaymentRecord`, `PaymentRecord hasMany PaymentValidationEvent`, `CommissionLedger belongsTo CommissionRule`); required factories (tenant-aware, producing valid scoped rows); required seeders (permissions, roles, plans/prices/entitlements, platform billing settings); and required tests (unit/feature/isolation/migration). Factories must never bypass tenant scoping; seeders are idempotent.

### 13.15 Schema Summary (canonical DDL: data dictionary) — Correction-3 Scope Entities and Split-Payment Group (Corrections 3, 7)
New launch tables that complete scope coverage. Canonical DDL lives in the data dictionary; these are summaries.

```text
payment_recording_groups (18A) [Correction 7 — durable split/multi-payment group] —
  id; ulid; merchant_id FK RESTRICT; branch_id FK RESTRICT; invoice_id FK invoices RESTRICT; maker_user_id FK users RESTRICT;
  total_amount_minor bigint; currency char(3); idempotency_key_id FK idempotency_keys RESTRICT nullable;
  status varchar CHECK in ('draft','recorded','pending_validation','validated','rejected','correction_required','reversed');
  recorded_at; submitted_for_validation_at; validated_at; rejected_at; created_at/updated_at; index (invoice_id, status).
  One group = one recording workflow; >=1 component payment_records reference it (payment_records.payment_recording_group_id);
  total = sum(components); single currency; sum(components) <= invoice balance; Finance validates the WHOLE group atomically
  producing ONE validation event and ONE receipt covering all validated components; refund/commission allocate by component (§41/§42/§43/§44).
invoice_number_sequences (17) [Correction 3] — id; merchant_id FK RESTRICT; scope CHECK in ('merchant_client_invoice'); next_value bigint; prefix varchar nullable;
  unique (merchant_id, scope). Gap-free per-merchant allocation under row lock at finalization; never reused; audited.
receipt_number_sequences (18B) [Correction 3] — id; merchant_id FK RESTRICT; scope CHECK in ('receipt'); next_value bigint; prefix varchar nullable;
  unique (merchant_id, scope). Gap-free per-merchant receipt numbering under row lock at issuance.
payment_reference_checks (18A) [Correction 3 — durable duplicate-reference detection record] —
  id; ulid; merchant_id FK RESTRICT; branch_id FK RESTRICT; payment_record_id FK RESTRICT; method varchar; reference_normalized varchar;
  result CHECK in ('unique','duplicate_suspected','override_approved'); matched_payment_record_id nullable; checked_at; override_by nullable; override_reason nullable.
  Makes duplicate-reference detection (§41) durable and auditable; unique partial index (merchant_id, method, reference_normalized) where method requires a reference.
billing_escalation_events (20B) [Correction 3 — durable overdue escalation log] —
  id; ulid; merchant_id FK RESTRICT; subscription_invoice_id FK RESTRICT nullable; merchant_subscription_id FK RESTRICT;
  event_type CHECK in ('reminder','grace_entered','overdue','suspended_billing','recovered'); from_billing_status; to_billing_status; reason; created_at (append-only).
  Drives/records the shared overdue escalation (§54); idempotent per (merchant_subscription_id, event_type, period boundary); feeds Super-Admin overdue-escalation reporting.
```

### 13.16 Correction-3 Consolidation Mappings (no scope entity silently disappears)
Scope entities implemented by an existing table rather than a new one. Each mapping is behavior-preserving and tested for equivalence.

```text
billing_invoice_lines  ->  subscription_invoice_items
  Reason: identical concept (line items of a subscription/billing invoice). Field map: line description->description; amount->amount_minor;
  line type->type ('plan_fee'|'platform_fee_rollup'|'sms_rollup'|'adjustment'). Lifecycle: created at issuance, immutable thereafter (parent immutability).
  Permission: merchant.subscription.invoice.view / platform billing perms. Audit: parent invoice issue/adjust events. Retention: with parent invoice.
  Migration: none (no legacy table). Tests: rollup composition (plan + platform_fee + sms), immutability after issuance.

billing_reconciliation_records  ->  billing_reconciliation_exceptions
  Reason: same concept (records reconciling confirmed provider funds to invoices). Field map: match/exception state->type+resolution_status;
  exception reason->exception_reason; linkage->merchant_id/subscription_invoice_id/subscription_payment_id. Lifecycle: open->resolved|dismissed.
  Permission: platform.billing_reconciliation.view/resolve. Audit: reconciliation resolve events (+step-up). Retention: with billing/financial history.
  Migration: none. Tests: matched auto-apply, each exception_reason path, resolve-by-linking (no manual recording).

receipt_reissues  ->  receipts (self-referencing reissue_of_receipt_id)
  Reason: a reissue is a new receipt row referencing the original; a separate table would duplicate receipt structure. Field map: original->reissue_of_receipt_id;
  new number from receipt_number_sequences. Lifecycle: issued (new tracking row); original preserved. Permission: receipt.reissue (Finance).
  Audit: receipt reissue event. Retention: with receipts. Migration: none. Tests: reissue creates new row referencing original; original immutable; one current receipt resolvable.
```

---

### 13.17 Schema Summary (canonical DDL: data dictionary) — Citrus Refer & Earn Integration (Phases 21R‑A, 21R‑B)

Data-dictionary file: `docs/architecture/data-dictionary/refer-earn-integration.md`.

```text
referral_snapshots (21R-A) — id; ulid; merchant_id FK merchants RESTRICT unique;   -- at most one per merchant
  raw_code_encrypted text;                       -- exactly as submitted, encrypted (evidence)
  code_normalized varchar nullable;              -- uppercased/trimmed, e.g. SERVANA-X8T2K; null if invalid_format
  capture_channel CHECK in ('query_param','manual_entry','central_redirect');
  captured_at timestamptz;                       -- inside the registration transaction
  landing_metadata jsonb nullable;               -- utm-style minimal, no PII, allowlisted keys only
  snapshot_status CHECK in ('captured','invalid_format','validating','validated','rejected','confirmed','expired_unconfirmed');
  re_validation_result_code varchar nullable; re_attribution_public_id varchar nullable;
  confirmed_at nullable; last_transition_at; created_at/updated_at.
  Immutable after 'confirmed'/'rejected' (status may not regress; trigger-enforced).
  Data minimization: NO referrer identity stored — only the code and R&E public attribution id.
  Failure if omitted: attribution evidence is lost if R&E is briefly unavailable at registration,
  breaking the referrer's legitimate claim (R&E integration case requires local snapshot + retry).

re_outbound_events (21R-A; transactional outbox, append-only) — id; ulid;
  event_id char(26) not null unique;             -- becomes X-Citrus-Event-Id; generated at insert; stable across retries
  event_type varchar not null;                   -- catalogue §58B.1
  event_version varchar not null;                -- e.g. '1'
  merchant_id FK merchants RESTRICT nullable;    -- null only for product-level events (none at launch)
  merchant_public_id char(26) nullable;          -- denormalized for payload stability
  sequence_no bigint not null;                   -- per-merchant monotonic (ordering partition key)
  payload jsonb not null;                        -- minimal-fact body per §58B.2; append-only (trigger)
  content_sha256 char(64) not null;              -- computed at insert over canonical JSON
  occurred_at timestamptz not null;              -- business time of the source fact
  delivery_status CHECK in ('pending','delivering','delivered','dead_letter','superseded');
  delivered_at nullable; attempt_count int default 0; next_attempt_at nullable;
  last_response_status int nullable; last_error_code varchar nullable; created_at.
  Unique (merchant_id, sequence_no). Index (delivery_status, next_attempt_at).
  Inserted in the SAME DB transaction as the source domain change (outbox pattern) —
  a fact and its event row commit or roll back together.

re_event_deliveries (21R-A) — id; re_outbound_event_id FK RESTRICT; attempted_at; duration_ms int;
  response_status int nullable; response_class varchar; error_code nullable;
  response_body_truncated_redacted varchar(512) nullable; created_at.
  Full delivery history per event (R&E-side dedupes by event_id+hash; Servana retries same id+body).

re_activity_rule_versions (21R-B) — id; ulid; re_rule_public_id varchar nullable; campaign_public_id varchar nullable;
  rule_version int not null; qualification_period_type CHECK in ('calendar_month');
  min_completed_sessions int not null default 10; min_validated_invoices int not null default 3;
  require_subscription_fully_paid boolean not null default true;
  disqualify_on_fraud_or_manual_suspension boolean not null default true;
  grace_days_after_period_close int not null default 5;
  effective_from date not null; effective_to date nullable;
  status CHECK in ('active','superseded'); source CHECK in ('platform_config','re_sync');
  created_by nullable; created_at/updated_at.
  Exclusion constraint: no overlapping effective ranges per (campaign_public_id, coalesced).
  Purpose: the rule Servana applies is version-pinned and immutable per period; changes create a new
  version effective prospectively (R&E "no silent rule change" principle).

re_qualification_periods (21R-B) — id; ulid; merchant_id FK RESTRICT; period_start date; period_end date;
  rule_version_id FK re_activity_rule_versions RESTRICT;
  evaluation_status CHECK in ('pending','evaluated','corrected','skipped_unattributed');
  evaluated_at nullable; unique (merchant_id, period_start, rule_version_id).

re_qualification_decisions (21R-B; append-only) — id; ulid; re_qualification_period_id FK RESTRICT;
  merchant_id; decision_version int not null;    -- 1..n; corrections increment
  decision CHECK in ('qualified','not_qualified');
  failure_category varchar nullable CHECK in (null,'insufficient_sessions','insufficient_validated_invoices',
    'subscription_not_fully_paid','fraud_or_manual_suspension','merchant_closed','attribution_invalid');
  qualifying_session_count int; required_session_count int;
  validated_invoice_count int; required_invoice_count int;
  subscription_paid boolean; suspension_clear boolean;
  evidence_checksum char(64);                    -- sha256 over the canonical evidence tuple
  outbound_event_id char(26) nullable;           -- links to the emitted re_outbound_events.event_id
  supersedes_decision_id FK self nullable;       -- required when decision_version > 1
  decided_at; created_at.
  Unique (re_qualification_period_id, decision_version). Same-version-different-content is an integrity
  error (guarded by unique + checksum comparison; conflict opens a critical incident, mirrors R&E §11.6).

re_inbound_requests (21R-B; replay protection for R&E→Servana reconciliation) — id;
  request_nonce varchar unique; key_id varchar; content_sha256 char(64); route varchar;
  received_at; response_status int; created_at. Retention 90 days.
```

## 14. Multi-Tenancy Model
- **Tenant key:** `merchant_id` on every tenant-owned table; enforced by the `BelongsToMerchant` trait + `MerchantScope` global scope, which auto-fills `merchant_id` on create and throws `MissingTenantContext` when unscoped.
- **Context resolution:** `ResolveTenantContext` middleware resolves the active merchant from the authenticated membership, pinned **before** `SubstituteBindings`; `terminate()` resets context per request.
- **Scoped route binding:** `resolveRouteBinding()` resolves within merchant scope; a foreign-tenant ULID returns 404 (no existence leak) and writes a high-severity `unauthorized_access` audit row.
- **Jobs:** `TenantAwareJob` captures merchant/branch IDs, rehydrates and re-validates context in `handle()`, and fails safely when context is absent or the merchant is not active.
- **Escape hatch:** `withoutTenancy()` is the only sanctioned bypass, permitted only inside `Tenancy`/`Platform`; banned elsewhere by `NoWithoutTenancyOutsidePlatformRule` and a source-scan test.
- **Posture:** cross-tenant access → 404; the platform context (Super Admin) never inserts merchant membership and never gains merchant operational permissions.
- **Tests:** `TenantColumnCoverageTest`; cross-tenant denial for every tenant/branch domain; scoped-binding tests; job tenancy tests; suspended-merchant denial.

### 14.1 Integration Tenancy, Job Context, and Data Isolation

1. **Webhook tenant resolution:** Wallet webhooks carry no Servana session. Tenant is resolved server-side by mapping `wallet_payment_id → subscription_invoices.merchant_id` (via the registration link). Events whose payment maps to no invoice open a reconciliation exception (`reason='unknown_payment'`) — they never guess a tenant.
2. **Job tenant propagation:** `ProcessWalletWebhookJob` and `DeliverReOutboxJob` serialize explicit IDs (inbox row ID / outbox row ID) and re-derive tenant context inside the job under ADR‑002 rules; `withoutTenancy()` remains banned outside Tenancy/Platform and the two Integration contexts' narrowly-scoped resolvers (each usage individually allowlisted in the static-analysis config with a justification comment).
3. **Cross-tenant denial cases (tested):** a Wallet event for merchant A's payment can never mutate merchant B's invoice (application requires the event's payment ID to equal the invoice's registered `wallet_payment_id`); an R&E reconciliation query scoped to one merchant reference returns only that merchant's facts; outbox events always carry the `merchant_public_id` bound at insert time inside the originating tenant-scoped transaction.
4. **Exports/notifications:** unchanged rules; reconciliation-exception exports are platform-scoped, masked, async, audited.

## 15. Branch-Scope Model
- **Branch key:** `branch_id` (+ `merchant_id`) on branch-owned tables; `BelongsToBranch` trait + `BranchScope` restricts merchant-wide roles to own-merchant branches via subquery and limits branch-scoped roles to assigned branches.
- **Assignment:** an active `branch_user_assignments` row is required for branch-scoped roles; `EnsureBranchScope` returns 404 for a foreign-branch ULID (with audit) and 403 `no_branch_scope` when the user has no assignment.
- **Posture:** same-tenant out-of-branch access returns the documented 403 (distinct from the cross-tenant 404).
- **Tests:** cross-branch denial for every branch domain; admin sees all own-merchant branches; branch-assignment-required-to-activate.

## 16. Personnel Own-Scope Model
- **Own key:** own-scope endpoints derive `staff_profile_id` from the authenticated membership; **no** route accepts another personnel identifier for own-scope resources.
- **Surfaces:** own queue/appointments/sessions, own served clients (masked contact), own compensation/earnings/statements/payouts, own earnings queries, own served-client SMS.
- **Contact protection:** no export endpoint; no bulk full-phone response; guessed export-shaped or cross-personnel routes return 404 and write a high-severity unauthorized-access audit event.
- **Tests:** personnel can act only on own resources; cannot view/message another personnel member's served clients; cannot export contacts; guessed routes 404 + audit.

## 17. Authentication Model
- **Mechanism:** passwordless Magic Link → Sanctum stateful session. `users.password` is nullable (Plan A-03).
- **Magic Link:** 64-byte random token, SHA-256 at rest, short expiry (15 min), atomic single-use (conditional UPDATE). Raw token only in the emailed link; never stored/logged/returned.
- **Seven-check eligibility** (`LoginEligibilityService`, scope §2.3): (1) user exists+active; (2) active membership; (3) account not suspended/deactivated; (4) role valid; (5) merchant operational status permits sign-in; (6) branch assignment present for branch-scoped roles; (7) email/rate eligibility. Uniform 202 request response and uniform 422 `invalid_or_expired_token` verify response prevent enumeration.
- **Sessions:** session-ID regeneration on login; `EnforceIdleTimeout` (60-min sliding); suspension/deactivation revokes sessions, tokens, unconsumed links, and pending invitations (completed in R6); membership+role re-checked every authenticated request.
- **Rate limiting:** named Magic-Link limiters → structured 429.

### 17.1 Machine-to-Machine Identities (Integrations)

Human authentication is unchanged (Magic Link + Sanctum, MFA per role). Four machine identities exist:

| Identity | Direction | Mechanism | Credential custody |
|---|---|---|---|
| Servana product application @ Wallet | Servana → Wallet | Wallet-issued per-environment machine credentials (Wallet §7.4/§14); TLS with cert verification; `Idempotency-Key` on money-adjacent creates | `servana/{env}/wallet/api_credentials` |
| Wallet application webhook sender | Wallet → Servana | Algorithm-aware signature (HMAC or asymmetric per Wallet §35; selected by algorithm identifier + key ID + contract version) + timestamp + replay window + per-application credentials; dual-key rotation window | `servana/{env}/wallet/webhook_secret_{key_id}` |
| Servana service account @ R&E | Servana → R&E | `X-Citrus-*` header contract + canonical-string HMAC (R&E dev plan §11.7); key ID selects key; nonce per request | `servana/{env}/refer-earn/signing_key_{key_id}` |
| R&E reconciliation caller @ Servana | R&E → Servana | Same canonical construction, distinct inbound secret; nonce stored in `re_inbound_requests` | `servana/{env}/refer-earn/inbound_secret_{key_id}` |

Rules: disjoint credentials per environment (startup guard, §9 rule 24); rotation runbooks in §77.1; all four identities appear on the integrations-health dashboard (§12.1 item 4) with last-success timestamps; sandbox `FakeWalletClient`/`FakeReferEarnClient` are used in CI so no real credential ever reaches test environments.

## 18. MFA and Step-Up Authentication (Phase R3)
- **TOTP:** enrollment + confirmation; secret encrypted at rest (`mfa_credentials`); one-time recovery codes stored hashed (`mfa_recovery_codes`).
- **Mandatory MFA roles:** Super Administrator, Merchant Administrator, Finance — privileged routes deny absent/unconfirmed MFA.
- **Step-up freshness:** a fresh MFA assertion (configurable freshness window) is required for: platform billing configuration; refund finalization; period reopen; payout approval; payout mark-paid; billing-reconciliation resolution; integration key-set/rule-version management; qualification correction; sensitive/backdated compensation changes. Stale step-up is denied (re-challenge).
- **Enforcement order:** MFA state is checked immediately after authentication and before tenant context (Section 9.4 step 2); step-up freshness is checked just before validation for designated actions (step 13).
- **Tests:** privileged route denies absent/stale MFA; recovery-code single-use; step-up required for each designated action; encrypted secret never logged.

---

## 19. Authorization Model and Complete Permission Matrix

### 19.1 Model
Authorization resolves through: role defaults + per-membership overrides, with **deny beats grant**; suspended/deactivated members resolve to no permissions. The runtime registry remains the in-code source of truth but must be **generated from or mechanically compared with** `docs/auth/permission-matrix.yaml` (the source-controlled security contract). The reported as-built registry (54 keys × 8 roles) is a baseline to be reconciled to the canonical catalogue below in the owning feature phases, with the parity test (Section 19.5) preventing drift.

### 19.2 Canonical Permission Catalogue (`docs/auth/permission-matrix.yaml`)
Grouped by domain (Correction 16.2). Sensitive platform mutations require mandatory MFA + fresh step-up; no platform permission grants merchant operational access.

```text
# Platform Governance (Super Administrator only)
platform.settings.view | platform.settings.update | platform.billing_settings.view | platform.billing_settings.update
platform.plan.view | platform.plan.manage | platform.plan_price.manage | platform.promotion.manage
platform.free_period_offer.manage | platform.preferred_personnel_fee.manage | platform.wallet_configuration.manage
platform.billing_reconciliation.view | platform.billing_reconciliation.resolve | platform.integrations.wallet.manage
platform.integrations.refer_earn.manage | platform.integrations.health.view
platform.referral.qualification.view | platform.referral.qualification.correct | platform.merchant.view | platform.merchant.suspend
platform.merchant.reactivate | platform.merchant.deactivate | platform.registration_monitor.view | platform.audit.view | platform.audit.export

# Merchant Ownership and Billing (Merchant Administrator)
merchant.profile.view | merchant.profile.update | merchant.subscription.view | merchant.subscription.plan_change
merchant.subscription.invoice.view | merchant.subscription.invoice.download | merchant.subscription.pay
merchant.billing_attempts.view_detailed | merchant.branch.create | merchant.branch.view_all | merchant.user.view_all
merchant.user.suspend | merchant.user.deactivate | merchant.report.view_all_branches | merchant.compensation_summary.view
merchant.payout.approve_high_value | merchant.period_reopen.approve_exception

# Branch Management (Branch Manager)
branch.profile.view | branch.profile.update | branch.calendar.manage | branch.day.open | branch.day.pause | branch.day.close
branch.day.reopen | branch.cash_up.submit | branch.dashboard.view | branch.report.view | service.view | service.create
service.update | service.archive | preferred_personnel_fee.view_branch_rule | merchant.subscription.pay_from_branch

# HR and Staff (HR)
staff.view | staff.invite | staff.invitation.resend | staff.invitation.revoke | staff.profile.create | staff.profile.update
staff.role.assign | staff.branch.assign | staff.suspend | staff.deactivate | staff.history.view | personnel.eligibility.manage
personnel.availability.manage | compensation.plan.view | compensation.plan.create | compensation.plan.update_draft
compensation.plan.submit | compensation.plan.approve | compensation.plan.reject | compensation.plan.cancel
compensation.history.view | payout_run.create | payout_run.update_draft | payout_run.submit | payout_run.cancel_draft

# Front Office Operations (Front Office)
client.view | client.create | client.update | appointment.view | appointment.create | appointment.reschedule
appointment.cancel | appointment.check_in | appointment.assign | appointment.transfer | queue.view | queue.create
queue.assign | queue.transfer | queue.reorder | service_session.view | service_session.start | service_session.complete
service_session.cancel | preferred_personnel.select | invoice.view | invoice.create | customer_payment.record
receipt.view | merchant.subscription.pay_simple | front_office.search

# Finance
invoice.view | invoice.void.request_or_execute_as_policy | invoice.adjustment.manage | customer_payment.view
customer_payment.validate | customer_payment.reject | customer_payment.reference_correct | customer_payment.duplicate_override
customer_payment.record_exception | receipt.view | receipt.reissue | refund.create | refund.approve | refund.finalize
finance_dispute.manage | cash_up.view | cash_up.approve | cash_up.reject | cash_up.request_correction | period_lock.create
period_lock.reopen | finance_export.create | finance_export.download | subscription.payment_attempts.view
merchant.subscription.pay | compensation.liability.view | compensation.adjustment.create | payout_run.verify
payout_run.approve_standard | payout_run.reject | payout_run.mark_paid | earnings_query.respond | finance.audit.view

# Personnel Own-Scope
personnel.my_queue.view | personnel.my_appointments.view | personnel.my_sessions.view | personnel.my_served_clients.view
personnel.my_compensation.view | personnel.my_earnings.view | personnel.my_statements.download | personnel.my_payouts.view
personnel.my_earnings_query.create | personnel.my_sms.send

# Audit
audit.branch_events.view | audit.compensation.view | audit.finance.view | audit.export | audit.flagged_event.create
audit.flagged_event.update_status | audit.flagged_event.resolve_metadata
```

### 19.3 Per-Permission Attributes and Populated Matrix (every key)

**Schema (stored for every key; CI fails if any key is missing any attribute):**
```text
key | description | default_roles | override_policy(grantable|revocable_only|non_overridable) |
scope(platform|merchant|branch|own) | billing_read_only_behavior(allow_read|block) | period_lock_behavior(enforced|n/a) |
entitlement_key(nullable) | mfa_required(bool) | step_up_required(bool) | audit_event | audit_severity | maker_checker_incompatibilities |
backend_policy_or_service | frontend_ux_usage | positive_tests | negative_tests
```
`docs/auth/permission-matrix.yaml` carries the **complete, schema-validated** machine-readable entry for **every** key with all attributes above; a YAML schema-validation test plus the §19.5 parity test fail the build if any key is absent or any attribute is unset. The human-readable populated matrix below covers every key; columns: **scope** (P/M/B/O), **ent**(itlement key or –), **billRO** (read_only_grace/suspended_billing mutation behavior: A=pure read always allowed, R=mutation blocked by billing gate, – = platform/n/a), **PL** (period-lock enforced), **MFA**, **SU** (step-up), **sev** (audit severity), **MC** (maker/checker-incompatible permission). `backend_policy_or_service` = the policy/action enforcing the key; `frontend_ux_usage` = visibility only. Tests for every key follow the naming convention `PermissionMatrix/{key}_allows` (positive) and `PermissionMatrix/{key}_denies` (negative), enforced by §19.5.

**Group defaults** (each row below inherits these unless its line overrides): Platform group → scope P, MFA Y, billRO –, PL n/a; Merchant Admin group → scope M, MFA Y; Branch Manager/HR/Front Office groups → MFA –; Finance group → MFA Y; Personnel group → scope O, MFA –; Audit group → scope B, MFA –, read-only. Pure-read keys are sev info with no SU/PL/MC.

```text
# Platform Governance (default_roles: super_admin; non_overridable: never grants merchant-operational access)
platform.settings.view            P|ent -|billRO -|PL n/a|MFA Y|SU -|sev info|MC -        policy PlatformSettingsPolicy
platform.settings.update          P|-|-|n/a|Y|SU Y|high|-                                 policy PlatformSettingsPolicy
platform.billing_settings.view    P|-|-|n/a|Y|-|info|-
platform.billing_settings.update  P|-|-|n/a|Y|SU Y|high|-                                 svc UpdatePlatformBillingSettings (ADR-011 price rules)
platform.plan.view                P|-|-|n/a|Y|-|info|-
platform.plan.manage              P|-|-|n/a|Y|SU Y|high|-
platform.plan_price.manage        P|-|-|n/a|Y|SU Y|high|-                                 svc ManagePlanPrice (sole price source)
platform.promotion.manage         P|-|-|n/a|Y|SU Y|high|-
platform.free_period_offer.manage P|-|-|n/a|Y|SU Y|high|-
platform.preferred_personnel_fee.manage P|-|-|n/a|Y|SU Y|high|-                          svc ManagePreferredPersonnelFeeRule (fixed+percentage)
platform.wallet_configuration.manage P|-|-|n/a|Y|SU Y|crit|-                             svc: webhook key-ID sets, breaker reset, inbox replay (was platform.mpesa_configuration.manage; SUP-06)
platform.billing_reconciliation.view P|-|-|n/a|Y|-|info|-                                 (was platform.mpesa_exception.view; SUP-06) masked provider references
platform.billing_reconciliation.resolve P|-|-|n/a|Y|SU Y|high|maker/checker when severity=critical  action ResolveBillingReconciliationException (link_to_invoice | dismiss; never manual payment recording)
platform.integrations.wallet.manage P|-|-|n/a|Y|SU Y|high|-                               webhook key-ID set changes, breaker manual reset, replay of failed inbox rows
platform.integrations.refer_earn.manage P|-|-|n/a|Y|SU Y|high|-                           rule-version creation (prospective only), outbox dead-letter replay, inbound key-ID set changes
platform.integrations.health.view P|-|-|n/a|Y|-|info|-                                    read-only integrations dashboard
platform.referral.qualification.view P|-|-|n/a|Y|-|info|-                                 decisions + evidence summaries (no client PII exists in them)
platform.referral.qualification.correct P|-|-|n/a|Y|SU Y|high|-                           action CorrectQualificationDecision (engine re-run with documented reason; NEVER free-form decision entry)
platform.merchant.view            P|-|-|n/a|Y|-|info|-
platform.merchant.suspend         P|-|-|n/a|Y|SU Y|high|-                                 action SuspendMerchant (merchants.status; never billing recovery)
platform.merchant.reactivate      P|-|-|n/a|Y|SU Y|high|-
platform.merchant.deactivate      P|-|-|n/a|Y|SU Y|crit|-
platform.registration_monitor.view P|-|-|n/a|Y|-|info|-
platform.audit.view               P|-|-|n/a|Y|-|info|-
platform.audit.export             P|-|-|n/a|Y|SU Y|high|-                                 export controls (signed/expiring)

# Merchant Ownership and Billing (default_roles: merchant_admin)
merchant.profile.view             M|-|A|n/a|Y|-|info|-
merchant.profile.update           M|-|R|n/a|Y|-|high|-
merchant.subscription.view        M|-|A|n/a|Y|-|info|-
merchant.subscription.plan_change M|-|R|n/a|Y|-|high|-                                    svc SchedulePlanChange (no proration)
merchant.subscription.invoice.view M|-|A|n/a|Y|-|info|-
merchant.subscription.invoice.download M|-|A|n/a|Y|-|info|-
merchant.subscription.pay         M|-|R(recovery-allowlisted)|n/a|Y|-|high|-             action InitiateSubscriptionStkPush (idempotent)
merchant.billing_attempts.view_detailed M|-|A|n/a|Y|-|info|-
merchant.branch.create            M|plan.branch_limit|R|n/a|Y|-|high|-                    entitlement-gated
merchant.branch.view_all          M|-|A|n/a|Y|-|info|-
merchant.user.view_all            M|-|A|n/a|Y|-|info|-
merchant.user.suspend             M|-|R|n/a|Y|-|high|-
merchant.user.deactivate          M|-|R|n/a|Y|-|crit|-
merchant.report.view_all_branches M|-|A|n/a|Y|-|info|-
merchant.compensation_summary.view M|-|A|n/a|Y|-|info|-
merchant.payout.approve_high_value M|-|R|n/a(payout)|Y|SU Y|crit|payout_run.verify       action ApprovePayoutRun(high-value); threshold snapshot
merchant.period_reopen.approve_exception M|-|R|enforced|Y|SU Y|high|period_lock.reopen   exceptional reopen approval only

# Branch Management (default_roles: branch_manager)
branch.profile.view               B|-|A|n/a|-|-|info|-
branch.profile.update             B|-|R|n/a|-|-|warn|-
branch.calendar.manage            B|-|R|n/a|-|-|warn|-
branch.day.open                   B|-|R|n/a|-|-|info|-                                    action OpenBranchDay
branch.day.pause                  B|-|R|n/a|-|-|info|-
branch.day.close                  B|-|R|n/a|-|-|info|-                                    BranchClosureGuard
branch.day.reopen                 B|-|R|n/a|-|-|warn|-
branch.cash_up.submit             B|-|R|enforced|-|-|warn|cash_up.approve                 maker (Branch Manager)
branch.dashboard.view             B|-|A|n/a|-|-|info|-
branch.report.view                B|-|A|n/a|-|-|info|-
service.view                      B|-|A|n/a|-|-|info|-
service.create                    B|-|R|n/a|-|-|warn|-
service.update                    B|-|R|n/a|-|-|warn|-
service.archive                   B|-|R|n/a|-|-|warn|-
preferred_personnel_fee.view_branch_rule B|-|A|n/a|-|-|info|-                            view-only (rule managed by Super Admin)
merchant.subscription.pay_from_branch B|-|R(recovery-allowlisted)|n/a|Y|-|high|-

# HR and Staff (default_roles: hr)
staff.view                        B|-|A|n/a|-|-|info|-
staff.invite                      B|-|R|n/a|-|-|warn|-
staff.invitation.resend           B|-|R|n/a|-|-|info|-
staff.invitation.revoke           B|-|R|n/a|-|-|warn|-
staff.profile.create              B|-|R|n/a|-|-|warn|-
staff.profile.update              B|-|R|n/a|-|-|warn|-
staff.role.assign                 B|-|R|n/a|-|-|high|-                                    cannot self-escalate
staff.branch.assign               B|-|R|n/a|-|-|high|-
staff.suspend                     B|-|R|n/a|-|-|high|-                                    revokes sessions/links
staff.deactivate                  B|-|R|n/a|-|-|crit|-
staff.history.view                B|-|A|n/a|-|-|info|-
personnel.eligibility.manage      B|-|R|n/a|-|-|warn|-
personnel.availability.manage     B|-|R|n/a|-|-|info|-
compensation.plan.view            B|-|A|n/a|-|-|info|-
compensation.plan.create          B|-|R|n/a|-|-|warn|-
compensation.plan.update_draft    B|-|R|n/a|-|-|info|-
compensation.plan.submit          B|-|R|n/a|-|-|warn|compensation.plan.approve
compensation.plan.approve         B|-|R|n/a|-|SU Y|high|compensation.plan.submit         backdated change → crit audit
compensation.plan.reject          B|-|R|n/a|-|-|warn|-
compensation.plan.cancel          B|-|R|n/a|-|-|warn|-
compensation.history.view         B|-|A|n/a|-|-|info|-
payout_run.create                 B|-|R|n/a(payout)|-|-|warn|-                            HR drafts
payout_run.update_draft           B|-|R|n/a|-|-|info|-
payout_run.submit                 B|-|R|n/a|-|-|warn|payout_run.verify                    frozen on submit
payout_run.cancel_draft           B|-|R|n/a|-|-|info|-

# Front Office Operations (default_roles: front_office)
client.view                       B|-|A|n/a|-|-|info|-
client.create                     B|-|R|n/a|-|-|info|-
client.update                     B|-|R|n/a|-|-|info|-
appointment.view                  B|-|A|n/a|-|-|info|-
appointment.create                B|-|R|n/a|-|-|info|-
appointment.reschedule            B|-|R|n/a|-|-|info|-
appointment.cancel                B|-|R|n/a|-|-|info|-
appointment.check_in              B|-|R|n/a|-|-|info|-
appointment.assign                B|-|R|n/a|-|-|info|-                                    eligibility/availability revalidated
appointment.transfer              B|-|R|n/a|-|-|info|-                                    Front Office only (not Branch Manager)
queue.view                        B|-|A|n/a|-|-|info|-
queue.create                      B|-|R|n/a|-|-|info|-
queue.assign                      B|-|R|n/a|-|-|info|-
queue.transfer                    B|-|R|n/a|-|-|info|-
queue.reorder                     B|-|R|n/a|-|-|info|-
service_session.view              B|-|A|n/a|-|-|info|-
service_session.start             B|-|R|n/a|-|-|info|-
service_session.complete          B|-|R|n/a|-|-|info|-                                    may create non-payable commission preview
service_session.cancel            B|-|R|n/a|-|-|info|-
preferred_personnel.select        B|-|R|n/a|-|-|info|-
invoice.view                      B|-|A|n/a|-|-|info|-                                    (also Finance)
invoice.create                    B|-|R|enforced|-|-|warn|-                              action FinalizeInvoice (number+snapshots, idempotent)
customer_payment.record           B|-|R|enforced|-|-|warn|customer_payment.validate       maker; group-based; idempotent
receipt.view                      B|-|A|n/a|-|-|info|-
merchant.subscription.pay_simple  B|-|R(recovery-allowlisted)|n/a|Y|-|high|-
front_office.search               B|-|A|n/a|-|-|info|-

# Finance (default_roles: finance; MFA mandatory)
invoice.void.request_or_execute_as_policy M/B|-|R|enforced|Y|SU Y|high|-
invoice.adjustment.manage         M/B|-|R|enforced|Y|-|high|-
customer_payment.view             B|-|A|n/a|Y|-|info|-
customer_payment.validate         B|-|R|enforced|Y|-|high|customer_payment.record         checker; validates whole recording group
customer_payment.reject           B|-|R|enforced|Y|-|warn|-
customer_payment.reference_correct B|-|R|enforced|Y|-|warn|-                             original reference never silently edited
customer_payment.duplicate_override B|-|R|enforced|Y|SU Y|high|-                         payment_reference_checks override
customer_payment.record_exception B|-|R|enforced|Y|-|high|customer_payment.validate      maker exception; needs separate checker
receipt.reissue                   B|-|R|n/a|Y|-|warn|-                                    new receipts row referencing original
refund.create                     B|-|R|enforced|Y|-|warn|refund.approve
refund.approve                    B|-|R|enforced|Y|SU Y|high|refund.create
refund.finalize                   B|-|R|enforced|Y|SU Y|crit|-                           component-allocated; commission reversal
finance_dispute.manage            B|-|R|n/a|Y|-|warn|-
cash_up.view                      B|-|A|n/a|Y|-|info|-
cash_up.approve                   B|-|R|enforced|Y|-|high|cash_up.submit                   checker
cash_up.reject                    B|-|R|enforced|Y|-|warn|-
cash_up.request_correction        B|-|R|enforced|Y|-|info|-
period_lock.create                M/B|-|R|enforced|Y|SU Y|high|-                          Finance owns
period_lock.reopen                M/B|-|R|enforced|Y|SU Y|crit|merchant.period_reopen.approve_exception
finance_export.create             M/B|-|R|n/a|Y|SU Y|high|-                              async/scoped/masked/signed
finance_export.download           M/B|-|A|n/a|Y|-|high|-                                 download-counted
subscription.payment_attempts.view M|-|A|n/a|Y|-|info|-
compensation.liability.view       M/B|-|A|n/a|Y|-|info|-
compensation.adjustment.create    M/B|-|R|n/a|Y|SU Y|high|-                              adjustment entry only
payout_run.verify                 M/B|-|R|n/a(payout)|Y|SU Y|high|payout_run.submit
payout_run.approve_standard       M/B|-|R|n/a|Y|SU Y|high|-                              ordinary-value approval (Finance)
payout_run.reject                 M/B|-|R|n/a|Y|-|warn|-
payout_run.mark_paid              M/B|-|R|n/a|Y|SU Y|crit|-                              external ref+date; idempotent; row-lock
earnings_query.respond            M/B|-|R|n/a|Y|-|info|-                                 resolution via adjustment only
finance.audit.view                B|-|A|n/a|Y|-|info|-

# Personnel Own-Scope (default_roles: personnel; non_overridable: never contact export)
personnel.my_queue.view           O|-|A|n/a|-|-|info|-                                    staff_profile_id derived from membership
personnel.my_appointments.view    O|-|A|n/a|-|-|info|-
personnel.my_sessions.view        O|-|A|n/a|-|-|info|-
personnel.my_served_clients.view  O|-|A|n/a|-|-|info|-                                    masked contact; no export
personnel.my_compensation.view    O|-|A|n/a|-|-|info|-
personnel.my_earnings.view        O|-|A|n/a|-|-|info|-
personnel.my_statements.download  O|-|A|n/a|-|-|info|-                                    own statements only
personnel.my_payouts.view         O|-|A|n/a|-|-|info|-
personnel.my_earnings_query.create O|-|R|n/a|-|-|info|-
personnel.my_sms.send             O|sms|R|n/a|-|-|warn|-                                 served-clients only; no contact export (ADR-010)

# Audit (default_roles: audit; non_overridable: never mutating source records)
audit.branch_events.view          B|-|A|n/a|-|-|info|-                                    field-masked
audit.compensation.view           B|-|A|n/a|-|-|info|-
audit.finance.view                B|-|A|n/a|-|-|info|-
audit.export                      B|-|A|n/a|-|SU Y|high|-                                 signed/expiring; permission-masked
audit.flagged_event.create        B|-|A|n/a|-|-|info|-                                    review metadata only
audit.flagged_event.update_status B|-|A|n/a|-|-|info|-                                    metadata only; source immutable
audit.flagged_event.resolve_metadata B|-|A|n/a|-|-|info|-
```

### 19.4 Non-Overridable Rules (hard, enforced in code + tests)
- Audit role can never gain a mutating operational/financial permission.
- Personnel can never gain contact export.
- Super Administrator can never gain merchant-creation or merchant-operational permissions through merchant membership.
- Branch Manager cannot receive invoice creation or queue/appointment transfer through branch route membership without an explicitly approved scope amendment.
- Maker and checker permissions are not assigned to the same user where the workflow requires separation.

Integration additions (enforced in code + tests):
- No role, including super_admin, may create a subscription payment without a Wallet-confirmed event (no manual payment-recording path exists; route-absence tested).
- No role may edit `re_outbound_events` payloads or `re_qualification_decisions` rows (append-only triggers + policy).
- Merchant roles have no access to any `platform.integrations.*` or `platform.referral.*` permission.

### 19.5 Tests
- **Matrix completeness:** `docs/auth/permission-matrix.yaml` schema-validation test fails if any key is missing or any required attribute (§19.3 schema) is unset; a completeness test asserts every key in the catalogue (§19.2) has a populated YAML row and a populated §19.3 matrix row.
- Matrix parity test: YAML ↔ PHP registry ↔ DB projection ↔ TypeScript metadata (zero mismatches).
- One positive and one denial test per permission key, named `PermissionMatrix/{key}_allows` and `PermissionMatrix/{key}_denies` (a generator asserts both exist for every key).
- Cross-tenant and cross-branch denial for every branch/merchant domain.
- Override tests: grant, revoke, deny-beats-grant, non-overridable denial.
- Named role-boundary tests (Section 3.1): Merchant Admin not operational superuser; Branch Manager excluded keys; Front Office record≠validate; Finance owns validation/period locks; HR cannot mark/approve payouts; Audit read-only.
- Same-user maker/checker conflict denial for every `MC` pair in §19.3.
- Personnel contact-export guessed routes → 404 + audit.

## 20. Plan-Entitlement Enforcement
- **Source:** `plan_entitlements` (per plan; key + optional limit + enabled). Merchant's effective entitlements derive from the active `merchant_subscriptions.plan_id`.
- **Enforcement:** an entitlement gate runs after permission resolution and before period-lock (Section 9.4 step 10). Permissions that depend on an entitlement carry `entitlement_key`; the gate returns 403 with an upgrade-relevant code when the entitlement is absent or a limit is exceeded.
- **Examples:** number of branches (`merchant.branch.create` checks the branch-count limit); bulk SMS (`personnel.my_sms.send` requires the SMS entitlement); advanced reports.
- **Tests:** entitlement-present allows; entitlement-absent denies; limit boundary (at/over) denies; downgrade revokes access to over-limit features without data loss.

## 21. Merchant Operational-Status Enforcement
- **Authority field:** operational/governance lifecycle is **`merchants.status`** (`pending_setup`, `active`, `suspended`, `deactivated`), distinct from `merchants.billing_status` (Section 22).
- **States:** `pending_setup → active`; `active → suspended | deactivated`; `suspended → active | deactivated` (Section 25).
- **Gates:** `EnsureMerchantActive` blocks operational routes when `merchants.status` is not `active`; `pending_setup` permits only first-time-setup routes (`pending_setup_only`). Suspension reasons (fraud/security/legal/compliance/manual) and actor are stored in `merchants.status_reason`.
- **Critical rule:** `merchants.status` is **independent** of `merchants.billing_status`. A billing payment changes only `merchants.billing_status` and never clears a fraud/security/legal/compliance/manual/deactivation suspension on `merchants.status`. Recovery from those requires platform action, not payment.
- **Tests:** pending_setup user limited to setup; suspended merchant denied operations; deactivation preserves history; payment does not reactivate a non-billing `merchants.status` suspension.

## 22. Merchant Billing-Status Enforcement
- **Authority field:** the request-authorization billing-access state is **`merchants.billing_status`** (values `trialing`, `read_only_grace`, `active`, `overdue`, `suspended_billing`). It is the sole field the billing-status gate reads; `merchant_subscriptions.status` (the subscription record lifecycle) is **never** consulted directly for access. A transactional **billing-status projection service** synchronizes `merchants.billing_status` from the active subscription whenever the subscription transitions (issue, pay, escalate, suspend, recover, cancel, expire), writing both rows in one transaction with a row lock and emitting a `merchant.billing_status_changed` audit event. `merchants.billing_status` is indexed for gate lookups.
- **States:** `trialing → active`; `trialing → read_only_grace`; `read_only_grace → active | suspended_billing`; `active → overdue`; `active/overdue → suspended_billing`; `suspended_billing → active` (Section 25). Trial starts at Merchant Admin creation; days are snapshotted.
- **Read-only grace:** during `read_only_grace` and `suspended_billing`, mutation routes are blocked by the **billing-status mutation gate** (Section 9.4 step 9) reading `merchants.billing_status`, while read access continues; **new** exports/reports/PDFs cannot be generated, though existing authorized files remain downloadable.
- **Recovery:** only a fully validated subscription payment moves `merchants.billing_status` `suspended_billing → active`, and only when the suspension reason is billing-only (the recovery allowlist middleware enforces which routes a suspended-billing merchant may reach). Recovery never alters `merchants.status` (operational), preventing a payment from reactivating a non-billing suspension.
- **Escalation:** shared overdue escalation events drive `active → overdue → suspended_billing` per configured grace, applied to `merchants.billing_status` via the projection service.
- **Tests:** projection synchronizes merchants.billing_status from subscription transitions transactionally; gate reads merchants.billing_status (subscription status alone never grants access); read-only blocks mutations but allows reads; new-export blocked in read-only while existing download allowed; only validated payment reactivates; recovery allowlist limits reachable routes; billing payment does not change merchants.status.

---

## 23. API Standards and Endpoint Inventory
- **Base:** `/api/v1`; JSON only; resources expose ULIDs, never sequential IDs; error envelope per Section 10.3.
- **Pagination:** unbounded collections use cursor/length-aware pagination; default page size 25, max 100 (or a lower domain cap); single-resource endpoints are not paginated; lookups document a bounded max; sort fields are allowlisted; filters are validated and indexed; exports are async (never "page size unlimited").
- **Correlation:** every request carries/echoes `X-Correlation-ID`.
- **Contract:** OpenAPI is generated and is the **complete authoritative endpoint inventory**; the TypeScript client types are generated from it; `RouteSecurityContractTest` (Section 24) and the OpenAPI/TS parity test prevent drift. The endpoint groups are: auth; me/bootstrap; merchant-registration; merchant/profile/branches/users; staff/HR/invitations/compensation; catalogue/services/eligibility; clients/consents; scheduling (appointments/walk-ins/queues/sessions); invoices; payments/validation/receipts/refunds/disputes; cash-up/period-locks; finance-exports; platform (settings/plans/prices/entitlements/promotions/free-periods/preferred-fee/merchant-governance/billing-reconciliation/integrations-health/referral-qualification/audit); subscriptions/billing-invoices/billing-payment (re-pointed at Wallet internally with identical merchant-facing shapes); wallet-webhooks (inbound partner); refer-earn-inbound (inbound partner); compensation/payouts/earnings; personnel own-scope; sms; files; reports; audit; health.

### 23.1 Naming
Routes use `domain.resource.action` names matching transition actions and permission keys (e.g., `payments.validate`, `cash_up.approve`, `billing.wallet.stk_initiate`, `integrations.wallet.webhook`). Forbidden routes (Super-Admin merchant creation, personnel contact export, any `*/mpesa/*` route) must not exist; tests assert their absence.

## 24. Route-Classification and Middleware Matrix
Every non-GET route declares exactly one classification in route metadata/registry. `RouteSecurityContractTest` loads the route collection and fails when: a non-GET route has no classification; a route misses middleware required by its class; a public route has tenant middleware; a financial route lacks idempotency; a webhook route uses Sanctum/browser CSRF instead of the partner contract; a route under `/api/v1/integrations/*` lacks one of the two partner classes or carries Sanctum/tenant middleware; any route matches `*/mpesa/*`; or a platform route is reachable by merchant middleware.

### 24.1 Route Classes and Required Controls (Correction 10.2)
```text
public_mutation (request/verify magic link, self-register, accept invitation):
  validation; purpose-specific rate limit; enumeration-resistant response; CSRF for same-origin session OR signed one-time token; abuse detection + correlation; security audit without account-existence leak; NO tenant middleware before tenant exists.
authenticated_global_mutation (theme/profile preference, MFA enrollment):
  Sanctum auth; active user/session; CSRF; validation; self-service policy/permission; audit for security-sensitive changes.
tenant_mutation (merchant profile, staff lifecycle):
  auth; tenant resolution; active membership/role; permission + policy; billing-status gate; validation; audit.
branch_mutation (service update, client create, queue transfer):
  all tenant controls; branch resolution + assignment; branch status; own-scope where relevant.
financial_mutation (payment record/validate, refund, payout, subscription payment initiation, invoice finalization, reconciliation resolution, billing credit):
  relevant tenant/branch controls; fine-grained financial permission; maker/checker rule; period-lock check; step-up MFA for designated actions; idempotency; DB transaction + row lock; immutable ledger/audit event.
platform_mutation (billing settings, plan prices, promotion approval, merchant suspension):
  platform staff auth; platform role/permission; mandatory MFA; step-up for sensitive actions; reason field; audit; NO merchant tenant context or membership insertion.
partner_webhook_mutation (Wallet webhooks; replaces the v3 provider_webhook_mutation — SUP-03):
  no Sanctum; algorithm-aware signature verification (algorithm identifier + key-ID + contract version per
  ADR-015; timestamp tolerance ±300s) BEFORE parse-for-routing AND before any canonical storage; strict POST +
  content-type + 64KB body limit; exact schema per event version; first-seen unique event-id insertion into the
  encrypted verified inbox only AFTER verification (Correction 14.7 — no event-ID squatting); fast ack; async
  processing; endpoint/anomaly rate limiting that never blocks legitimate retries; full redaction; security
  audit (no inbox row) on verification failure.
partner_signed_query (R&E reconciliation):
  no Sanctum; X-Citrus-* canonical-string HMAC; nonce uniqueness (re_inbound_requests); bounded read-only
  query classes; response contains only §58B.2-grade facts; rate limited per key; audited.
liveness/readiness:
  no user auth; network/platform access; no user input; infrastructure rate control.
```

### 24.2 Class-Specific Acceptance Matrix (Correction 11.2)
| Route class | Auth | Authorization | Validation | Pagination | Rate limit | Idempotency |
|---|---|---|---|---|---|---|
| Public mutation | No session | Token/flow rules | Required | No | Required | Flow-specific |
| Public read | No session | Public-data policy | Query validation | When collection unbounded | Required | No |
| Authenticated self-service | Required | Self policy | On mutations | Collection only | Required | When effect-sensitive |
| Tenant/branch read | Required | Tenant/branch/policy | Query validation | Unbounded collections | Required | No |
| Tenant/branch mutation | Required | Permission + policy | Required | No | Required | When duplicate effect matters |
| Financial mutation | Required | Financial policy | Required | No | Strict | Required |
| Platform mutation | Required | Platform permission | Required | No | Strict | Where effect-sensitive |
| Partner webhook | Signature contract (algorithm-aware, ADR-015) | Key-ID/correlation | Strict schema | No | Endpoint/anomaly | Unique first-seen event-ID replay protection (post-verification) |
| Partner signed query | HMAC contract | Key + bounded query class | Strict schema | Cursor where lists | Per key | Nonce uniqueness |
| Liveness/readiness | No user auth | Network/platform | No user input | No | Infra control | No |

### 24.3 Response/Error Rules
Enumeration-resistant public flows return a uniform accepted response; foreign-tenant IDs → 404; same-tenant out-of-branch → documented 403; validation → 422 with field maps; idempotency conflict → 409; financial-period lock → 423 (`financial_period_locked`); rate limit → 429 with retry info; internal exceptions never expose stack traces/SQL/provider secrets/raw callbacks.

### 24.4 Idempotency Middleware Algorithm (financial routes)
1. Require `Idempotency-Key` (length 16–255). 2. Compute canonical request hash (method, route, normalized path params, content type, canonicalized body; exclude volatile headers). 3. Begin transaction; attempt to insert a `processing` row with lock expiry. 4. On existing key: same hash + `completed` → replay stored status/approved headers/encrypted body; different hash → 409 `idempotency_key_reused_with_different_request`; same hash + active `processing` lock → 409 `request_in_progress` + retry-after; same hash + expired lock → `SELECT ... FOR UPDATE`, replace lock, retry; `failed` → retry only if explicitly retryable else replay stable failure. 5. Execute the domain action in the same transaction where practical; for provider calls, persist the attempt first and use a state machine + outbox/job. 6. On success, encrypt and store replay-safe response, mark completed, commit. 7. On domain validation failure, store a stable replayable 4xx where the effect must remain deterministic. 8. On server failure, mark failed with a redacted code; never store stack traces/secrets. `FinancialRouteIdempotencyCoverageTest` fails when any `financial_mutation` route lacks the middleware. Retention: standard ≥72h; support-retriable financial ≥30d; provider dedupe retained with the financial record; prune job never deletes an active lock.

### 24.5 Log Redaction List (binding)
Never log: passwords, Magic-Link tokens, MFA secrets, recovery codes, session IDs, Wallet API credentials/tokens, Wallet webhook secrets or signatures, raw Wallet webhook bodies, R&E signing keys, `X-Citrus-Signature` values, R&E nonces paired with signatures, raw referral landing metadata, raw callback payloads, consumer phone numbers (`phone_msisdn` values), payment references (`provider_reference` unmasked), decrypted payloads of `wallet_webhook_inbox`/`referral_snapshots.raw_code_encrypted`, signed-URL tokens, email headers.

---

## 25. State-Machine Catalogue

### 25.1 Standard and Per-Transition Contract
Every stateful aggregate has a PHP enum plus a transition **Action** class. **Status is never assigned directly anywhere** — not in controllers, jobs, listeners, console commands, observers, ad hoc scripts, factories, or migrations; every transition is executed only through its named domain action / state-machine service (a `NoDirectStatusAssignmentRule` static-analysis check plus a source-scan test enforce this).

The arrow catalogue in §25.2–§25.5 is the authoritative **transition inventory**. For **every legal transition listed there**, a complete **transition record** is materialized in the mandatory, version-controlled state-machine specification at `docs/architecture/state-machines/{aggregate}.md` (one record per transition), and the owning phase must author and have that record reviewed **before** implementing the transition. Each transition record contains, completely:

```text
aggregate | current_state | next_state | actor | required_permission |
tenant_conditions | branch_conditions | own_scope_conditions | entitlement_conditions |
billing_status_conditions | operational_status_conditions | period_lock_conditions |
input_validation | preconditions | transaction_boundary | rows_locked | advisory_lock |
idempotency_requirement | writes | generated_records | ledger_effects | compensation_effects |
notifications | queue_jobs | audit_event | failure_codes | retry_behavior | reversal_or_correction | tests
```

A transition is **not** considered specified while it is represented only by an arrow; the arrow plus its reviewed transition record in `docs/architecture/state-machines/{aggregate}.md` together constitute the specification (the same named-mandatory-spec mechanism used for the data dictionary §13.2 and screen specs §27.1). Financial transitions lock the aggregate row `FOR UPDATE` and assert current state in the `WHERE`/locked model. Every transition has positive, invalid-transition, authorization, concurrency, and audit tests; high-value aggregates also record transition history.

**Worked transition records (binding format instances — the spec files follow this exact shape for every transition):**

```text
# Payment Recording Group: pending_validation -> validated  (§25.3)
aggregate: payment_recording_group | current_state: pending_validation | next_state: validated
actor: Finance (checker) | required_permission: customer_payment.validate
tenant_conditions: group.merchant_id == ctx.merchant | branch_conditions: group.branch_id in active assignments
own_scope_conditions: n/a | entitlement_conditions: none
billing_status_conditions: merchants.billing_status allows financial mutation | operational_status_conditions: merchants.status = active
period_lock_conditions: invoice period not locked (else 423)
input_validation: each component reference/amount/method valid; sum(components) == group.total; <= invoice balance
preconditions: maker != checker (customer_payment.record incompatibility); group currency single
transaction_boundary: single DB transaction | rows_locked: invoice + all component payment_records FOR UPDATE | advisory_lock: none
idempotency_requirement: Idempotency-Key keyed on group (one validation effect)
writes: group.status=validated; components.status=validated; invoice.validated_paid += sum; invoice.status recomputed
generated_records: one payment_validation_event(group); one receipt(receipt_number_sequences) covering all components; earned commission_ledger entries per component
ledger_effects: validated_paid increase; commission earned | compensation_effects: per-component earned commission
notifications: Front Office + relevant parties (payment validated) | queue_jobs: receipt PDF generation (10F); commission projection
audit_event: customer_payment.validated (high) | failure_codes: 422 invalid_group, 409 idempotency, 423 period_locked, 403 maker_is_checker
retry_behavior: safe replay returns stored result | reversal_or_correction: reversal/adjustment workflow only (never destructive)
tests: happy-path; sum!=total reject; over-balance reject; maker==checker deny; locked-period 423; duplicate idempotent; concurrent double-validate one effect; cross-branch deny

# Personnel Payout Run: approved -> paid  (§25.5)
aggregate: personnel_payout_run | current_state: approved | next_state: paid
actor: Finance | required_permission: payout_run.mark_paid
tenant_conditions: run.merchant_id == ctx.merchant | branch_conditions: run.branch_id in assignments
own_scope_conditions: n/a | entitlement_conditions: none
billing_status_conditions: financial mutation allowed | operational_status_conditions: merchants.status = active
period_lock_conditions: n/a (payout runs not period-locked)
input_validation: external_payment_reference present; paid_date present
preconditions: run.status = approved (high-value runs require prior Merchant-Admin approval); fresh step-up MFA
transaction_boundary: single transaction | rows_locked: payout_run + payout_items FOR UPDATE | advisory_lock: none
idempotency_requirement: Idempotency-Key REQUIRED (one mark-paid effect)
writes: run.status=paid; items.status=paid; linked salary_ledger/commission_ledger entries -> paid; store external_payment_reference_encrypted, paid_at
generated_records: none new (status transitions on existing ledgers) | ledger_effects: ledger entries marked paid
compensation_effects: personnel earnings reflect paid | notifications: affected personnel (statement available)
queue_jobs: statement PDF (10F) | audit_event: payout_run.marked_paid (critical) | failure_codes: 409 idempotency, 403 stale_step_up, 422 missing_reference
retry_behavior: idempotent replay | reversal_or_correction: adjustment run only (never status rewind)
tests: mark-paid happy-path; stale step-up deny; missing reference 422; idempotent replay; ledger status propagation; high-value requires prior MA approval
```

### 25.2 Operational and Org Machines
```text
Merchant Operational Status (merchants.status) — actor Merchant Admin/Platform
  pending_setup → active        (Merchant Admin completes first-time setup)
  active → suspended            (platform: fraud/security/legal/compliance/manual; reason+actor stored)
  active → deactivated; suspended → active | deactivated
  Rule: billing payment never changes operational status; non-billing suspensions cleared only by platform action.

Merchant Billing Status (merchants.billing_status) — actor Billing engine/Finance/Merchant Admin
  trialing → active | read_only_grace
  read_only_grace → active | suspended_billing
  active → overdue ; active/overdue → suspended_billing
  suspended_billing → active (ONLY via fully validated payment + billing-only reason)
  Side effects: billing-status gate toggles mutation/export capability; escalation events; recovery allowlist.
  merchants.billing_status is the access authority, projected transactionally from merchant_subscriptions.status (§22).

Merchant Setup — pending_setup setup-incomplete → setup-complete (flips operational to active); first-time-setup routes only while pending.

Branch Lifecycle — active → suspended | archived ; suspended → active | archived (admin; BranchClosureGuard blockers must pass to archive).

Branch Day — actor Branch Manager (reopen may require Finance/Manager policy)
  not_opened → open → paused → open → closed
  closed → reopened (reason + permission) → open → closed
  Close requires all mandatory day-close checks (unclosed-day, cash-up discrepancy, plus queue/session/invoice/payment/receipt/appointment guards flipped on by Phases 16–18).

Staff Invitation — pending → accepted | revoked | expired ; expired → pending only via new token (no reuse). Token hashed, single-use, invalidated on revoke/deactivation/replacement.

Staff Lifecycle — invited → active → suspended → active ; active/suspended → deactivated.
  Suspend/deactivate revokes sessions + unused Magic Links + pending invitations; sole-active-admin orphan guard; branch-assignment-required-to-activate.

Appointment — actor Front Office
  scheduled → confirmed | cancelled ; confirmed → checked_in | rescheduled | cancelled | no_show
  checked_in → queued | in_service | cancelled_with_reason ; rescheduled → confirmed/scheduled
  Eligibility + availability + branch-open revalidated on assign/transfer.

Queue Entry — actor Front Office (transfer); Personnel cannot access others'
  waiting → assigned → called → in_service → completed
  waiting/assigned/called → transferred | cancelled | no_show ; transferred → assigned/waiting at destination

Service Session — pending → in_progress → completed ; pending/in_progress → cancelled.
  Personnel must be eligible for every service item + assigned to branch; duplicate-active-session protection.
```

### 25.3 Financial Machines
```text
Merchant-Client Invoice — actor Front Office (create), Finance (void/adjust)
  draft → issued (number allocated; prices/preferred-fee/percentage-fee config snapshotted)
  issued → partially_paid | paid | void_pending | adjusted ; partially_paid → paid | void_pending | adjusted
  void_pending → voided | (back to issued/partially_paid on rejection) ; paid → refund_pending | adjustment_required
  Voiding a paid invoice creates financial adjustments; never deletes ledger rows. Mutations blocked by period lock (423).

Payment Recording Group — actor Front Office maker (record), Finance checker (validate); the durable grouping for single, split, or multi-method recording
  draft → recorded → pending_validation → validated | rejected | correction_required
  correction_required → pending_validation ; rejected/validated terminal for the group (corrections via new group or reversal)
  Group invariants: total_amount = sum(component payment_records); single currency; sum(components) ≤ invoice balance (validated). Validation acts on the WHOLE group atomically (one payment_validation_event for the group, one receipt covering all validated components). Maker cannot self-validate. Idempotency keyed on the group.

Payment Record + Validation — Front Office maker, Finance checker; period-lock + step-up where designated; every record belongs to a Payment Recording Group
  recorded(pending_validation) → validated | rejected | correction_required
  correction_required → pending_validation ; validated → reversed/adjusted only via controlled finance workflow
  At group 'validated': lock invoice + all component payment rows; create immutable payment_validation_event for the group; increase validated_paid; update invoice status; auto-issue one receipt covering all validated components; create earned commission entries allocated by component; emit audit/notifications; commit atomically (or outbox-guaranteed). Maker cannot self-validate.

Receipt — issued automatically once per validated Payment Recording Group (containing all validated component methods/amounts) → reissued (new tracking row referencing original). Not generated before validation.

Refund — actor Finance; period-lock + approval + step-up on finalize
  requested → approved → finalized ; requested/approved → rejected
  Finalize reduces recognized paid balance via adjustment/reversal only; creates proportional commission reversal; preserves original payment/receipt/commission rows.

Finance Dispute — open → under_review → resolved | rejected.

Cash-Up — Branch Manager submit, Finance approve (maker≠checker)
  draft → submitted → approved | rejected | correction_requested ; correction_requested → submitted ; approved → locked (period/day controls).

Financial Period Lock — open → locked → reopened (reopen requires Finance; exceptional reopen approval by Merchant Admin where policy requires). Mutations in a locked period → 423.
```

### 25.4 Billing, Wallet-Payment, Promotions Machines
```text
Subscription (merchant_subscriptions.status) — trialing → active (record lifecycle; merchants.billing_status is projected from it, §22); → read_only_grace/overdue/suspended_billing track the access projection; → cancelled | expired are terminal record states. Plan changes scheduled (no proration) apply at next cycle.

Subscription Invoice — draft → issued → pending_payment → partially_paid → paid ; issued/partially_paid → overdue ;
  pending_payment → payment_failed or back to issued after attempt expiry ; any payable → reconciliation_required when confirmed funds cannot be safely applied ; draft/issued → void (terminal, pre-payment supersession). Issued invoices immutable; transitions produce event rows/timestamps.
  pending_payment/partially_paid/paid transitions are driven EXCLUSIVELY by verified Wallet events or exception-resolution linkage (SUP-04).
  Registration status (unregistered→pending→registered|failed) is an orthogonal technical field, not part of the financial machine, and never blocks issuance (ADR-014).
  Terminology: subscription invoices use **`void`** only (never `cancelled`); `cancelled` is reserved for non-invoice records (subscription, payout run, promotion, free-period offer). These are distinct documented transitions.

Wallet Payment Attempt (subscription_payment_attempts.status; Correction 14.6 as amended by ADR-012) —
  initiated ─► submitting_to_wallet ─► submitted_to_wallet ─► prompt_sent ─► confirmed ─► applied_to_invoice
      │               │                      │                    │  │             (terminal-success)
      │               │ pre-call failure     │ wallet unreachable │  ├─► customer_cancelled (terminal)
      ├─► failed      ├─► failed (terminal)  ├─► provider_        │  ├─► timeout (NOT proof funds didn't
      │  (terminal,   │                      │   unavailable      │  │    move → QueryStaleWalletAttemptsJob
      │  wallet 4xx/  │ timeout/ambiguous    │   (definitive      │  │    may still move timeout →
      │  validation)  │ transport failure    │   pre-acceptance   │  │    confirmed later)
      │               ├─► submission_unknown │   failure only;    │  └─► failed (terminal)
      │               │   (ambiguity is NOT  │   retryable; lock  │
      │               │   proof of non-      │   released)        │
      │               │   acceptance)        │                    │
      └─► duplicate (idempotent replay resolution)
  submission_unknown ─► submitted_to_wallet | prompt_sent | confirmed | failed | timeout
      (resolved ONLY by authoritative Wallet status — GET /payments/{p} or a verified webhook event;
       the attempt retains its ORIGINAL servana_idempotency_key; no duplicate attempt under a new key;
       retries/queries reuse the original identity; a bounded cooldown/invoice payment lock holds
       until resolution or lock expiry)
  confirmed ─► reconciliation_required (apply-time invariant breach → exception queue)
  applied_to_invoice ─► reversed | refunded_externally (Wallet reversal/refund events; §13.11 reversal rows)
  Per-transition contract (§25.1): actor (system/webhook/job), permission n/a for webhook-driven,
  lock (invoice row lock on confirmed→applied), idempotency key, audit event, notification.
  Ordering rule: terminal-success states never regress from stale events; a late 'prompt_sent' after
  'applied_to_invoice' is recorded in wallet_status_snapshot history only.
  Apply under invoice row lock: <balance → partially_paid; =balance → paid; >balance → pay invoice + create billing credit. Reactivate ONLY billing-only suspension.

Billing Reconciliation Exception (billing_reconciliation_exceptions) — open → resolved | dismissed. Super Admin resolves by linking a Wallet-confirmed payment to the correct invoice (reason + step-up + before/after audit + maker/checker when severity=critical).

Promotion — draft → scheduled | active → paused → active → expired ; any pre-active → cancelled. Snapshotted at application; does not mutate issued invoices.

Free-Period Offer — draft → scheduled → active → paused → active → expired ; any pre-active → cancelled. Applied days snapshotted onto subscription; later edits never rewrite an existing trial.
```

### 25.5 Compensation Machines
```text
Compensation Plan — draft → pending_approval → scheduled | active | rejected ; scheduled → active ; active → superseded | expired ; any pre-active → cancelled.
  Effective-date overlap blocked (exclusion constraint); backdating requires approval + critical-severity audit; active monetary terms immutable (supersede with new version).

Commission Ledger Item — pending_preview (optional at session completion) → earned (at validated payment) → included_in_payout → paid.
  earned → reversed (negative row referencing original) on void/refund/payment reversal; adjustments are new rows; never edit/delete originals.

Salary Ledger Item — accrual(pending) → included_in_payout → paid ; reversal/adjustment as new rows. Scheduler idempotent per (plan, staff, pay-period segment, entry_type).

Payout Run (Correction 17.3) — draft → submitted → finance_verified → approved (ordinary) ;
  finance_verified → pending_merchant_admin_approval (high-value) → approved | rejected ; approved → paid ;
  pre-paid → rejected/cancelled per actor ; paid → adjusted only via new adjustment workflow (never status rewind).
  HR creates/edits/submits; Finance verifies/approves-standard/marks-paid; Merchant Admin approves high-value; high-value threshold snapshotted (not hardcoded). Mark-paid requires external reference + paid date + fresh step-up + idempotency + row lock + ledger-status update + personnel notification.

Payout Item — mirrors run status; snapshots salary/commission/adjustment/gross + source ledger refs at creation; frozen on submit.

Earnings Query — open → assigned → resolved | rejected. Resolution never mutates ledgers silently; monetary correction creates an adjustment entry.

Audit Flagged Event — open → under_review → resolved | dismissed ; resolved/dismissed → reopened (explicit permission + audit). Only review metadata mutable; source audit log immutable.
```

---

### 25.6 Refer & Earn Machines
```text
Referral Snapshot (referral_snapshots.snapshot_status) —
  captured ─► validating ─► validated ─► confirmed (terminal)
     │            │             └─► expired_unconfirmed (R&E confirm window lapsed; terminal; audited)
     │            └─► rejected (invalid/expired/ineligible code; terminal)
     └─► invalid_format (terminal; never sent to R&E)
  No regression transitions; retries stay within the same state (trigger-enforced).

Qualification (re_qualification_periods.evaluation_status) — pending → evaluated → corrected, where 'corrected'
  simply records that a higher decision_version exists; decisions themselves (re_qualification_decisions) are
  immutable append-only rows — not a mutable machine (§58B.3).

Outbox Event (re_outbound_events.delivery_status) — pending → delivering → delivered ;
  delivering → pending (retry with backoff, same event ID + hash) → dead_letter (max age / 409 mismatch / 422 schema;
  alert + replay command) ; superseded reserved for schema-version replacement replays. Payload append-only.
```

## 26. UI Design System
- **Tokens:** brand color tokens (per `SERVANA COMBINED.txt` Brand Identity), spacing, radius, typography (self-hosted Inter/Manrope), elevation; light + dark token sets. Primary/CTA contrast meets WCAG AA (`text-brand-deep` on brand orange; ADR-009).
- **Core components:** `SvButton` (variants, loading, disabled, 44px touch target), `SvInput`/`SvSelect`/`SvTextarea` (labels, `aria-invalid`/`aria-describedby`/`aria-required`), `SvCard`, `SvModal` (focus trap, Esc, `aria-modal`), `SvToast` (`role="status"`, auto-dismiss, pause on hover), `SvStateBoundary` (loading/empty/error/success), `SvEmptyState`, `PermissionGate`. All components ship light + dark + every state and are axe-verified.
- **Money/date display:** integer-minor-unit formatter; `Africa/Nairobi` date helpers.

## 27. Complete Screen and Route Inventory

### 27.1 Per-Screen Specification Format (mandatory file per route)
Create one spec per route under `/docs/frontend/screens/{role-or-domain}/{screen-key}.md` containing: route name + URL; layout; allowed roles; required permissions; merchant/branch/own scope; required entitlement; billing-state behavior; API dependencies; fields + table columns; primary/secondary/destructive actions + confirmation behavior; loading/empty/error/success states; no-permission/no-branch states; locked/read-only/suspended-billing states; mobile/tablet/desktop transformation; keyboard + screen-reader behavior; dark-mode requirements; audit events triggered; unit/component/e2e tests. **No screen is implemented before its specification exists and passes review** (this is the named mandatory specification, not a placeholder).

### 27.2 Role Entry Surfaces (every role)
Each role has (1) a live landing page showing role-true actionable work and (2) a guided get-started page with persisted checklist completion, deep links, resumability, dismissal, and reopen behavior — populated with the scope-defined checklist content, not generic placeholders.

### 27.3 Minimum Screen Inventory (Correction 22.4)
```text
Public/Auth: marketing/landing (where applicable); merchant self-registration; magic-link request/verify result; invitation acceptance; MFA enrollment/challenge/recovery.
Merchant Administrator: landing + get-started; first-time setup; merchant profile; branch list/create/detail; staff overview/lifecycle; subscription dashboard; plan management + scheduled change; subscription invoices/detail/download/payment; billing recovery; merchant reports; compensation summary.
Branch Manager: landing/get-started; branch profile/calendar/day open-close; service catalogue; queue/appointment read views; cash-up submission; branch reports; subscription payment notice/recovery.
HR: landing/get-started; staff roster/detail/invite/edit/lifecycle; role + branch assignment; eligibility + availability; compensation list/detail/setup/history; payout draft preparation.
Finance: landing/get-started/task inbox; invoice/payment validation; duplicate-reference review; receipts/reissue; refunds/disputes; cash-up approval; period locks; exports; payout verification/approval/mark-paid; subscription payment attempts.
Front Office: landing/get-started; client create/search/detail; appointment + walk-in; queue assignment/transfer; service-session workflow; invoice create/detail; payment record; receipt status; simple subscription payment banner/recovery.
Personnel: landing/get-started; own queue/appointments/sessions; own served clients; SMS composer; My Earnings tabs; statements + queries.
Audit: landing/get-started; branch audit log; flagged-event review; compensation/finance audit; permissioned masked export.
Super Administrator: landing/get-started; billing settings; plans/prices/entitlements; promotions/free periods; preferred-personnel fee rules; merchant registration monitoring/list/detail/governance; billing reconciliation exceptions; integrations health (Wallet + R&E) with qualification-decisions view; platform audit/reports. NO merchant-create screen.
```

## 28. Responsive Strategy
- **Breakpoints:** `md: 768px`, `lg: 1025px`. Desktop: persistent side navigation + full data tables. Tablet: condensed navigation, responsive columns, deliberate labelled scroll only where unavoidable. Mobile: single-column; tables convert to cards; primary actions remain visible; financial confirmations remain readable.
- **Critical mobile flows:** queue, payment, subscription payment (STK + instructions), personnel earnings, served clients, and SMS must be fully usable on mobile.
- **Per-feature gate:** every feature phase tests desktop/tablet/mobile + a no-horizontal-scroll test; a release-wide responsive audit runs at Phase 23.

## 29. Dark-Mode Strategy
Class-based dark mode with pre-paint flash prevention; every component and screen ships dark tokens and is verified in both themes. Each feature phase includes dark-mode verification; the Phase 23 audit covers all launch screens.

## 30. Accessibility Strategy
Every feature phase includes keyboard navigation, focus management, screen-reader semantics, contrast validation, accessible error messages, loading/empty states, automated axe checks, and manual checks for critical workflows. A whole-product accessibility audit runs at Phase 23 after all launch screens exist. Targets: WCAG 2.1 AA.

## 31. Forms and Validation Strategy
- Client-side validation via `useForm<T>` is UX only; the backend Form Request is authoritative.
- Server 422 field errors merge into the form; duplicate submits are prevented; destructive actions require typed confirmation; financial confirmations show amounts in readable minor-unit-formatted currency.
- No HTML `<form>` posts that bypass the SPA contract; all mutations go through the typed API client.

---

# Feature Domains (§32–§78)

Each domain references its owning phase (Section 80), state machine (Section 25), permission keys (Section 19), schema (Section 13), and screens (Section 27). Domain-specific rules below are binding and supplement those artifacts. Authoritative product behavior is `SERVANA COMBINED.txt`.

## 32. Merchant Self-Registration and Onboarding (Phase 6 as-built; verify)
Self-registration is the **only** merchant-creation path (`POST /api/v1/merchant-registration/self-register`, public_mutation, uniform 202, no enumeration): creates user + merchant `pending_setup` + shell profile + `merchant_admin`/`active` membership + status-history row in one transaction, then emails a Magic Link. No Super-Admin/KYC route or screen exists (tested). First-time setup (`EnsureFirstTimeSetupAccess`: pending_setup + merchant_admin) sets plan/tier, profile, ≥1 branch, initial Branch+HR invited memberships, welcome emails, then flips merchant → `active`. Trial starts at this Merchant Admin creation (Section 22). Audit: registration, setup completion.

## 33. Branch Management (Phase 7 as-built; verify)
Admin-only branch create/update/archive; merchant-scoped list/show; weekly operating hours; calendar exceptions; day open/pause/close/reopen (Section 25); `BranchClosureGuard` blockers (unclosed day, cash-up discrepancy now; queue/session/invoice/payment/receipt/appointment guards flipped on by Phases 16–18; branch-fee debt by 20). Service catalogue ownership is **Branch Manager** (Section 39). Audit: branch lifecycle, day open/close/reopen.

## 34. Staff, HR, Invitations, and Lifecycle (Phase 7 as-built; verify)
HR owns staff + invitations + lifecycle + role/branch assignment + eligibility/availability + compensation setup. Invitations: SHA-256-hashed 72h token, create/resend/revoke, atomic public accept (user + active membership + staff_profile + active branch assignment + append-only history). Lifecycle service: activate/suspend/deactivate/assignBranch/revoke — suspend/deactivate revokes sessions + unused Magic Links + pending invitations; sole-active-admin orphan guard; branch-assignment-required-to-activate. Authority: Merchant Admin invites Branch Manager/HR; HR invites operational roles within its own branch. Audit: invitation + lifecycle events.

## 35. Client Records (Phase 15A)
Branch-scoped client create/search/update by Front Office (`client.*`). Contact stored encrypted, displayed masked; `phone_last_four` for display. SMS consent recorded (`client_consents`). No client self-service portal at launch. Audit: client create/update.

## 36. Appointments (Phase 16A)
Front Office owns appointments (Section 25 Appointment machine). Eligibility + availability + branch-open revalidated on assign/transfer. Transfer is Front Office only (not Branch Manager). Audit: create/reschedule/cancel/transfer/no_show.

## 37. Walk-Ins and Queues (Phase 16B)
Walk-ins convert to queue entries; queue assignment/transfer/reorder by Front Office; Personnel cannot access another personnel member's entries. Queue wait-time metric defined in Section 69. Audit: queue create/assign/transfer/cancel.

## 38. Service Sessions (Phase 16C)
Personnel must be eligible for every service item and assigned to the branch; duplicate-active-session protection (partial-unique). Session completion may create a non-payable commission **preview** only (earning happens at validated payment). Audit: session start/complete/cancel.

## 39. Services, Pricing, and Personnel Eligibility (Phase 15A)
**Branch Manager** owns the service catalogue (`service.create/update/archive`). Each service has price (minor units), duration, category, and an effective **preferred-personnel fee** (Section 41/59 treatment). Eligibility (`service_personnel_eligibility`) gates which personnel may perform a service. **Preferred-personnel-fee rules are launch-active Super-Administrator configuration** in `preferred_personnel_fee_rules` (§13.10, owned by Phase 20A; `platform.preferred_personnel_fee.manage`, MFA + step-up) supporting both `fixed_amount` and `percentage` (basis points, round-half-up; ADR-005), with platform-default or per-service scope, effective dating with no overlap, and immutable active terms (supersede to change). Branch users may view the applicable rule (`preferred_personnel_fee.view_branch_rule`) but cannot edit it. The effective fee is **snapshotted onto the invoice at finalization** (Phase 17); existing invoices are never recalculated when a rule changes. The legacy fixed `services.preferred_personnel_fee_minor` is migrated to rules via expand-and-contract and retained read-only until contract. Audit: service create/update/archive, eligibility changes, fee-rule create/supersede.

## 40. Invoices (Phase 17)
Front Office creates merchant-client invoices (`invoice.create`); Finance voids/adjusts. Numbers allocated at finalization from a gap-free per-merchant **`invoice_number_sequences`** counter (row-locked, never reused; §13.15); finalization snapshots prices, the resolved preferred-personnel fee, taxes/discounts, and percentage-platform-fee config (Section 25 Invoice machine). Balance is based on **validated** payments only. Voiding a paid invoice creates adjustments; never deletes ledger rows. Mutations in a locked period → 423. Audit: create/finalize/void/adjust.

## 41. Merchant-Client Payments (Phase 18A) — Correction 18
Methods: `cash`, `mpesa_offline`, `bank_transfer`, `card_terminal`, `voucher`, `split_payment`, `other`. Every recording opens a durable **`payment_recording_groups`** row (§13.15): a single-method payment is a group of one; a `split_payment`/multi-method payment is one group with multiple component `payment_records` (`payment_records.payment_recording_group_id`). Front Office records (maker) against an issued/partially-paid invoice in the same branch; backend verifies group currency consistency, group total = sum(components), balance, branch, merchant, billing-mutation allowance, and period openness; each component amount positive; **overpayment rejected by default** (overpayment credit applies only to merchant→Servana billing). Method-specific references: cash optional (no duplicate check); offline M-Pesa required, normalized uppercase, format-validated, merchant/branch duplicate detection; bank transfer required; card terminal terminal/auth code per setup; voucher validated where a voucher module exists else external evidence; other requires merchant-defined label + evidence. Duplicate-reference detection is recorded durably in **`payment_reference_checks`** (§13.15) with result `unique`/`duplicate_suspected`/`override_approved`; a `duplicate_suspected` result raises a critical warning and only Finance with `customer_payment.duplicate_override` may proceed (reason + step-up for high-risk); the original reference is never silently edited. Records created `pending_validation`; Finance notified; audit (maker, group, amounts, methods, masked references, balance before/after). Partial/split: invoice balance uses validated payments only; concurrent pending may not collectively exceed balance (invoice row lock + pending-total check); commission allocated proportionally to eligible items by validated allocation per component. Idempotency required and keyed on the recording group (Section 24.4). Tests: each method's reference behavior, `payment_reference_checks` duplicate+override, group total = sum(components), single-currency enforcement, partial, split/multi-method, concurrent recording, maker-cannot-self-validate, locked-period denial, cross-tenant/branch denial, billing read-only denial.

## 42. Payment Validation (Phase 18B) — Correction 18.5
Finance validates (checker) a **payment recording group** as a unit; cannot validate a group it recorded under an exception permission unless a separate checker exists or an approved small-team exception policy applies. Validation (atomic, locked over the invoice + all component rows): verify each component's reference/amount/method + group total + invoice/branch/evidence; create one immutable `payment_validation_event` for the group; set components validated; increase invoice validated-paid; update invoice status; auto-issue one receipt covering all validated components; create earned commission entries allocated by component; emit audit + notifications. A failure in receipt/commission creation rolls back the whole group validation unless an outbox design guarantees completion — the invoice never says paid while side effects are silently missing. Audit: validate/reject/correction_required (group-level). Step-up where designated.

## 43. Receipts (Phase 18B)
Issued automatically once per validated **payment recording group**, containing all validated component methods/amounts (Plan A-12); numbers come from a gap-free per-merchant **`receipt_number_sequences`** counter (row-locked; §13.15); reissue (`receipt_reissues` is modelled as a new `receipts` row referencing the original via `reissue_of_receipt_id`, §13.16) creates a new tracking row; receipts are never generated before validation. Receipt PDFs are files (Section 65). Audit: issue/reissue.

## 44. Refunds and Disputes (Phase 18B) — Correction 18.8
Servana records external refunds; it does not move merchant-client funds at launch. Finance creates a refund request against validated-payment allocation, **allocated by component** of the original payment recording group; verify period-lock policy + approval; store amount/method/external reference/reason/evidence/approval; finalization reduces recognized paid balance via adjustment/reversal entries only; create proportional commission reversal per affected component; preserve original payment/receipt/commission rows. Disputes: open → under_review → resolved/rejected. Step-up on finalize. Audit: refund request/approve/finalize/reject; dispute lifecycle.

## 45. Cash-Up and Reconciliation (Phase 18B)
Branch Manager submits cash-up (`branch.cash_up.submit`); Finance approves/rejects/requests correction (maker≠checker). Lines per method with expected/counted/variance. Approval locks with period/day controls. Daily branch day-close and cash-up PDFs (Section 69, Phase 21N). Audit: submit/approve/reject/correction.

## 46. Financial Period Locks (Phase 18B)
Finance owns period locks (`period_lock.create/reopen`); Merchant Admin only approves exceptional reopen where policy requires (`merchant.period_reopen.approve_exception`). Mutations affecting a locked period return 423. Reopen requires reason + audit + (step-up). Audit: lock/reopen.

## 47. Plan Catalogue and Entitlements (Phase 20A)
Super Admin manages plans (non-price metadata), prices (`subscription_plan_prices` — sole price source, effective-dated, no overlap; ADR-011), and entitlements (`plan_entitlements`). Every price carries a `billing_interval` from the five canonical billing periods — `weekly`, `bi_weekly`, `monthly`, `quarterly`, `annual` — used consistently across PHP enums, PostgreSQL CHECKs, price/subscription tables, billing settings, API contracts, frontend TypeScript types, Super Admin and merchant plan-selection screens, invoice-generation schedules, renewal-date calculation, reminder schedules, reports, and tests. `platform_billing_settings` holds billing mode + trial/grace defaults + currency (versioned, single active via effective dates). Merchants get pricing visibility read models. No invoices or Wallet integration in this phase. Canonical billing modes only (Section 2.1.9). Audit: settings/plan/price/entitlement changes (platform_mutation, MFA + step-up).

## 48. Subscription Lifecycle (Phase 20B)
`merchant_subscriptions` with billing-status machine (Section 25); trial starts at Merchant Admin creation with snapshotted days; read-only grace + suspension transitions; no-proration next-cycle plan changes via `scheduled_plan_changes`; shared overdue escalation events; recovery allowlist middleware. Price (and its `billing_interval`) captured at issuance. Audit: subscription lifecycle, plan-change scheduled/applied.

## 49. Subscription Invoices (Phase 20B)
`subscription_invoices` (+ items) with the subscription-invoice machine; invoice number per merchant; `account_reference` is the Wallet structured payment reference `SRV-PAY-…` (ADR‑014; nullable until Wallet registration succeeds; immutable once set); issued invoices immutable; balance from confirmed payments (verified Wallet events only). Phase 20B ships only the nullable, forward-compatible Wallet projection columns (`wallet_payment_id`, `wallet_registration_status` default `'unregistered'`, `wallet_registered_at`, and nullable-until-registered `account_reference`) — no outbox intent, table, or consumer exists in 20B. Phase 20D‑W then (1) idempotently backfills registration for every existing unregistered payable subscription invoice, (2) registers newly issued invoices after commit, (3) guarantees registration before payment instructions or STK initiation are served (§56.1), and (4) always uses the stable idempotency key `srv:pay-reg:{invoice_ulid}` — which removes any 20B→20D‑W ordering risk without an undefined intermediate mechanism. Invoice PDFs render payment instructions only when registered; otherwise the PDF carries "Payment reference pending — see your billing dashboard" and is regenerated (new file version per 10F rules) after registration. Discounts/free periods snapshot at issuance and never mutate issued invoices. Invoice finalization (number/percentage-fee rollup) is a financial_mutation (idempotent). Billing-invoice PDFs are files (Section 65). Audit: issue/overdue/paid.
- **Interval date math (deterministic, `Africa/Nairobi`):** the next period and due/renewal dates are computed per `billing_interval`: `weekly` = +7 days; `bi_weekly` = +14 days; `monthly` = +1 calendar month with **end-of-month clamping** (e.g., Jan 31 → Feb 28/29); `quarterly` = +3 calendar months with the same clamp; `annual` = +1 calendar year with **leap-year clamping** (Feb 29 → Feb 28 in non-leap years). Anchor day is the subscription's billing anchor (issuance day-of-month), preserved across months and clamped to the shortest month. Reminder schedules and overdue/grace timers derive from these computed boundaries. Tests cover each interval plus the Jan-31, Feb-29, and year-boundary edge cases.

## 50. Fixed Billing Mode (Phase 20A/20B)
`billing_mode = fixed_amount`: subscription invoice is the flat plan price; **no** percentage-fee ledger entries created (tested). Default launch mode unless configured otherwise.

## 51. Percentage Billing Mode (Phase 20E)
`billing_mode = percentage_on_merchant_client_invoice`: the percentage platform-fee engine computes fees from validated merchant-client invoice amounts into `platform_fee_ledger_entries`, aggregated into subscription-invoice lines. Built and launch-capable; **activated only when configured**. Tier behavior: customer-centric/shared/business-centric. Adjustments/disputes supported. Integer arithmetic + round-half-up + largest-remainder residual (ADR-005).

## 52. Fixed-Plus-Percentage Billing Mode (Phase 20E)
`billing_mode = fixed_amount_plus_percentage_on_merchant_client_invoice`: flat plan price + percentage component; percentage tiers apply only when the percentage component is active (tested). Snapshots fee configuration at invoice finalization.

## 53. Promotions and Free-Period Offers (Phase 20C)
`promotional_discounts` + explicit `promotional_discount_targets`; `free_period_offers` + explicit `free_period_offer_targets` (normalized rows, no unvalidated JSON for targets). Both targeting models support `target_type` in `merchant` | `plan` | `billing_mode` (exactly one of `merchant_id`/`subscription_plan_id`/`billing_mode` set, matching `target_type`); global reach is expressed via the parent `target_scope = all_new_merchants`. **Stacking/priority/conflict resolution:** at most one discount and one free-period offer apply per subscription issuance; when multiple eligible targets match, precedence is `merchant` > `plan` > `billing_mode` > global, ties broken by latest `effective_from` then target ULID; the selected discount and free-period days are snapshotted onto the subscription/invoice and never recomputed afterward. Snapshot application to subscription/invoice; approval + audit workflows; tests prove (a) billing_mode-targeted offers resolve correctly, (b) precedence/tie-breaking is deterministic, and (c) discounts/free periods do not mutate issued invoices or existing trial snapshots. Platform-governed (`platform.promotion.manage`, `platform.free_period_offer.manage`, MFA + step-up).

## 54. Shared Overdue Escalation (Phase 20B)
A single shared escalation pathway drives `active → overdue → suspended_billing` per configured grace, regardless of billing mode; each step is recorded durably in **`billing_escalation_events`** (§13.15; `reminder`/`grace_entered`/`overdue`/`suspended_billing`/`recovered`) and applied to `merchants.billing_status` via the projection service (§22), emitting escalation + suspension events. Scheduler-driven (Section 67); idempotent per `(merchant_subscription_id, event_type, period boundary)`; feeds Super-Admin overdue-escalation reporting (§69). Alerts on scheduler failure (Section 71).

## 55. Wallet by Citrus Payment-Integration Architecture (Phase 20D‑W) — Corrections 14, 15 as amended by ADR‑012

Components (bounded context `app/Domain/Integrations/Wallet`, §10.1): `WalletClientInterface`, `HttpWalletClient`, `FakeWalletClient`, `SyncMerchantWalletAccount`, `RegisterInvoicePayment`, `InitiateWalletStkAttempt`, `QueryWalletPaymentStatus`, `ProcessWalletWebhookEvent`, `ApplyConfirmedWalletPayment`, `RecordWalletReversal`, `RecordExternalRefund`, `OpenBillingReconciliationException`, `ResolveBillingReconciliationException`, `ReconcileInvoiceAllocationsAgainstWallet`, `WalletSignatureVerifier`, `WalletEventOrdering`. Wallet payload DTOs are separate from domain models; the webhook controller verifies and hands off to actions; controllers contain no settlement logic. **No manual Super-Admin payment-recording path** (unchanged). **No provider logic in Servana** (§9 rule 20): no Daraja credentials, OAuth tokens, provider callback endpoints, receipt-uniqueness logic, or provider reconciliation — all of that is Wallet's (§2.2). Machine credentials per §17.1; configuration/secrets/rotation per §77.1.

The money path is: `merchant pays (STK prompt or PayBill/Till with SRV-PAY reference) → Safaricom → Wallet (raw callbacks, receipt uniqueness, provider recon, ledger) → signed Wallet webhook → Servana verification + inbox → ApplyConfirmedWalletPayment under invoice row lock → billing-status projection (billing-only recovery where applicable)`.

The Servana↔Wallet client treats Wallet error codes as machine-readable (Wallet §36.4): unknown 4xx → structured failure, never retried blindly; 5xx/timeouts → retried only for idempotent-by-key calls with capped backoff; circuit breaker per §10.2.

## 56. STK Push and Billing-Payment Endpoints via Wallet (Phase 20D‑W) — Correction 14.4 as amended

### 56.1 Billing-Payment API Endpoint Contracts (Correction 4; replaces the v3 direct-Daraja contract block)

Canonical endpoints (authoritative inventory in the OpenAPI contract). Each lists classification + controls. These contracts cover the whole Wallet billing-payment domain (§55–§58).

```text
POST /api/v1/billing/subscription-invoices/{invoice}/wallet/stk      route: billing.wallet.stk_initiate
  class financial_mutation | auth Sanctum | tenant yes | branch optional(snapshot)
  permission merchant.subscription.pay | merchant.subscription.pay_from_branch | merchant.subscription.pay_simple
  mfa role-mandatory where applicable | rate limit per-merchant + per-invoice strict
  idempotency REQUIRED (client Idempotency-Key; attempt-keyed)
  request  { phone_msisdn }
  sequencing (all inside the action, in order):
    1 authorize role/permission/merchant/branch/recovery-allowlist/payable-state/balance (§9 rule 4 pipeline)
    2 acquire invoice row lock + subscription_invoice_payment_locks (409 request_in_progress if unexpired)
    3 validate + normalize Kenyan MSISDN (422 invalid_msisdn)
    4 ensure wallet_merchant_account_links active (SyncMerchantWalletAccount; on failure 503 provider_unavailable)
    5 ensure invoice registered with Wallet (RegisterInvoicePayment; ADR-014; on failure 503 provider_unavailable)
    6 persist subscription_payment_attempt status='initiated' with servana_idempotency_key BEFORE any Wallet call
    7 set status='submitting_to_wallet'; call Wallet POST /api/v1/payments/{wallet_payment_id}/attempts/stk
      with Idempotency-Key = servana_idempotency_key
    8 on 2xx: store wallet_attempt_id; status='submitted_to_wallet' (→'prompt_sent' on Wallet ack semantics)
      on timeout or ambiguous transport failure: status='submission_unknown' (Correction 14.6 — ambiguity is
        NOT proof of non-acceptance); KEEP the payment lock bounded; retry/query with the ORIGINAL
        servana_idempotency_key; resolve only via authoritative Wallet status; return 202 with polling guidance
      on definitive pre-acceptance 5xx (Wallet rejected before accepting the submission):
        status='provider_unavailable'; release payment lock; return 503 with retry guidance
      on Wallet 4xx (cooldown, invalid state): map to structured 422/409; release lock
  response { attempt_ulid, status } — NEVER success-from-initiation
  audit wallet.stk_initiated (info)
  tests: success; lock-conflict; invalid-phone; foreign-invoice 404; suspended merchant; wallet-down 503 +
         no stranded lock; idempotent replay returns same attempt; cooldown mapped; registration race (two
         concurrent initiations register once)

GET  /api/v1/billing/payment-attempts/{attempt}                       route: billing.payment_attempt_show
  class authenticated read | auth Sanctum | tenant yes | permission merchant.billing_attempts.view_detailed
  (Merchant Admin) | subscription.payment_attempts.view (Finance) | own initiator
  response { attempt_ulid, status, amount_minor, currency, masked_phone, provider_method, created_at,
  applied_invoice_ulid?, wallet_status? } — wallet_status gated to Finance/Merchant-Admin detail permission;
  no full MSISDN | errors 404 foreign-tenant | audit none (read)
  tests: polling states; Front Office cannot view sensitive fields (restricted-field test)

GET  /api/v1/billing/subscription-invoices/{invoice}/payment-instructions   route: billing.payment_instructions
  class authenticated read | auth Sanctum | tenant yes | permission any billing-pay permission
  behavior: if wallet_registration_status != 'registered' → trigger registration (async) and return
  { status: 'instructions_pending' }; else return { paybill_or_till, account_reference: 'SRV-PAY-…',
  amount_minor (= current balance), currency }
  errors 404 foreign-tenant
  tests: reference correctness; pending state; never exposes an internal invoice number as a payable reference

POST /api/v1/integrations/wallet/webhooks                              route: integrations.wallet.webhook
  class partner_webhook_mutation | auth NONE (signature contract §24.1, algorithm-aware per ADR-015)
  request: Wallet signed event envelope (event id/type/version, timestamps, product/application/environment,
  merchant account, resource ids, current/prior state, amount/currency, masked provider reference,
  correlation id — Wallet §35)
  response 200 fast-ack after durable inbox insert (unique wallet_event_id; duplicate → 200 + 'duplicate')
  processing async (§57) | audit wallet.webhook_received (info; high on verification failure)
  tests: valid signature; bad signature uniform 401 + audit; stale timestamp; replayed event id no-op;
  unknown key id; oversized body 413; wrong environment claim; unknown payment → exception row

GET  /api/v1/platform/billing-reconciliation/exceptions                route: platform.billing_reconciliation.index
  class platform read | auth Sanctum | platform staff | permission platform.billing_reconciliation.view
  mfa mandatory | response paginated exceptions (masked) | errors 403 non-platform | tests platform-only

POST /api/v1/platform/billing-reconciliation/exceptions/{e}/resolve    route: platform.billing_reconciliation.resolve
  class financial_mutation(platform) | auth Sanctum | platform staff
  permission platform.billing_reconciliation.resolve | mfa mandatory | step_up REQUIRED | idempotency REQUIRED
  request { resolution:'link_to_invoice'|'dismiss', subscription_invoice_ulid?, note }
  response { exception_ulid, resolution_status }
  errors 409 idempotency, 422 invalid-link
  behavior: link performs ApplyConfirmedWalletPayment against the chosen invoice under lock (linking a
  Wallet-confirmed payment — NOT manual recording); maker/checker when severity=critical
  audit billing.reconciliation_exception_resolved (high/critical; before/after values)
  tests: link-by-reconciliation; no manual record path (route-absence assertion); maker/checker on critical
```

Eligible Merchant Admin/Branch Manager/Finance/Front Office opens the subscription invoice; the backend authorizes role/permission/merchant/branch/recovery access/payable state/balance; the §56.1 sequencing runs; a public-safe attempt ULID + polling status is returned (never success from initiation). Never issue a second STK while an unexpired payment lock exists. Frontend states per §12.1 item 1: initiating, prompt-sent/polling, confirmed, applied, cancelled, timeout, failed, provider-unavailable/retry, support.

### 56.2 Servana→Wallet Client Calls (outbound; not Servana routes)

| Call | Wallet route (Wallet §36.1) | Idempotency-Key | Used by |
|---|---|---|---|
| Register payment | `POST /api/v1/payments` | `srv:pay-reg:{invoice_ulid}` | RegisterInvoicePayment (post-commit registration at issuance, idempotent backfill, + lazy guarantee paths; 20D‑W only) |
| STK attempt | `POST /api/v1/payments/{p}/attempts/stk` | `srv:stk:{attempt_ulid}` | InitiateWalletStkAttempt |
| Status query | `GET /api/v1/payments/{p}` | n/a (safe) | QueryStaleWalletAttemptsJob, NightlyWalletAllocationReconciliationJob, exception-resolve view |
| List attempts | `GET /api/v1/payments/{p}/attempts` | n/a | Finance detail, reconciliation |
| Merchant-account sync | Wallet Foundation registration API (exact route per Wallet OpenAPI at Gate W) | `srv:ma:{merchant_ulid}` | SyncMerchantWalletAccount |

## 57. Wallet Webhook Processing and Event Application (Phase 20D‑W) — Correction 14.5 as amended

Merchants paying by PayBill/Till see official instructions + the exact `SRV-PAY-…` structured reference (§56.1 instructions endpoint); Wallet owns C2B validation/confirmation against that reference and webhooks the outcome. All settlement flows through one verified pipeline:

**Verification (before parse and before any canonical storage — §9 rule 21):** HTTPS/transport → strict content-type → 64 KB body limit → required-header syntax → key-ID resolution → timestamp tolerance ±300 s → content-SHA‑256 match → constant-time signature verification (algorithm identifier + key ID + contract version, ADR‑015) → JSON parse → event schema validation → **canonical first-seen `wallet_event_id` insertion** into the encrypted verified `wallet_webhook_inbox` (unique constraint decides replay) → 200 fast-ack → async processing. Fail → uniform 401 (413 for size), **no inbox row** (an unverified request never occupies the canonical event-ID uniqueness), high-severity security audit event with body/request hash + minimal non-sensitive metadata, metrics/alerts.

**Processing algorithm — `ProcessWalletWebhookJob` per inbox row, in one flow:**

1. Load row `FOR UPDATE`; skip if not `received`.
2. Resolve `wallet_payment_id → subscription_invoice` via the registration link; miss → exception `unknown_payment`, mark `processed` (the event is Wallet-valid, just not ours to apply), severity high.
3. Order guard (Correction 14.8): the Wallet contract must publish a **monotonic per-resource version field** (`resource_version` or `state_sequence` — exact name pinned at Gate W, §80.2). Servana applies an event's state only when its version is **strictly newer** than the last-applied version stored on the attempt/payment projection; an equal-or-older version → record snapshot only (`ignored`). `occurred_at` and the event ID are **not** sufficient ordering authority (wall clocks skew, IDs are not sequenced); they remain forensic metadata only. Until the field is pinned, no ordering logic may be implemented.
4. Switch on `event_type` (names per Wallet OpenAPI at Gate W; mapping is 1:1 with Wallet collection states, Wallet §20.3):
   - attempt-progress (SUBMITTED/PROVIDER_ACCEPTED/PROCESSING/PENDING_CUSTOMER_ACTION) → project attempt status.
   - `SUCCEEDED` / partial-receipt confirmation → **ApplyConfirmedWalletPayment**: open transaction; lock invoice row; upsert the one-per-Wallet-payment `subscription_payments` aggregate (unique `wallet_payment_id`) and verify event first-seen (insert a `subscription_payment_receipts` child row with unique `confirming_wallet_event_id` — Correction 14.10); verify amount invariant (cumulative applied ≤ Wallet received; breach → `amount_mismatch` exception, no apply); allocate: `<balance → partially_paid`, `=balance → paid`, `>balance → paid + merchant_billing_credits (source='overpayment')` (unchanged §58 semantics; ADR‑005 rounding); run the billing-status projection (may perform billing-only recovery `suspended_billing→active`; never touches `merchants.status`); enqueue the R&E `subscription.payment_received` outbox row in the same transaction; commit; audit `wallet.event_applied`.
   - `FAILED/REJECTED/CANCELLED/EXPIRED` → project attempt terminal state; release payment lock; notify initiator per §12.1 UX states.
   - `REVERSED / REFUNDED / PARTIALLY_REFUNDED` → **RecordWalletReversal/RecordExternalRefund**: insert `subscription_payment_reversals`; reduce allocation under lock (reversal > allocation → `reversal_exceeds_allocation` critical exception, no partial apply); re-project billing status; enqueue matching R&E event; audit high.
   - `RECONCILIATION_EXCEPTION` (Wallet-side) → open local exception `unmatched_reference`/`duplicate_confirmation` as mapped, for Super-Admin linkage.
   - `SETTLED / SETTLEMENT_PENDING` → informational: update the `wallet_settlement_status` projection on the payment row; contributes to `payment_cleared` gating (§58B.1).
5. Mark inbox `processed`; failures → `failed` with a redacted code, retried with backoff, dead-lettered after policy; dead-letters alert.

Super Admin resolves exceptions by linking an already-Wallet-confirmed payment to the correct invoice (reconciliation, **not** offline manual recording); resolution requires reason + MFA step-up + before/after audit + maker/checker for critical severity.

## 58. Reconciliation, Reversals, and Recovery via Wallet (Phase 20D‑W) — Correction 14.6–14.9 as amended

Apply confirmed amounts under the invoice row lock exactly as §57; reactivate **only** billing-only suspension (SUP‑04). STK attempts expire after a configurable period (timeout ≠ proof no funds moved); `QueryStaleWalletAttemptsJob` queries Wallet's status API for stale attempts; `NightlyWalletAllocationReconciliationJob` compares Servana applied allocations vs Wallet received totals for every invoice with activity in the last 45 days (drift → `allocation_drift` exception; also evaluates `payment_cleared` gating, §58B.1); Wallet downtime leaves the invoice payable with a transparent retry/support state. Reversals/refunds/chargebacks arrive only as Wallet events and are recorded as `subscription_payment_reversals` rows (§13.11) — settled payment history is never edited.

Role-specific exposure (unchanged intent): Merchant Admin (plan/invoice/payment/recovery), Branch Manager (branch-context invoice/payment action), Finance (detailed attempts, masked phone, `wallet_status`, masked provider references, balance, reconciliation status), Front Office (simple amount due + progress), HR/Personnel/Audit (no default initiation), Super Admin (billing-reconciliation exceptions + integrations health; no normal "record payment").

### 58.1 Wallet Edge-Case Catalogue (Normative — each case has a named test in §75.1)

| # | Scenario | Required handling |
|---|---|---|
| W‑01 | STK success happy path | initiated→submitted→prompt_sent→confirmed→applied; invoice paid; audit chain complete; R&E payment_received enqueued in the apply transaction |
| W‑02 | Customer cancels prompt | Wallet event → `customer_cancelled`; payment lock released; invoice unchanged; retry allowed after lock expiry |
| W‑03 | Prompt timeout then late success callback | `timeout` recorded; later `SUCCEEDED` event still applies (timeout is non-terminal for funds); UX shows applied; no duplicate application |
| W‑04 | Duplicate Wallet event (same `wallet_event_id`) | Inbox unique constraint → `duplicate`; 200 ack; zero domain effect |
| W‑05 | Two different events confirming the same funds (Wallet defect) | Second apply blocked by amount invariant → `amount_mismatch`/`duplicate_confirmation` exception; no double credit |
| W‑06 | Same `wallet_payment_id` referenced by events resolving to two invoices | Application requires event payment == invoice's registered payment; mismatch → `wallet_payment_reused` critical exception |
| W‑07 | Concurrent STK initiations on one invoice | Row lock + `subscription_invoice_payment_locks` → second gets 409 `request_in_progress` |
| W‑08 | Concurrent webhook + status-query both trying to apply | Apply keyed on first-seen confirming event + invoice row lock → exactly one application |
| W‑09 | Partial payment | `<balance` → `partially_paid`; balance reduced; instructions endpoint shows remaining balance; a subsequent payment completes |
| W‑10 | Overpayment | `>balance` → paid + `merchant_billing_credits` overpayment row (A‑10); ADR‑005 rounding on any residuals |
| W‑11 | Payment against an already-paid invoice (late C2B) | Full amount → overpayment-credit path or `overpayment_review` exception per configured threshold; never rejected silently |
| W‑12 | Wrong/unknown structured reference paid at PayBill | Wallet owns C2B validation; if Wallet still confirms and webhooks an unknown payment → `unknown_payment` exception; Super Admin links or dismisses |
| W‑13 | Wallet API down at initiation | 503 `provider_unavailable`; attempt row records the failure; **payment lock released**; invoice remains payable; UX retry/support state |
| W‑14 | Wallet down at issuance registration | `wallet_registration_status='failed'` + retry backoff; issuance itself unaffected; instructions endpoint returns `instructions_pending`; STK blocked until registered |
| W‑15 | Webhook signature invalid / unknown key / stale timestamp / oversized | Uniform 401 (413 for size); **no inbox row** (no event-ID squatting); high-severity security audit with body/request hash; metrics/alert; no parse-for-routing |
| W‑16 | Wallet sends a staging event to the production endpoint | Environment-claim check → rejected + audit (§77.1) |
| W‑17 | Out-of-order events (PROCESSING after SUCCEEDED) | Ordering guard: stale `resource_version`/`state_sequence` (or non-terminal after terminal) → snapshot-only `ignored`; never regress on `occurred_at` comparison alone |
| W‑18 | Reversal after invoice paid & merchant recovered | Reversal row reduces allocation under lock; invoice may regress to `partially_paid`; billing projection re-runs (may re-escalate per grace); R&E `payment_reversed`; qualification correction if a decided period is affected |
| W‑19 | Reversal amount exceeds the applied allocation | `reversal_exceeds_allocation` **critical** exception; no partial apply; manual resolution |
| W‑20 | External refund recorded by Wallet | `refunded_externally` projection + reversal-row semantics; R&E `refund_issued`; audit high |
| W‑21 | Billing-only suspension recovery | Applied payment covering suspension debt → projection `suspended_billing→active`; recovery allowlist honored; `merchants.status` untouched (fraud/manual suspension stays blocked — tested both ways) |
| W‑22 | Stale attempt with Wallet status UNKNOWN | Remain non-terminal; re-query with backoff; after the policy window → `stale_no_status` exception; invoice stays payable |
| W‑23 | Registration idempotency race (instructions + STK concurrently trigger register) | Both use `srv:pay-reg:{invoice_ulid}`; Wallet returns the same payment; a single link is stored (unique column) |
| W‑24 | Merchant-account sync failure mid-initiation | 503 with a structured code; no attempt submitted; sync retried by backoff |
| W‑25 | Allocation drift found by the nightly job | `allocation_drift` exception with both totals; blocks `payment_cleared` emission for affected payments until resolved |

## 58A. Citrus Refer & Earn — Referral Capture, Outbox, and Signed Delivery (Phase 21R‑A; ADR‑013)

### 58A.1 Referral Capture at Self-Registration

The merchant self-registration page accepts `?ref=SERVANA-XXXXX` (and central-redirect equivalents) and manual entry (§12.1 item 5). `CaptureReferralSnapshot` runs **inside the registration transaction**: it stores the immutable `referral_snapshots` row (§13.17) with the raw code encrypted, the normalized code, the capture channel, and allowlisted landing metadata — and nothing else happens synchronously. Registration **never** blocks or fails because of R&E (A‑19). Validation (`ValidateReferralCode`) and attribution confirmation (`ConfirmAttribution`) run asynchronously with backoff; results drive the §25.6 snapshot machine (`validated`, `confirmed`, `rejected`, `expired_unconfirmed`). Malformed codes are marked `invalid_format` and are never sent to R&E. At most one snapshot exists per merchant (unique constraint). No referrer identity is ever stored or displayed — Servana holds only the code and the R&E public attribution ID.

### 58A.2 Outbound Event Emission and Delivery (binding)

Every event is created by `EnqueueProductEvent` **inside the same database transaction as the source domain change** (outbox pattern; §13.17 `re_outbound_events`) — a fact and its event row commit or roll back together. Event bodies are canonical JSON (sorted keys, no insignificant whitespace) so `content_sha256` is deterministic. Per-merchant `sequence_no` preserves ordering (R&E workers partition by merchant).

`DeliverReOutboxJob` signs and delivers to `POST {RE}/api/v1/integrations/products/{productCode}/events` with headers exactly per R&E dev plan §11.7 (`X-Citrus-Key-Id`, `X-Citrus-Event-Id`, `X-Citrus-Event-Type`, `X-Citrus-Event-Version`, `X-Citrus-Timestamp`, `X-Citrus-Nonce`, `X-Citrus-Content-SHA256`, `X-Citrus-Signature`, `Idempotency-Key = event_id`), signing the canonical string of §9 rule 22. Delivery response handling:

- `202` → `delivered` (R&E accepts after durable write).
- `409 EVENT_ID_PAYLOAD_MISMATCH` → **stop retrying that event**, mark `dead_letter`, open a critical incident (payload-tamper signal); never mutate-and-resend.
- `401/403` → pause the queue + alert (credential problem).
- `422` schema → dead-letter + alert (contract drift); the fix ships as a code change with a schema version bump, then replay.
- `429/5xx/timeout` → exponential backoff with jitter (base 30 s, cap 1 h, max age 7 days → dead-letter + alert). Same event ID + same hash across retries always (append-only outbox guarantees).

Companion calls: `POST …/referral-codes/validate` (from `ValidateReferralCode`; response codes map to `snapshot_status`), `POST …/attributions/confirm` (from `ConfirmAttribution`; stores `re_attribution_public_id`; idempotent by snapshot ULID), and the R&E reconciliation API (used by `ReconcileReEventGapsJob` with product-scoped cursors, hourly).

## 58B. Citrus Refer & Earn — Event Catalogue, Qualification Engine, Reconciliation Surface (Phase 21R‑B; ADR‑013)

### 58B.1 Event Catalogue and Servana Source Mapping (R&E dev plan §11.8 — all 17 required types)

| Event type | Emitted when (Servana source of truth) | Owning phase |
|---|---|---|
| `merchant.registration_started` | Self-register transaction commits (merchant `pending_setup` created) — emitted only when a referral snapshot exists (`captured`/`validating`+); unreferred merchants emit nothing (data minimization) | 21R‑A |
| `merchant.admin_created` | First `merchant_admin` membership created (same registration transaction) — same referral-presence condition | 21R‑A |
| `merchant.setup_completed` | First-time setup flips merchant → `active` | 21R‑A |
| `merchant.status_changed` | Any `merchants.status` transition (active/suspended/deactivated), with reason **category** only (`fraud`,`security`,`legal`,`compliance`,`manual`) — never free-text reasons | 21R‑A |
| `merchant.identity_snapshot_changed` | Merchant legal/business identity profile fields change (name, registration identifiers) — snapshot hash, not raw documents | 21R‑A |
| `subscription.invoice_issued` | 20B issuance commits (invoice ULID, period, total, currency, due date) | 21R‑B |
| `subscription.payment_received` | ApplyConfirmedWalletPayment commits (§57) — amount applied, invoice ULID, paid_at | 21R‑B |
| `subscription.payment_cleared` | Clearing rule met for an applied payment: applied AND no open exception for its `wallet_payment_id` AND (Wallet settlement projection ∈ {SETTLED} OR the nightly allocation reconciliation matched the payment and `clearing_grace_days` elapsed). Evaluated by the nightly job; one cleared event per payment | 21R‑B |
| `subscription.payment_reversed` | `subscription_payment_reversals` kind='reversal' committed | 21R‑B |
| `subscription.refund_issued` | kind='external_refund' committed | 21R‑B |
| `subscription.chargeback_recorded` | kind='chargeback' committed | 21R‑B |
| `subscription.plan_changed` | `scheduled_plan_changes` applied at the cycle boundary (from/to plan public keys) | 21R‑B |
| `subscription.suspended` | Billing-status projection reaches `suspended_billing` | 21R‑B |
| `activity.qualification_decided` | EvaluateQualificationPeriod writes decision_version=1 | 21R‑B |
| `activity.qualification_corrected` | Any decision_version>1 (late clearing, reversal-driven re-run, platform-triggered correction) — references the superseded version | 21R‑B |
| `merchant.product_tenant_closed` | Merchant deactivated (terminal) | 21R‑B |
| `merchant.product_tenant_merged` | **Not applicable at launch** (Servana has no tenant-merge capability). Documented N/A in the integration contract with R&E; if merge is ever built, the event ships with it. | n/a |

Emission scope rule: all `subscription.*` and `activity.*` events are emitted **only for merchants with a referral snapshot in `validated`/`confirmed` status** (plus `merchant.*` per the table). This is the data-minimization boundary: R&E has no business need for facts about unreferred merchants, and Servana must not stream its whole billing ledger to a partner system. If a snapshot reaches `confirmed` after some events were skipped (out-of-order confirmation), `ReconcileReEventGapsJob` backfills the missed window through the reconciliation API rather than fabricating late events.

### 58B.2 Payload Minimal-Fact Schema (v1)

Common envelope fields inside every payload: `product_code`, `environment`, `merchant_public_id` (Servana merchant ULID = R&E `source_tenant_id`), `event_id`, `occurred_at`, `sequence_no`, `schema_version`. Per-type facts carry only: public ULIDs, status enums, amounts in minor units + currency, dates, counts, checksums. **Forbidden in any payload:** client names/phones, staff PII, invoice line descriptions, raw payment references, MSISDNs, free-text reasons, internal sequential IDs. A schema test validates every emitted payload against committed JSON Schemas in `docs/integrations/refer-earn/schemas/*.json`; a forbidden-field test greps payload builders for banned sources (§9 rule 23).

### 58B.3 Qualification Engine (Servana active-use rule — R&E scope §11.2; final authority per ADR‑013)

`EvaluateMonthlyQualificationJob` runs on the 1st of each month + `clearing_grace_days` (`Africa/Nairobi`), under an advisory lock:

1. Resolve the effective `re_activity_rule_versions` row for the closed period; create `re_qualification_periods` rows for every merchant with a `confirmed` attribution snapshot active during the period (skip others as `skipped_unattributed`).
2. Compute per merchant, from Servana's own tables, as of evaluation time:
   - `qualifying_session_count` = completed service sessions (`service_sessions.status='completed'`, business date in period, merchant-scoped, all branches).
   - `validated_invoice_count` = merchant-client invoices with a Finance-validated payment allocation in the period (Phase 18B facts).
   - `subscription_paid` = every subscription-invoice obligation whose period overlaps the qualification period is fully paid **and cleared** (§58B.1 clearing rule).
   - `suspension_clear` = no `merchants.status` fraud/security/legal/compliance/manual suspension overlapping the period.
3. Decision: `qualified` iff sessions ≥ rule.min (10) AND invoices ≥ rule.min (3) AND paid AND clear; else `not_qualified` with the **first** failing `failure_category` in the deterministic order above.
4. Insert the append-only decision (version 1), compute `evidence_checksum = sha256(canonical evidence tuple)`, and enqueue `activity.qualification_decided` in the same transaction. Idempotent per (period, rule version): re-runs with identical evidence are no-ops; re-runs with different evidence **must not** insert same-version rows (unique constraint) — they go through `CorrectQualificationDecision`.
5. Corrections (`decision_version` n+1) are triggered by: late `payment_cleared`, a payment reversal/refund/chargeback affecting the period, retroactive suspension backdating, or platform correction (`platform.referral.qualification.correct`, step-up, reason). Each correction supersedes-by-reference and emits `activity.qualification_corrected`. Lower/duplicate versions can never override (unique + monotonic check), mirroring R&E decision-precedence rules.

### 58B.4 Inbound Reconciliation Endpoint (R&E → Servana)

```text
POST /api/v1/integrations/refer-earn/reconciliation/query    route: integrations.refer_earn.reconciliation
  class partner_signed_query | auth HMAC (§24.1) | rate limit per key
  request { query_class: 'event_by_id'|'events_by_merchant_period'|'qualification_decision'|'subscription_payment_summary',
            parameters: {…} }   -- only these four bounded classes; anything else → 422
  responses return §58B.2-grade minimal facts + evidence checksums; no client PII; no amounts beyond
  subscription totals already emitted; cursor pagination where lists.
  tests: signature required; nonce replay 409; unknown query class 422; scope: cannot fetch facts for a
  merchant with no attribution snapshot unless the query is event_by_id for an event Servana actually emitted.
```

### 58B.5 Refer & Earn Edge-Case Catalogue (Normative — each case has a named test in §75.1)

| # | Scenario | Required handling |
|---|---|---|
| R‑01 | Valid `?ref=` at registration | Snapshot in registration txn; async validate→confirm; notice UI; `registration_started` + `admin_created` events |
| R‑02 | Malformed code | `invalid_format`; registration proceeds; nothing sent to R&E |
| R‑03 | R&E down at registration | Registration unaffected (A‑19); validation retried with backoff; snapshot `captured→validating` loops safely |
| R‑04 | Code valid but attribution conflict at R&E (another referrer already effective) | R&E confirm response drives `rejected`; no further events; snapshot immutable evidence retained |
| R‑05 | Confirmation arrives after some lifecycle events were skipped | Gap backfill via the reconciliation API (§58B.1 scope rule); no fabricated event timestamps |
| R‑06 | Outbox delivery 5xx/timeout | Backoff retries, same event ID + hash; max-age → dead-letter + alert + replay command |
| R‑07 | `409 EVENT_ID_PAYLOAD_MISMATCH` from R&E | Stop that event permanently; critical incident (tamper signal); never mutate-and-resend |
| R‑08 | 422 schema rejection | Dead-letter + contract-drift alert; fix ships as a code change with a schema version bump, then replay |
| R‑09 | Duplicate delivery after network ambiguity | R&E dedupes by event id + hash → prior acceptance returned; Servana marks delivered |
| R‑10 | Qualification: exactly 10 sessions + exactly 3 validated invoices + paid + clear | `qualified` (boundary inclusive) |
| R‑11 | 9 sessions | `not_qualified`, `insufficient_sessions` (first failing category deterministic) |
| R‑12 | Paid but not yet cleared at evaluation | `not_qualified`/`subscription_not_fully_paid` at v1; on later clearing → correction to `qualified` (version 2) |
| R‑13 | Reversal after a `qualified` decision | Correction run → `not_qualified` v2 referencing v1; R&E handles hold/reversal on its side; Servana never deletes v1 |
| R‑14 | Evaluation re-run with identical evidence | No-op (checksum equal); no duplicate rows/events |
| R‑15 | Same-version different-evidence attempt | Unique constraint blocks; critical integrity alert (mirrors the R&E conflicting-decision rule) |
| R‑16 | Merchant deactivated mid-period | `merchant.product_tenant_closed`; the period evaluates with `merchant_closed`/suspension category per timing |
| R‑17 | Suspension backdated after a decision | Correction pathway with reason; audit high |
| R‑18 | Inbound reconciliation replayed nonce | 409 replay; audited |
| R‑19 | Inbound query for an unreferred merchant | Empty scoped result (no existence leak of billing facts); only `event_by_id` for actually-emitted events answers |
| R‑20 | Rule change mid-quarter | New `re_activity_rule_versions` row prospective-only; open periods pinned to their version; no silent change (guard test rejects overlapping ranges) |
| R‑21 | Clock skew: event `occurred_at` in outbox vs delivery time | Signing timestamp is delivery-time; `occurred_at` is business time; R&E tolerance applies to the signing timestamp only |
| R‑22 | Two registrations submit the same code | Both snapshots stored (different merchants); attribution uniqueness is R&E's decision per merchant-product tenant; Servana treats each independently |

## 59. Compensation-Plan Management (Phase 20F) — Correction 19
`compensation_model` is separate from employment type: `commission_only` (commission rule required, salary null, no salary ledger), `salary_only` (salary fields required, commission rule null, no commission ledger), `salary_plus_commission` (both required, both ledgers). Compensation configuration never grants login/role/branch/availability/eligibility. Effective-dated plans: one active plan per staff profile, branch, and date (date-range exclusion constraint); active monetary terms immutable (supersede with a new `effective_from` version); mid-period changes split salary by effective dates; commission uses the rule active on the configured business event date (service/invoice date recommended unless configured otherwise); backdated changes require approval + reason + impact preview + critical-severity audit. HR sets up and submits; HR approves per `compensation.plan.approve` where the scope assigns approval to HR (and Merchant Admin/Finance where policy requires). Preferred-personnel-fee treatment per `applies_to_preferred_personnel_fee`. Audit: plan create/submit/approve/reject/supersede; backdated change (critical).

## 60. Salary Processing (Phase 20G) — Correction 19.5
Scheduler creates accruals per pay period (`salary_ledger`) in `Africa/Nairobi`; daily/hourly/per-shift salary requires approved source attendance/shift data (no inferred hours); monthly/weekly salary prorated across effective-date segments using a documented day-count convention; **suspension behavior defaults to `suspension_salary_policy = continue`** (Plan A-11), with an optional **prospective** merchant override to `pause` that takes effect from its effective timestamp and **never retroactively rewrites accrued salary**; on resumption, accrual restarts from the resumption date; termination ends accrual on the termination date while preserving unpaid earned commission; every scheduler run idempotent via unique (plan, staff, pay-period segment, entry_type). Override change requires HR permission + approval and is audited; personnel see the policy in their compensation terms. Liability reports (Section 69).

## 61. Commission Processing (Phase 20G) — Correction 19.4
Service-session completion may create a non-payable `pending_preview` only; invoice finalization snapshots personnel/service/rule identity/basis; payment recording does **not** earn commission; **Finance validation** allocates validated amount to invoice items; for each eligible item, resolve the plan/rule effective on the configured date; compute with integer arithmetic (percentage `round_half_up(basis_minor * basis_points / 10000)`; residual minor units across items via largest-remainder, ties by ascending invoice-item ULID; fixed minor capped where required) per ADR-005; create exactly one idempotent earned ledger entry per validation allocation; refund/void/payment reversal creates a negative reversal entry that is the **exact negative of the original stored amount** (never recomputed) referencing the original; already-paid commission reversals become a negative adjustment in a future payout (paid history never rewritten). Salary-only plans never generate commission rows (tested). Audit: earned/reversal/adjustment.

## 62. Payout Runs (Phase 20H) — Correction 19.6–19.7
Ownership: HR creates/edits/submits draft; Finance verifies; **Finance approves ordinary** runs; **Merchant Admin approves high-value** runs after Finance verification; Finance marks paid after external payment. Servana does not move payout money at launch. High-value threshold comes from merchant compensation settings, is snapshotted onto the run, and is not hardcoded. At creation, snapshot eligible unpaid ledger entries into payout items (salary/commission/approved adjustments/gross + source refs); the run freezes on submit (corrections via rejection→new draft or an adjustment run, never silent line edits). Mark-paid requires approved status + external reference + paid date + Finance actor + fresh step-up + idempotency + row lock + ledger-status update + personnel notification/statement availability. Audit: create/submit/verify/approve/reject/mark-paid (mark-paid critical).

## 63. Earnings Statements and Queries (Phase 20H) — Correction 19.8–19.9
Personnel own-scope: overview; commission tab only for models with commission; salary tab only for models with salary; compensation terms; payout history; downloadable period statement (PDF file, Section 65); earnings query. `staff_profile_id` derived from membership; arbitrary staff IDs rejected. Earnings queries: personnel creates against own ledger/payout item; type validated; Finance/HR assignment by type; resolution never mutates ledger silently (monetary correction = adjustment entry); personnel sees status + resolution note; all events audited.

## 64. Personnel Bulk SMS (Phase 21S) — Correction 20
Controlled messaging to **personally served clients only**; permanently no contact export (ADR-010). Compose/send: personnel opens served-clients view (own served clients only, paginated, masked contact); select recipients (configurable max batch); compose within configurable char/segment limit; backend revalidates every recipient at preview (returns recipient count, excluded count/reasons, estimated segments, estimated KES cost, billing notice); personnel confirms explicitly; backend revalidates entitlement/billing status/own-scope/consent/cost; create campaign/recipient snapshots transactionally; queue delivery; record provider result + cost; roll up billable SMS charge to Servana billing; show final status without a downloadable phone list. Contact-protection controls: no CSV/XLSX/PDF/clipboard/print/API export of contacts; no endpoint returns bulk full phone numbers; rate-limit search + sends; detect enumeration patterns; escape/validate search input; no phone numbers in logs/analytics/URLs/frontend persistence; guessed export-shaped routes → 404 + high-severity audit. Provider adapter interface; redact provider payloads; retry transient (capped backoff), not permanent invalid/opt-out failures; dedupe by campaign-recipient key; idempotent delivery receipts. Tests per Correction 20.8 (prove personnel cannot view/message others' clients, cannot message a client with no completed own session, cannot export contacts, billing/entitlement gates, cost-preview accuracy, duplicate-confirmation single send, opt-out suppression, cross-tenant/branch denial, no full-phone exposure).

## 65. Files and Media (Phase 10F) — Correction 13
Owning phase before any feature that stores/exports files. `uploaded_files` + `file_scan_events` (Section 13.13). Pipeline: authorize purpose before bytes; per-purpose size/extension allowlists; stream to private quarantine; compute SHA-256 while streaming; inspect magic-byte/server-detected MIME (never trust browser MIME/filename); reject archives unless a feature requires them; reject executables/scripts/active-SVG/macro-office/polyglot unless an approved sanitizer exists; create `uploaded_files` `pending/quarantined`; dispatch tenant-aware ClamAV scan; on clean → move to private final prefix + mark available; on infected/failed → block download, quarantine/delete per policy, notify security ops; generate images via safe server-side processing stripping metadata; never expose storage paths (downloads via an authorized endpoint issuing a short-lived signed URL or streaming). Download authorization: authenticated (except public brand assets) + tenant ownership + branch scope + resource permission + file purpose + available status + billing read-only policy (existing downloads allowed; new export/report generation blocked during read-only grace/suspension) + personnel own-scope for statements/personnel files. Jobs (idempotent, tenant-aware): `ScanUploadedFile`, `FinalizeCleanFile`, `ExpireSignedExport`, `DeleteExpiredQuarantineFile`, `VerifyOrphanedFileRecords`. Tests: MIME spoofing, double extension, oversize, malware EICAR, cross-tenant/branch download, personnel-other-statement, signed-URL expiry, export download count, read-only blocks new export but allows existing download, log redaction.

## 66. Notifications (Phase 21N)
`notifications` (mail/database channels); branded templates; no secrets/PII beyond masked data in payloads; recipient authorization (e.g., daily branch reports email only authorized Merchant Admin). Notification + file audit events on report delivery. Used by: invitations, welcome, payment validation, payout mark-paid, billing escalation, Wallet payment-attempt outcomes (terminal states to the initiator), new critical billing-reconciliation exceptions (Super Admin), platform alert channels for breaker-open / outbox age > 1 h / qualification-run failure, earnings-query updates.

## 67. Queues and Scheduled Tasks (Phase 21N)
Queue classes (separate workers): `critical-billing`, `notifications`, `reports-exports`, `file-scanning`, `wallet-events`, `re-outbox`, `re-qualification`, `default`. All jobs are tenant-aware (`TenantAwareJob`) and idempotent.

| Integration queue | Workload | Concurrency policy |
|---|---|---|
| `wallet-events` | Webhook event processing | Moderate concurrency; per-`wallet_payment_id` ordering via unique job middleware (`WithoutOverlapping` on payment ID) |
| `critical-billing` | Apply-payment, stale-attempt queries, nightly allocation reconciliation | Small controlled concurrency; DB row locks authoritative |
| `re-outbox` | Signed event delivery to R&E | High concurrency; per-merchant ordering partition (events for one merchant deliver in `sequence_no` order) |
| `re-qualification` | Monthly evaluation, corrections, gap reconciliation | Singleton scheduling via advisory lock; idempotent per (merchant, period, rule version) |

Scheduler (singleton/leader) runs: trial/grace/suspension transitions; shared overdue escalation; salary accrual; daily branch day-close + cash-up report generation/email; signed-export expiry; quarantine cleanup; orphan-file verification; idempotency prune; audit-chain verification; and the integration schedule below (all idempotent, advisory-locked, `Africa/Nairobi`):

| Job | Schedule | Purpose |
|---|---|---|
| `QueryStaleWalletAttemptsJob` | every 10 min | Attempts in `submission_unknown`/`submitted_to_wallet`/`prompt_sent` older than expiry → `GET /payments/{p}` with the original attempt identity; project truth; timeout/ambiguity ≠ proof of no funds |
| `NightlyWalletAllocationReconciliationJob` | daily 02:30 | For every invoice with activity in 45 days: compare Servana applied allocations vs Wallet received totals; drift → `allocation_drift` exception; also evaluates `payment_cleared` gating (§58B.1) |
| `DeliverReOutboxJob` dispatcher sweep | every minute | Deliver `pending` where `next_attempt_at` is due (the normal path is event-driven dispatch at commit; the sweep is the safety net) |
| `ReconcileReEventGapsJob` | hourly | Product-scoped cursor comparison against the R&E reconciliation API; missing acks → redeliver; unexplained gaps → alert |
| `EvaluateMonthlyQualificationJob` | monthly (1st + clearing grace) | §58B.3 |
| Inbox retry sweep | every 5 min | `wallet_webhook_inbox` failed rows with due `next_retry_at` |
| Dead-letter monitor | every 15 min | Alert on any dead_letter growth (outbox or inbox) |

Critical-billing/recovery job lag target ≤30s (Section 71). Failures route to dead-letter/exception queues with alerts.

## 68. Search (Phase 22)
Tenant/branch-scoped search (e.g., Meilisearch) with permission-aware indexing; never index or return cross-tenant data; never cache an unscoped result and filter in the frontend; served-client search is own-scope, masked, and rate-limited (Section 64). Sort/filter fields allowlisted.

## 69. Reporting Catalogue (Phase 21N + owning phases) — Correction 21
Create `docs/reporting/report-catalogue.md`; every report defines: key; business definition; roles + permission; merchant/branch/own scope; source tables/events; metric formula; timezone + date boundary (`Africa/Nairobi`); currency behavior; freshness target; filters + sorting; row-level masking; export availability; retention; scheduled delivery; acceptance tests. Launch reports by role per Correction 21.2 (Merchant Admin revenue/branch revenue/service revenue/staff performance/subscription+billing/compensation liabilities/daily day-close PDF/daily cash-up PDF; Branch Manager operational dashboard/queue delays/appointments-walk-ins-sessions/service performance/day-close+cash-up; Finance pending validations/payment-method breakdown/outstanding invoices/refunds-disputes/cash-up discrepancies/locked periods/salary-commission liabilities/payout runs/subscription attempts; HR staff status/availability+missing eligibility/missing compensation/compensation changes/config summary; Personnel own performance + earnings; Super Admin registrations+suspicious patterns/plan adoption/trial-grace-suspension funnel/subscription revenue/percentage-fee liabilities/Wallet payment success-failure + reconciliation exceptions/overdue escalation; Audit branch events/flagged/compensation/finance/export events). Metric definitions are precise (revenue = validated payments allocated in period, not issuance; outstanding = total − validated − finalized adjustments; commission liability = earned-unpaid balance; queue wait = service-start/call minus queue-entry with documented exclusions; staff performance counts completed sessions + validated revenue, excluding transferred/cancelled). Architecture: operational cards query indexed tables/read models; heavy aggregations use materialized views/read models refreshed by jobs/events; cached report keys include merchant+branch+role/masking+filters+date range; invalidation follows domain events; never cache unscoped + filter client-side. Scheduled PDFs/email: `GenerateBranchDayCloseReport`, `GenerateBranchCashUpReport`, `EmailDailyBranchReportsToMerchantAdmin` (after day close/cutoff; tenant/branch-scoped PDFs in private storage; email only authorized Merchant Admin; idempotent `(branch_id, business_date, report_type)`; notification+file audit; no new report generation during billing read-only while existing reports remain downloadable). Tests: formula correctness with partial/split/refund; isolation; date boundary; PDF idempotency; recipient authorization; read-only behavior; large-dataset performance; masking.

## 70. Audit Logging and Chain Verification (Phase R2 core; Phase 19 full)
`audit_logs` append-only, hash-chained, DB trigger blocks UPDATE/DELETE; `AuditRecorder`/`DatabaseAuditRecorder`. R2 adds auth/invitation/membership/role/permission-override/branch-lifecycle/staff-lifecycle events + the hash-chain **verifier** command + masked read API + branch/platform policies. Phase 19 completes coverage across all financial/billing/compensation/SMS/file/export events and the flagged-event workflow (`audit_flagged_events`); integration audit events land with their owning phases (20D‑W, 21R‑A, 21R‑B). Audit reads are branch-scoped and field-masked per permission; Audit role can update only flagged-event review metadata. Every transition action emits a typed event with severity (info/warning/high/critical). Alerts on chain-verification failure (Section 71).

Integration audit events (hash-chained, append-only, per ADR‑008): `wallet.payment_registered` (info), `wallet.stk_initiated` (info), `wallet.webhook_received` (info; high on verification failure), `wallet.event_applied` (info), `wallet.payment_reversed` (high), `wallet.external_refund_recorded` (high), `billing.reconciliation_exception_opened` (high; critical for reused/exceeds reasons), `billing.reconciliation_exception_resolved` (high/critical; before/after values), `re.referral_captured` (info), `re.attribution_confirmed` (info), `re.attribution_rejected` (info), `re.event_dead_lettered` (high), `re.qualification_decided` (info), `re.qualification_corrected` (high), `re.inbound_reconciliation_query` (info), `integration.credential_rotated` (critical), `integration.breaker_state_changed` (high).

## 71. Observability (Phase 25 baseline; per-phase metrics) — Correction 24.5–24.6
Structured JSON logs with correlation ID, route name, environment, service, and safe actor/tenant identifiers, with the Section 24.5 redaction list enforced. Centralized logs with access control + retention. Distributed tracing across API/queue/partner for critical flows; the Wallet correlation identifier and R&E `X-Citrus-*` request IDs are joined to Servana's `X-Correlation-ID` in structured logs, so a single payment or event is traceable across HTTP → inbox/outbox → job → domain transaction → audit row. Metrics for billing lifecycle, payment attempts, reconciliations, queue state, report generation, and audit events; integration metrics: webhook ack latency p50/p95, inbox processing lag, inbox failed/dead-letter counts, outbox depth + oldest pending age, delivery success ratio by event type, Wallet client latency/error rates + breaker state, registration failure rate, qualification run duration + decision counts by category, reconciliation exceptions open by severity (thresholds documented in `docs/runbooks/integration-alerts.md`: webhook lag > 5 min, outbox oldest > 60 min, any dead-letter, breaker open > 10 min, qualification run missed). Sentry (or equivalent) for exceptions with PII scrubbing. Alerts (each with severity, owner, runbook link, escalation): availability probe failure; readiness dependency failure; HTTP 5xx >2% over 5 min (critical financial endpoints >1%); p95 latency over target for 10 min; queue lag over threshold; failed jobs above baseline or any repeated critical-billing job failure; DB connection saturation/replication lag/disk pressure/long locks/backup failure; Redis memory pressure/evictions; object-storage errors; Wallet initiation-failure spike, webhook verification-failure spike, webhook processing lag, reconciliation backlog, unapplied confirmed payments, R&E outbox backlog/dead-letter, qualification-run failure; trial/grace/suspension scheduler failures; audit-chain verification failure; certificate/secret expiry; dependency vulnerability alerts.

## 72. Performance and Scalability — Correction 24.2
Initial service objectives (replaceable only by a stricter signed infra ADR): monthly availability 99.9% (excluding announced maintenance); API p95 read ≤500 ms (indexed); API p95 write ≤800 ms (excluding external-partner completion); payment-initiation API response ≤2 s (excluding Wallet/provider completion and the handset prompt); Wallet webhook acknowledgement internal target p95 ≤250 ms (verification + encrypted insert only pre-ack); queue lag p95 ≤60 s; critical billing/recovery job lag ≤30 s; RPO ≤15 min; RTO ≤2 h. External provider delays are measured separately and never hidden inside application latency. Performance tests run on large datasets for reports and list endpoints; indexes back every filter/sort; N+1 queries are prohibited and tested.

Integration-specific notes: `wallet_event_id` unique lookups are index-backed; the inbox table is partitioned by month when volume warrants (documented threshold: > 2 M rows). The apply path's invoice row lock is per-invoice, so contention is naturally bounded; `WithoutOverlapping(wallet_payment_id)` prevents redundant concurrent processing rather than relying on lock waits. The outbox uses a covering index (delivery_status, next_attempt_at); the dispatcher batches 100/sweep; per-merchant ordering is enforced by delivering merchant partitions serially only when an earlier `sequence_no` for that merchant is undelivered. Qualification uses set-based SQL aggregation per period (one query per fact class across all attributed merchants), never per-merchant N+1; evidence tuples are computed in bulk; run duration carries a metric with an alert at 15 min. Bottleneck watch list: Wallet client latency (breaker + timeout budget), nightly reconciliation scans (bounded to a 45-day activity window), R&E 429 rate limiting (backoff honors `Retry-After`).

## 73. Threat Model — Section 9 applied
For every sensitive workflow the owning phase documents and tests the attacker model from Section 9.1. The cross-cutting threats explicitly covered: cross-tenant/branch data access; over-privileged staff; suspended-user session reuse; compromised email account replaying Magic Links; replayed/duplicated financial requests and partner webhooks (Wallet events, R&E reconciliation queries); forged partner webhooks; outbox payload tamper; concurrent financial writes; personnel contact extraction; file-upload abuse (MIME spoof, malware, polyglot); SSRF on provider/URL fetches; secret leakage in logs/exports; audit tampering. Each has a documented control and a security-regression test.

## 74. Privacy, Masking, Retention, and Deletion
PII (phone, email, references) stored encrypted where display masking is required; masked at read time per permission; never logged in plaintext (Section 24.5). Retention: financial/audit retention ≥ legal/financial policy (operational backup retention ≥35 days, Section 78); idempotency retention per Section 24.4; `wallet_webhook_inbox` retained 13 months then archived; `re_inbound_requests` retained 90 days; SMS recipient/phone data retained per policy then purged; finance/audit exports expire and are revocable. Cross-platform data minimization per §9 rule 23 (no referrer PII, no client PII in events, no raw provider payloads). Deletion: merchant deactivation is soft lifecycle removal (history preserved); append-only ledgers/audit are never destructively deleted; quarantine and orphan files are cleaned by scheduled jobs. No personnel contact-export channel exists.

## 75. Testing Strategy — Sections 6.4, 13.3, 19.5, 24
Layers: unit (Money, value objects, calculators), domain-service/action, feature, API, request-validation, authentication, authorization, role/permission (parity + per-key positive/denial), tenant-isolation, branch-scope, personnel-own-scope, plan-entitlement, billing-status, operational-status, period-lock, idempotency, concurrency/locking (DB-level), duplicate-callback/replay, partner-webhook verification, outbox atomicity, integration contract (pinned Wallet OpenAPI / R&E schemas via recorded fixtures), ledger-integrity, audit-chain, notification, queue-job, scheduler, file-upload-security, frontend component/store/composable, browser/e2e (Playwright + axe), responsive (3 breakpoints + no-horizontal-scroll), dark-mode, accessibility, security-regression, deployment-smoke, backup-restore. Coverage guards: `DataDictionaryCoverageTest`, `TenantColumnCoverageTest`, `RouteSecurityContractTest`, `FinancialRouteIdempotencyCoverageTest`, permission-matrix parity, OpenAPI/TS parity, traceability CI (Section 85). Tests run in clean containers against **PostgreSQL** + Redis, repeatedly/parallel where flakiness is a risk; isolated Redis/cache/rate-limit prefixes per test (R7). Cases include success/denied/invalid/duplicate/expired/suspended/cross-tenant/cross-branch/unauthorized/concurrent/retry/provider-failure/partial-failure/recovery. No test is skipped/weakened/deleted to pass without an approved documented reason; security/isolation is never weakened to pass a test.

### 75.1 Integration Test Suites (Wallet + Refer & Earn; file-level)

Wallet integration — `tests/Feature/Integrations/Wallet/`:

| File | Purpose (positive / negative / cross-tenant / validation) |
|---|---|
| `StkInitiationTest.php` | W‑01 happy path via FakeWalletClient; lock conflict W‑07; invalid MSISDN; foreign-invoice 404 (cross-tenant); suspended merchant 403; idempotent replay; wallet-down W‑13 with lock release; cooldown mapping |
| `InvoiceRegistrationTest.php` | ADR‑014: eager registration at issuance; lazy on instructions/STK; idempotency race W‑23; failure + retry W‑14; PDF regeneration after registration; account_reference immutability |
| `WalletWebhookVerificationTest.php` | Valid signature 200; bad signature/unknown key/stale timestamp/oversize/wrong environment (W‑15, W‑16) all rejected uniformly + audited; replay W‑04 |
| `WalletEventApplicationTest.php` | Apply full/partial/overpayment (W‑09/10/11); duplicate confirmation (W‑05); payment reuse across invoices (W‑06 — cross-tenant denial); concurrent apply (W‑08); out-of-order (W‑17); timeout-then-success (W‑03) |
| `WalletReversalTest.php` | Reversal/refund/chargeback rows; allocation reduction + status regression + re-escalation (W‑18); exceeds-allocation critical exception (W‑19); paid history never edited |
| `BillingRecoveryViaWalletTest.php` | Billing-only recovery works; fraud/manual suspension never cleared by payment (W‑21); recovery allowlist |
| `ReconciliationExceptionTest.php` | Every §13.11 reason producible; resolve link/dismiss with step-up + idempotency + before/after audit; maker/checker on critical; **no manual payment-recording route exists** (route-absence assertion) |
| `StaleAttemptAndAllocationReconTest.php` | W‑22 status-query lifecycle; W‑25 drift detection blocks clearing |
| `NoDirectProviderIntegrationTest.php` | §9 rule 20 guard: banned symbols, no `*/mpesa/*` routes, no `services.mpesa.*` config |
| `tests/Unit/Integrations/Wallet/` | Signature-verifier vectors (constant-time), event-ordering guard, DTO parsing strictness, ADR‑005 rounding on credit residuals |

Refer & Earn integration — `tests/Feature/Integrations/ReferEarn/`:

| File | Purpose |
|---|---|
| `ReferralCaptureTest.php` | R‑01/02/03: snapshot in txn; registration never blocked; invalid format; Phase 6 as-built regression suite still green (non-breaking extension proof) |
| `AttributionLifecycleTest.php` | validate→confirm; rejection R‑04; expired window; status non-regression trigger; no referrer-PII columns exist (schema assertion) |
| `OutboxEmissionTest.php` | Same-transaction atomicity (fact rollback ⇒ no event row); sequence_no monotonic per merchant; payload schema validation against committed JSON Schemas; forbidden-field scan (§58B.2) |
| `OutboxDeliveryTest.php` | Signing canonical-string exact-match vectors (R&E dev plan §11.7); retry same id+hash; 409 mismatch → dead-letter + critical incident (R‑07); 422 → dead-letter (R‑08); backoff caps; per-merchant ordering under concurrency |
| `SubscriptionEventMappingTest.php` | Each §58B.1 row fires exactly once from its source transition; cleared-gating (R‑12, W‑25 interaction); emission-scope rule (unreferred merchants emit nothing — negative case) |
| `QualificationEngineTest.php` | R‑10 boundary inclusive; each failure category in deterministic order (R‑11); idempotent re-run (R‑14); correction versioning (R‑12/13/17); same-version conflict blocked (R‑15); rule-version pinning + overlap rejection (R‑20); Nairobi month boundaries incl. year boundary |
| `InboundReconciliationTest.php` | Signature required; nonce replay 409 (R‑18); bounded query classes; scope rules (R‑19); tenant isolation of returned facts |
| `GapReconciliationTest.php` | R‑05 backfill; cursor progress; alert on unexplained gap |

Contract and cross-cutting: `RouteSecurityContractTest` additions (§24.1 partner classes; no `*/mpesa/*` routes); `FinancialRouteIdempotencyCoverageTest` covers `billing.wallet.stk_initiate` and reconciliation resolve; consumer-driven contract tests pinned to the Wallet OpenAPI and R&E event schemas run in CI against recorded fixtures, and the same suite runs against the Wallet sandbox at Gate W (§80.2 evidence); E2E (Playwright per tooling): merchant pays an invoice via STK simulation end-to-end; Super Admin resolves an exception; registration with a referral code shows the notice. Live partner systems are never called from CI or unit tests (§81 rule 21). New suites are added to the coverage guards (§13.3).

## 76. CI/CD — Correction 24.7
PR pipeline: Pint → Larastan (level 8 + custom rules + source-scan) → ESLint/vue-tsc → Pest (PostgreSQL 16 + Redis 7 service containers, parallel) → Vitest → SPA build → Playwright + axe → dependency audits (`composer audit`, `npm audit`) → `gitleaks` → container build/scan → coverage/parity/traceability guards → integration contract tests (recorded partner fixtures; §75.1) → remediation-gate check (Section 5.5). Deployment: immutable versioned images; DB migration job before app switch using expand-and-contract; health/readiness gate before load-balancer registration; queue-worker restart with graceful drain; Horizon/scheduler coordination; automatic smoke tests; application rollback only within schema compatibility; feature flags for high-risk billing integrations where staged activation is allowed (but launch-required functionality cannot remain permanently disabled). Security-sensitive PRs require a second reviewer.

## 77. Production Infrastructure — Correction 24.3
Topology: ≥2 stateless web/app replicas behind a load balancer; queue workers separated by class (critical-billing, notifications, reports-exports, file-scanning, default); singleton/leader scheduler; managed/HA PostgreSQL with automated backups + PITR + connection pooling; Redis with persistence/failover sized for sessions/queues/cache/locks; private S3-compatible storage with versioning + lifecycle; separate staging and production databases/buckets/Redis/provider credentials/webhook URLs; no production data copied to development without approved anonymization. Region/data-residency recorded in an infra ADR; network segmentation; TLS termination at the edge; secrets in a managed secrets store. PHP/Node/Composer versions aligned across all images (R7).

### 77.1 Integration Configuration, Secrets, and Rotation (Wallet + R&E)

**Configuration keys** (`config/services.php` additions; values from env/secrets manager):

```text
services.wallet.base_url                 per environment; allowlisted egress host
services.wallet.application_id           Wallet application-registry ID (non-secret)
services.wallet.webhook_key_ids          active key-ID list for inbound verification
services.wallet.timeout_connect_ms / timeout_total_ms / breaker thresholds
services.refer_earn.base_url
services.refer_earn.product_code         'SRV' (or the code the R&E registry assigns — confirm at integration kickoff; §81 rule 24)
services.refer_earn.key_id               active outbound signing key ID
services.refer_earn.inbound_key_ids
services.refer_earn.clearing_grace_days  default 5 (A-18)
```

**Environment separation:** the `APP_ENV=production` boot guard verifies: the Wallet base URL is the production host, all key IDs lack sandbox/staging prefixes, `FakeWalletClient`/`FakeReferEarnClient` bindings are absent (container assertion), and webhook routes reject requests whose signed `environment` claim ≠ `production` (§9 rule 24).

**Rotation runbooks** (`docs/runbooks/`): `rotate-wallet-webhook-secret.md`, `rotate-wallet-api-credentials.md`, `rotate-re-signing-key.md`, `rotate-re-inbound-secret.md`. Common pattern: provision the new key in the partner registry → add the key ID to the accepted set (dual-key window) → switch the active outbound key → observe 24 h of successful traffic on the new key → revoke the old key → `integration.credential_rotated` critical audit event at each step. Each runbook lists verification queries and abort criteria. Redaction of all four secret families per §24.5.

## 78. Backup and Disaster Recovery — Correction 24.4, 24.8
Continuous/PITR log retention supporting 15-minute RPO; daily full/base backup; ≥35-day operational retention (longer per financial/audit/legal policy); object-storage versioning + lifecycle; quarterly restore test into an isolated environment proving application boot, tenant counts, financial totals, audit-chain verification, and representative downloads, with restore duration recorded against RTO. Backups encrypted. Incident severities: SEV-1 (cross-tenant exposure, incorrect financial settlement, widespread outage, compromised credentials), SEV-2 (major role workflow unavailable, reconciliation backlog risking access recovery, scoped data corruption), SEV-3 (degraded noncritical feature). SEV-1 actions: immediate containment, evidence preservation, credential rotation where required, stakeholder notification, post-incident review. Financial/audit data is never repaired through ad hoc SQL without a reviewed script and before/after evidence.

---

## 79. Step-by-Step Remediation Roadmap (Phase V + R1–R7)

Per-phase fields follow Section 7. One correction domain per PR; security-sensitive PRs require a second reviewer; each PR carries migration notes, rollback/forward-repair notes, tests, proof, and updated progress records. The pre-feature remediation gate (Section 5.4) must be fully satisfied before any feature phase in Section 80 begins; feature-delivery obligations (Section 5.4a) are gated by their own owning phase's exit, not its start.

### Phase V — As-Built Verification (Correction 25)
- **Objective:** establish a trustworthy baseline; do not rewrite features.
- **Authoritative refs:** Correction 25; Section 4.
- **Dependencies:** repository access. **Exclusions:** no feature/remediation code changes beyond evidence capture.
- **Procedure:** record commit SHA/branch and confirm no uncommitted production changes; inspect `composer.json`/`composer.lock`/`package.json`/lock/Dockerfiles/compose/CI/env examples and derive actual framework/package versions from lock files and running containers; run migrations on a clean PostgreSQL DB, inspect status, export actual schema, and compare tables/constraints/indexes/triggers/tenant columns with this plan and the data dictionary (verify the audit immutability trigger + hash columns); export `route:list --json` and map every route to classification/middleware/policy/permission, proving forbidden Super-Admin merchant-creation routes do not exist and public/auth routes have correct enumeration posture; inspect tenant global scopes/escape hatches and search for `withoutGlobalScopes`, raw SQL, unscoped queries, direct `find`, mass assignment, status assignment, frontend secrets, and UI-only authorization; inspect queue jobs for tenant context and logging redaction; run all suites in clean containers against PostgreSQL/Redis (repeatedly/parallel; do not trust copied counts; verify skipped tests + reasons) plus Pint, Larastan, ESLint, TypeScript, Vitest, Playwright, axe, dependency audits, gitleaks, image build; verify session revocation after suspension, Magic-Link hashing/expiry/single-use, cross-tenant 404/cross-branch 403 posture, permission-override semantics, and audit rows for implemented events.
- **Deliverables:** `docs/verification/as-built-discrepancies.md` (claim | reported source | actual evidence | status confirmed/partially_confirmed/contradicted/not_verifiable | impact | required correction); regenerated Section 4 + `PROGRESS.md` phase statuses linked to commits/tests, distinguishing `local_complete`/`ci_passed`/`merged`/`deployed_staging`/`deployed_production`; no "reviewed" without reviewer evidence; seed `docs/remediation/register.yaml`.
- **Acceptance/Exit:** every claim in Section 4 has an evidence-based status; any contradicted/materially-partial claim is filed as a C0/C1 item; narrative progress files no longer serve as sole proof. **Blocks** all subsequent phases until complete.

### Phase R1 — Dependency and Runtime Security (Correction 5; ADR-001)
- **Objective:** remove the unsupported/vulnerable framework state.
- **Work:** upgrade to Laravel 12.60+; verify the exact patched version from lock files; pin PHP 8.3 across local/CI/worker/scheduler/production images; remove the invalid advisory ignore (CVE-2026-48019 / GHSA-5vg9-5847-vvmq) after remediation; Composer/PHP/package compatibility review; DB + cache compatibility checks; upgrade notes; CR/LF email-input regression tests; full regression suite.
- **Migration/rollout/rollback:** image rollback within schema compatibility; no schema change required beyond framework defaults (handle any via expand-and-contract).
- **Tests/proof:** full baseline green in clean containers; `composer audit` shows zero unapproved advisories; CR/LF regression tests pass; dependency-audit evidence attached.
- **Exit:** zero unapproved advisories; full baseline green.

### Phase R2 — Core Audit Completeness (Corrections 6 gate, 22)
- **Objective:** complete core audit events + chain verification + masked read.
- **Work:** replace interim auth logging with `AuditRecorder`; add auth, invitation, membership, role, permission-override, branch-lifecycle, and staff-lifecycle events; add the audit hash-chain **verifier** command; add a masked read API + branch/platform policies.
- **DB:** confirm `audit_logs` immutability trigger + hash columns; add `audit_flagged_events` seam if needed (full workflow in Phase 19).
- **Tests/proof:** event-coverage tests + tamper-verification command pass; masked-read denial tests.
- **Exit:** event coverage tests and tamper-verification pass.

### Phase R3 — Privileged MFA and Step-Up (Correction 7)
- **Objective:** real privileged MFA + step-up.
- **DB:** `mfa_credentials` (encrypted secret), `mfa_recovery_codes` (hashed).
- **Work:** TOTP enrollment/confirmation; mandatory MFA for Super Admin/Merchant Admin/Finance; step-up freshness for billing configuration, refund finalization, period reopen, payout approval, payout mark-paid, reconciliation resolution, and sensitive compensation changes; enforcement order per Section 9.4.
- **Tests/proof:** privileged routes deny absent/stale MFA; recovery-code single-use; step-up required per designated action; secrets never logged.
- **Exit:** privileged routes deny absent/stale MFA.

### Phase R4 — Idempotency and Replay Protection (Correction 3)
- **Objective:** correct idempotency store + middleware + coverage.
- **DB:** corrected `idempotency_keys` schema (Section 13.5).
- **Work:** idempotency middleware (Section 24.4); financial-route classification; provider-callback dedupe seams; concurrency + crash-recovery handling.
- **Tests/proof:** same key+same request → one effect; same key+different request → 409; concurrent submissions → one effect; crashed-worker expired-lock recovery; replayed responses contain no secrets/unsafe headers; `FinancialRouteIdempotencyCoverageTest` passes.
- **Exit:** duplicate and concurrent requests produce one effect.

### Phase R5 — Tenant and Branch Schema Hardening (Correction 7)
- **Objective:** structural tenant/branch isolation completeness.
- **DB:** add `merchant_id` to branch-owned tables where missing; backfill from parent branch (expand-and-contract); add indexes/constraints.
- **Work:** extend static analysis + source-scan rules; verify route-bound models cannot bypass tenant resolution (any directly-route-bound branch-owned table must carry `BelongsToMerchant`/`merchant_id` so its binding audits).
- **Tests/proof:** cross-tenant and cross-branch suites pass; `TenantColumnCoverageTest` passes; scoped-binding audit proof.
- **Exit:** cross-tenant and cross-branch suites pass.

### Phase R6 — Session and Authorization Revocation (Correction 7)
- **Objective:** complete revocation + per-request authorization freshness.
- **Work:** verify session, token, Magic-Link, invitation, and cache invalidation; add active-membership + active-role check to every authenticated request; document the 404 cross-tenant / 403 same-tenant cross-branch posture.
- **Tests/proof:** mid-session suspension/deactivation → next request denied; revoked links/invitations unusable.
- **Exit:** next request after suspension is denied.

### Phase R7 — Production Probes, CI Isolation, Environment Parity (Correction 7; ADR-009)
- **Objective:** operability + test isolation + parity + brand decision.
- **Work:** separate liveness from readiness; make production dependencies required in readiness (503 on failure); isolate Redis/cache/rate-limit prefixes in tests; align PHP/Node/Composer versions across images; record the brand contrast decision (ADR-009).
- **Tests/proof:** production-like dependency failure returns 503; repeated parallel CI is stable; rate-limit isolation verified.
- **Exit:** production-like dependency failures return 503; repeated parallel CI is stable. **On completion of V + R1–R7, run the pre-feature remediation completion report and close the pre-feature gate (Section 5.4).**

---

## 80. Step-by-Step Feature Roadmap

Feature phases begin only after the pre-feature remediation gate (Section 5.4) is closed; each phase must additionally complete every FEATURE_DELIVERY_OBLIGATION mapped to it before it exits (Section 5.4a). Each phase follows the Section 7 template, includes the per-feature responsive/dark/accessibility gate (Sections 28–30) and the per-domain spec deliverables (data-dictionary entries §13.2, screen specs §27.1, permission-matrix reconciliation §19, state machines §25, traceability §85), and is one reviewable PR (subphases split where noted). Every phase: reads its scope refs, inspects current code, proves state, produces a file-level checklist, implements only its scope, writes tests first, runs the full suite, produces proof, updates `PROGRESS.md`/`CHANGELOG.md`/traceability, and stops on acceptance failure.

### 80.1 Dependency Graph
```text
Gate(V+R1..R7) → 10 → 10F → 11                        [complete]
10 + 11 → 15A → 15B → 16A → 16B → 16C                  [complete]
16C → 17 → 18A → 18B → 19                              [complete]
17 (COMPLETE — invoices finalized using the legacy fixed `services.preferred_personnel_fee_minor` seam)
20A(preferred_personnel_fee_rules): migrates the legacy service-level values into effective-dated
  preferred_personnel_fee_rules via expand-and-contract, then changes FUTURE invoice finalization to
  resolve the effective rule; finalized invoices are NEVER rewritten. Completed Phase 17 does not
  depend on 20A; 20A supersedes the seam prospectively only.
20A → 20B → 20C
20B → [External Gate W: Wallet Servana Collections Slice] → 20D-W
20A + 17/18 → 20E ; 20F + 18B(validated payments) → 20G → 20H     (20E/20F need not wait for Gate W)
20B → 21R-A (parallel-eligible with 20C..20E)
21R-A + 20B + 20D-W + 16C + 18B → 21R-B
(17,18,20D-W) → 21N ; 16C + 15A(consent) → 21S ; → 22 → 23 → 24 → 25
Launch rule: 20D-W and 21R-B must be complete before Phase 25 exit; if Gate W stalls, escalate per §80.2.4.
```

### 80.2 External Gate W — Wallet "Servana Collections Slice" Readiness (dependency contract, not Servana work)

Servana must not stub, simulate-in-production, or partially implement any Wallet capability. Gate W opens when the Wallet platform demonstrates, in **sandbox** (and later re-verified in production before Phase 25 exit):

1. **Foundation subset:** product + application registry with Servana sandbox/staging/production applications and disjoint machine credentials; merchant-account registration/sync API; product API authentication; Safaricom provider account configured; payment-route configuration; idempotency infrastructure; incoming provider webhook foundation; outgoing signed product webhooks (**signing contract published and pinned**: algorithm identifier(s) — Wallet scope §35 allows HMAC or asymmetric signatures, so Servana must not assume HMAC-SHA-256 until pinned here — header names, canonical string, key-ID scheme, contract version, rotation procedure); **a monotonic per-resource ordering field (`resource_version` or `state_sequence`; exact field name pinned here — required by §57 step 3 / Correction 14.8)**; audit, observability, secret management, environment separation.
2. **Collections subset:** payment resource (`POST/GET /api/v1/payments`) with `external_reference` uniqueness and structured `SRV-PAY-…` reference issuance; STK attempts (`POST /payments/{p}/attempts/stk`, `GET …/attempts`) with cooldown/idempotency controls; M‑Pesa STK callback processing; C2B validation/confirmation with structured Servana references; duplicate-callback + receipt-uniqueness protection; collection state machine incl. partial/overpaid; transaction-status reconciliation; exception queue; immutable ledger postings; basic settlement tracking; webhook delivery with retries/replay; published OpenAPI + event schema versions; a sandbox test harness able to simulate success, cancel, timeout, late callback, duplicate, reversal, and refund.
3. **Explicitly NOT required for Gate W (do not wait for):** B2C/bulk payouts, PesaLink, direct-bank adapters, beneficiaries, multi-approver payouts, treasury transfers, liquidity routing, cross-provider fallback, enterprise compliance tooling.
4. **Gate evidence + stall policy:** the agent records gate evidence (credential receipt, OpenAPI version hash, a passing contract-test run against sandbox, simulated end-to-end STK + C2B flows) in `docs/integrations/wallet/gate-w-evidence.md`. If Gate W is not open when 20C exits: proceed 20E→20F→(20G/20H prep), re-check weekly, and raise a blocking risk in the register if the stall is projected to threaten Phase 25 by more than two weeks.

### Phase 10 — API Foundation (Corrections 10, 11, 12; REM-ROUTE/MIG-001)
- **Objective:** establish the API contract substrate all features inherit.
- **Refs:** Sections 23, 24; Corrections 10–12.
- **Dependencies:** gate closed. **Exclusions:** no business domains.
- **Backend/API:** pagination/filter/sort traits (default 25/max 100, allowlisted sorts, validated/indexed filters); `Idempotency-Key` middleware (Section 24.4); resource `can` maps; route-classification metadata/registry; expand-and-contract **migration manifest** convention (Section 13/ADR-004); OpenAPI generation + TypeScript contract generation.
- **Tests/proof:** `RouteSecurityContractTest`, `FinancialRouteIdempotencyCoverageTest` (passing on the seam routes), pagination/sort/filter tests, OpenAPI/TS parity test, migration-manifest lint.
- **Acceptance/Exit:** every non-GET route has a valid classification + required middleware; financial routes cannot exist without idempotency; contract generated and parity-verified.

### Phase 10F — File and Media Foundation (Correction 13; REM-FILE-001)
- **Objective:** own the file domain before any feature stores/exports files.
- **DB:** `uploaded_files`, `file_scan_events` (Section 13.13).
- **Backend/security:** upload pipeline + ClamAV scanning + signed downloads + authorization (Section 65); jobs `ScanUploadedFile`/`FinalizeCleanFile`/`ExpireSignedExport`/`DeleteExpiredQuarantineFile`/`VerifyOrphanedFileRecords` (idempotent, tenant-aware) on the `file-scanning` queue.
- **Frontend:** upload component states (selecting/scanning/available/rejected); private download via authorized endpoint.
- **Tests/proof:** MIME spoof, double extension, oversize, malware EICAR, cross-tenant/branch download, signed-URL expiry, read-only blocks new export but allows existing download, log redaction.
- **Acceptance/Exit:** a named phase owns schema/pipeline/authorization/jobs/UI states/tests before any feature stores files.

### Phase 11 — UI Layout Foundation and Role Navigation (Correction 22)
- **Objective:** finalize role layouts, navigation, and entry surfaces.
- **Frontend:** verbatim scope-defined role navigation per role; landing + get-started pages per role (Section 27.2) with persisted checklist completion/deep links/resumability/dismissal/reopen; `PermissionGate`-driven visibility (UX only); state boundaries everywhere.
- **Tests/proof:** role navigation matches scope; get-started persistence; responsive/dark/axe on all foundation screens.
- **Acceptance/Exit:** every role has a live landing + guided get-started with real scope content.

### Phase 15A — Services, Catalogue, Clients (Corrections 16, 17, 22)
- **Objective:** Branch-Manager service catalogue + Front-Office client records.
- **DB:** `service_categories`, `services`, `service_personnel_eligibility`, `clients`, `client_consents` (Section 13.7).
- **Backend/API/authz:** branch_mutation routes; Branch Manager owns `service.*`; Front Office owns `client.*`; eligibility management; SMS consent capture; canonical state where applicable.
- **Frontend:** catalogue + eligibility screens (Branch Manager); client create/search/detail (Front Office, masked contact).
- **Tests/proof:** role-boundary (Branch Manager owns catalogue; Front Office cannot mutate catalogue), tenant/branch isolation, consent, masking; responsive/dark/axe.
- **Acceptance/Exit:** catalogue + clients usable with correct ownership and masking; per-screen specs + data-dictionary entries merged.

### Phase 15B — Personnel Availability and Eligibility Completion (Corrections 16, 17)
- **DB:** `personnel_availability`. **Backend:** HR manages availability/eligibility (`personnel.availability.manage`, `personnel.eligibility.manage`).
- **Tests/proof:** availability drives scheduling validation; isolation; role boundaries.
- **Acceptance/Exit:** eligibility + availability complete and enforced in scheduling.

### Phase 16A — Appointments (Corrections 16, 17, 22)
- **DB:** `appointments`. **Backend:** Front Office appointment machine (Section 25); eligibility/availability/branch-open revalidation on assign/transfer.
- **Frontend:** appointment create/reschedule/cancel/assign/transfer screens; mobile-usable.
- **Tests/proof:** valid-transition-only, invalid-transition denial, eligibility/availability revalidation, isolation, audit; responsive/dark/axe.
- **Acceptance/Exit:** appointment lifecycle correct and audited.

### Phase 16B — Walk-Ins and Queues (Corrections 16, 17, 22)
- **DB:** `walk_ins`, `queue_entries`. **Backend:** queue machine; Front Office transfer; Personnel cannot access others' entries.
- **Frontend:** queue board + assignment/transfer/reorder; **mobile-usable** (critical flow).
- **Tests/proof:** queue transitions, transfer authority, own-scope denial, position integrity under concurrency, audit; responsive/dark/axe.
- **Acceptance/Exit:** queue operations correct, isolated, and mobile-usable.

### Phase 16C — Service Sessions and Preferred Personnel (Corrections 16, 17)
- **DB:** `service_sessions`. **Backend:** session machine; eligibility + branch-assignment per service item; duplicate-active-session protection; preferred-personnel selection; session completion creates non-payable commission **preview** only.
- **Tests/proof:** eligibility enforcement, duplicate-active protection, preview-not-earned, isolation, audit.
- **Acceptance/Exit:** sessions correct; preview commissions never payable pre-validation.

### Phase 17 — Invoicing (Corrections 1, 9, 17; financial)
- **DB:** `invoices`, `invoice_items`, `invoice_number_sequences` (Section 13.8/13.15). **Dependency:** preferred-personnel-fee resolution reads `preferred_personnel_fee_rules` (Phase 20A); until that table exists, the legacy fixed `services.preferred_personnel_fee_minor` is used. **Backend:** Front Office creates; Finance voids/adjusts; finalization allocates a gap-free per-merchant number from `invoice_number_sequences` + snapshots prices/percentage-fee config and the **resolved effective preferred-personnel fee** (fixed or percentage, round-half-up; never recalculated after finalization); balance from validated payments; period-lock 423; financial_mutation idempotency on finalization.
- **Frontend:** invoice create/detail; read-only/locked/suspended-billing states.
- **Tests/proof:** finalization snapshot stability, number allocation, void-creates-adjustment, period-lock denial, idempotent finalization, isolation, audit; responsive/dark/axe.
- **Acceptance/Exit:** invoices finalize deterministically with snapshots and audit; no destructive edits.

### Phase 18A — Merchant-Client Payment Recording (Correction 18; financial)
- **Objective:** Front-Office maker recording across all methods.
- **DB:** `payment_recording_groups`, `payment_records` (+ `payment_recording_group_id`), `payment_allocations`, `payment_reference_checks`, `invoice_number_sequences` (Section 13.8/13.15).
- **Backend/security:** durable recording group (single, split, or multi-method); group total = sum(components) + single-currency enforcement; method set (cash/mpesa_offline/bank_transfer/card_terminal/voucher/split_payment/other); method-specific reference rules; durable duplicate-reference detection in `payment_reference_checks`; overpayment rejected by default; partial/split allocation with invoice row lock + pending-total check; idempotency keyed on the group; records `pending_validation`; Finance notified.
- **Frontend:** payment record form (method-aware), split/multi-method group builder, masked references; confirmation with readable amounts.
- **Tests/proof:** group total = sum(components), single-currency enforcement, per-method reference behavior, `payment_reference_checks` duplicate+override, partial, split/multi-method, concurrent recording, maker-cannot-self-validate, locked-period denial, billing read-only denial, cross-tenant/branch denial, idempotent replay, audit.
- **Acceptance/Exit:** all methods record correctly within a durable group with maker/checker separation preserved.

### Phase 18B — Validation, Receipts, Refunds, Disputes, Cash-Up, Period Locks (Correction 18; financial)
- **DB:** `payment_validation_events` (group-level), `receipts`, `receipt_number_sequences`, `refunds`, `finance_disputes`, `branch_cash_ups`/`cash_up_lines`, `financial_period_locks`, `finance_exports`.
- **Backend/security:** Finance validation of the **whole payment recording group** (atomic, locked; one immutable event for the group; validated-paid update; invoice status; one auto receipt covering all components with a gap-free `receipt_number_sequences` number; per-component earned commission seam to 20G; outbox-guaranteed side effects); receipts (one per validated group, reissue tracking via new receipts row); refunds (external record, component-allocated, adjustment/reversal only, proportional commission reversal, step-up on finalize); disputes; cash-up (Branch Manager submit, Finance approve, maker≠checker, lock); period locks (Finance owns; Merchant-Admin exceptional reopen; 423); finance exports (async, scoped, masked, signed, expiring, download-counted, audited — files via 10F).
- **Frontend:** Finance task inbox; group validation/duplicate-review; receipts/reissue; refunds/disputes; cash-up approval; period locks; exports.
- **Tests/proof:** group validation atomicity + rollback-on-side-effect-failure, one-receipt-per-group with gap-free numbering, receipt reissue references original, refund reduces balance via adjustment + per-component commission reversal, dispute lifecycle, cash-up maker≠checker, period-lock denial, export masking + expiry + download count, step-up on designated actions, concurrency, isolation, audit; responsive/dark/axe.
- **Acceptance/Exit:** money lifecycle is auditable, group-validated, locked-period-safe, maker/checker-separated, and never destructively edited.

### Phase 19 — Audit Logging Completion and Flagged Events (Corrections 16, 22)
- **DB:** `audit_flagged_events`, `audit_exports` (async, reason-gated, permission-masked, signed/expiring, download-counted Audit export — product-owner decision 2026-07-04 resolving REM-AUDEXP-001; §13.5 DDL; the Phase 19 Audit-export build, with Phase 23 remaining final release-wide export **hardening**, not the initial implementation). **Backend:** complete audit coverage across all financial/billing/compensation/SMS/file/export events (integration audit events land with their owning phases 20D‑W/21R‑A/21R‑B); flagged-event workflow (open→under_review→resolved/dismissed→reopened; only review metadata mutable); branch-scoped, field-masked Audit reads; the branch-scoped, reason-gated, step-up, masked Audit export (never exporting merchant-level branch-null rows; download-counted on the authorized stream; files via 10F); Audit role updates only review/export-request metadata; chain-verification scheduled (Section 67) + alert (Section 71).
- **Tests/proof:** event coverage for every mutating action, tamper-verification, masked-read enforcement, Audit cannot mutate source records, flagged-event lifecycle.
- **Acceptance/Exit:** every mutating action emits a typed, severity-tagged, chain-verified event; Audit role is provably read-only except flagged-event metadata.

### Phase 20A — Plan Catalogue, Prices, Entitlements, Billing Settings (Corrections 2, 4, 8; ADR-011; platform)
- **DB:** `platform_billing_settings`, `subscription_plans`, `subscription_plan_prices`, `plan_entitlements` (Section 13.9); `preferred_personnel_fee_rules` (§13.10, launch-active; expand-and-contract from `services.preferred_personnel_fee_minor`).
- **Backend/authz:** Super-Admin platform_mutation (MFA + step-up); canonical billing-mode enum across PHP/DB/API/TS/seed/audit; the five canonical billing intervals (`weekly`/`bi_weekly`/`monthly`/`quarterly`/`annual`) across enum/DB CHECK/API/TS/screens; price as sole source with non-overlapping effective ranges; entitlement gate (Section 20); merchant pricing read models.
- **Tests/proof:** canonical-enum parity (mode + interval), price-overlap rejection, entitlement allow/deny/limit, platform-only access, no merchant-context insertion, audit; preferred-personnel-fee rule fixed/percentage validation, overlap rejection, supersede-not-edit, percentage round-half-up, invoice snapshot stability (rule change does not recalculate existing invoices), legacy-field migration equivalence.
- **Acceptance/Exit:** plans/prices/entitlements/settings managed with canonical modes, all five intervals, entitlement enforcement, and launch-active fixed+percentage preferred-personnel-fee rules.

### Phase 20B — Subscription Lifecycle and Subscription Invoices (Corrections 2, 8; financial)
- **DB:** `merchant_subscriptions` (record lifecycle on `status`), `scheduled_plan_changes`, `subscription_invoices` (including the nullable Wallet forward-compatibility columns `wallet_payment_id`, `wallet_registration_status` default `'unregistered'`, `wallet_registered_at`, and nullable-until-registered `account_reference` — §13.9/ADR‑014; populated in 20D‑W), `subscription_invoice_items`, `billing_escalation_events`; project `merchants.billing_status` from the active subscription (Section 22).
- **Backend:** billing-status machine on `merchants.billing_status` projected from `merchant_subscriptions.status` (Section 22/25); transactional projection service; per-interval date math (§49); trial at Merchant-Admin creation (snapshotted days); read-only grace + suspension gates; no-proration next-cycle changes; shared overdue escalation seam (20-/scheduler); invoice issuance (immutable, snapshotted discounts/free periods; issuance enqueues a `RegisterInvoicePayment` outbox intent — a no-op until 20D‑W lands the Wallet client — and the invoice PDF renders payment instructions only once registered, with regeneration after registration per §49); billing-invoice PDFs via 10F; finalization idempotent.
- **Frontend:** subscription dashboard; plan management + scheduled change; invoices/detail/download.
- **Tests/proof:** projection synchronizes `merchants.billing_status` from subscription transitions transactionally and is the gate authority; per-interval next-date/renewal math incl. Jan-31/Feb-29/year-boundary; trial start/snapshot, read-only behavior (mutations blocked, reads allowed, new exports blocked), no-proration change at next cycle, invoice immutability, isolation, audit; responsive/dark/axe.
- **Acceptance/Exit:** subscription + billing invoices correct with read-only grace, `merchants.billing_status` as access authority, all-interval date math, and immutable issuance.

### Phase 20C — Promotions and Free-Period Offers (Correction 2; platform)
- **DB:** `promotional_discounts`(+targets), `free_period_offers`(+targets).
- **Backend:** explicit target rows (no JSON targets); snapshot application; approval + audit; trial-snapshot immutability.
- **Tests/proof:** target resolution for merchant/plan/**billing_mode** types, exactly-one-target constraint, **precedence/tie-breaking determinism**, snapshot does not mutate issued invoices or existing trials, approval/audit, platform-only access.
- **Acceptance/Exit:** promotions/free periods apply via snapshots without rewriting issued financial state.

### Phase 20D‑W — Wallet by Citrus Billing-Payment Integration (Corrections 3, 14, 15 as amended by ADR‑012; financial; REPLACES the v3 Phase 20D)
- **Objective:** merchants pay Servana subscription invoices through Wallet (STK + PayBill/Till structured references) with verified webhook settlement, exactly-once application, overpayment credit, billing-only recovery, reversal handling, reconciliation exceptions, and full auditability — with zero provider logic in Servana.
- **Refs:** §§8.1 (ADR‑012/014/015), 10.1, 13.11, 17.1, §§55–58 + 58.1, 12.1, 24.1, 67, 70–71, 75.1, 77.1; Wallet scope §§20–22, 34–37; superseded v3 §§55–58 (SUP‑01…06).
- **Entry criteria:** 20B complete; the §1.3 plan-adoption PR merged; Gate W (§80.2) open in sandbox.
- **DB:** `wallet_merchant_account_links`, `subscription_payment_attempts` (revised shape), `subscription_payments` (one aggregate per Wallet payment, Correction 14.10), `subscription_payment_receipts` (append-only receipt child rows), `subscription_payment_reversals`, `wallet_webhook_inbox`, `billing_reconciliation_exceptions`, `subscription_invoice_payment_locks`, `merchant_billing_credits`; populate the 20B-shipped `subscription_invoices` Wallet columns (§13.11).
- **Backend/security:** data-dictionary entries → migrations → models/factories/seeders → Wallet client + DTOs + signature verifier (§10.1) → actions with the §56.1 sequencing and §57 algorithm → §67 jobs → routes/policies/permissions (§19) → OpenAPI + TS regeneration; §9 rules 20–24 implementation; redaction §24.5; static-analysis guards §11; environment boot guards §77.1.
- **Frontend:** §12.1 items 1–4 (payment states, instructions panel with `instructions_pending`, Billing Reconciliation screen, Integrations Health screen).
- **Tests/proof:** the entire §75.1 Wallet suite + contract tests against sandbox (run commands recorded); sandbox end-to-end evidence (STK success, cancel, timeout+late callback, duplicate event, partial, overpayment credit, reversal, unknown-payment exception, recovery) with correlation-ID traces; DB queries proving allocation invariants; audit-chain verifier pass; OpenAPI diff reviewed.
- **Acceptance/Exit:** all §58.1 cases pass; no manual recording path; no provider symbols (guard test); webhook ack p95 < 250 ms in a sandbox load test; docs/PROGRESS/CHANGELOG/traceability updated.
- **Risks & rollback:** integration-contract drift (pinned OpenAPI hash + contract tests); Wallet sandbox instability (`FakeWalletClient` keeps CI deterministic; sandbox runs are a separate gate job); rollback = revert PR pre-production; production correction is forward-repair per ADR‑004 (no destructive `down()`).

### Phase 20E — Percentage Platform-Fee Engine (Corrections 2, 4, 8; financial)
- **DB:** `platform_fee_configurations`, `platform_fee_ledger_entries`, `platform_fee_adjustments`, `platform_fee_disputes` (§13.10).
- **Backend:** compute fees from validated merchant-client invoice amounts; tier behavior (customer/shared/business-centric); integer arithmetic + round-half-up + largest-remainder residual (ADR-005); aggregate into subscription-invoice lines; adjustments + platform-fee disputes (resolution creates adjustments, never edits ledger rows); **no entries created in fixed-only mode** (tested); launch-inactive until configured.
- **Tests/proof:** fixed-only creates no fee entries; percentage/fixed-plus-percentage compute correctly; rounding determinism; reversal on invoice void/refund; isolation; audit.
- **Acceptance/Exit:** engine is launch-capable, correct, and inert unless a percentage component is configured. (Platform-fee rollups flow into subscription invoices, which are Wallet-payable like any other — no additional Wallet work in this phase.)

### Phase 20F — Compensation Plan Setup and Commission Rules (Correction 19; HR)
- **DB:** `personnel_compensation_plans`, `compensation_plan_history`, `commission_rules`.
- **Backend:** three compensation models with model-specific validation (no cross-model ledgers); effective-dated plans/rules with overlap exclusion constraints; immutable active monetary terms (supersede); backdated change approval + critical audit; preferred-personnel-fee applicability; configuration grants no login/role/branch.
- **Tests/proof:** model validation, overlap rejection, supersede-not-edit, backdated approval/audit, salary-only has no commission rule, isolation.
- **Acceptance/Exit:** compensation setup correct, effective-dated, and immutable where required.

### Phase 20G — Salary Accrual and Commission Processing (Correction 19; financial)
- **DB:** `salary_ledger`, `commission_ledger`, `compensation_adjustments`.
- **Backend:** scheduler salary accrual (idempotent per segment; attendance-backed sub-monthly; effective-date proration; settled suspension policy; termination handling); commission earned **only** at Finance validation (idempotent earned entries; effective-date rule resolution; integer + ADR-005 rounding); refund/void/reversal creates negative reversal/adjustment; salary-only never earns commission.
- **Tests/proof:** accrual idempotency + proration + suspension policy, earn-at-validation, one entry per validation allocation, reversal on refund/void, salary-only exclusion, concurrency, isolation, audit.
- **Acceptance/Exit:** ledgers correct, idempotent, reversible-by-adjustment, never destructively edited.

### Phase 20H — Payout Runs and Earnings (Correction 19; financial)
- **DB:** `personnel_payout_runs`, `personnel_payout_items`, `earnings_queries`.
- **Backend:** HR draft/submit; Finance verify/approve-standard/mark-paid; Merchant-Admin high-value approval (snapshotted threshold); frozen-on-submit; mark-paid (external ref + paid date + Finance + fresh step-up + idempotency + row lock + ledger status + notification); personnel earnings (own-scope tabs by model, statements via 10F); earnings queries (assignment by type; resolution via adjustment only).
- **Frontend:** HR payout prep; Finance verification/approval/mark-paid; Merchant-Admin high-value approval; personnel My Earnings + statements + queries.
- **Tests/proof:** ownership routing, high-value approval routing, frozen-on-submit, mark-paid idempotency + step-up, ledger status updates, own-scope earnings, query-resolution-creates-adjustment, isolation, audit; responsive/dark/axe.
- **Acceptance/Exit:** payouts flow HR→Finance→(Merchant-Admin high value)→paid with correct ownership, freezing, and personnel visibility.

### Phase 21R‑A — Citrus Refer & Earn Integration Foundation (ADR‑013; NEW)
- **Objective:** Servana becomes a signing source product: referral capture at registration (non-blocking), code validation + attribution confirmation, transactional outbox, signed delivery pipeline, and merchant-lifecycle event emission.
- **Refs:** §§8.1 (ADR‑013/015), 10.1, 13.17 (`referral_snapshots`, `re_outbound_events`, `re_event_deliveries`), 17.1, 25.6, 58A, 58B.1 (merchant.* rows), 58B.5 (R‑01…R‑09, R‑21, R‑22), 75.1, 77.1; R&E dev plan §11.7–§11.8.
- **Entry criteria:** 20B complete (merchant lifecycle facts stable); the §1.3 plan-adoption PR merged; R&E sandbox service-account credentials received (record in `docs/integrations/refer-earn/credentials-receipt.md`; if the R&E sandbox is unavailable, implement against `FakeReferEarnClient` + recorded contract fixtures and mark a deferred-verification item that must close before Phase 25). Parallel-eligible with 20C–20E (§80.1).
- **Backend:** data-dictionary entries → migrations → `CitrusEventSigner` (exact canonical string; test vectors) → outbox insert/dispatch/delivery with §58A.2 response handling → `CaptureReferralSnapshot` wired into the existing self-register action as an additive step (inspect the Phase 6 as-built code first; prove the insertion point; smallest correct change; the Phase 6 regression suite must stay green) → `ValidateReferralCode`/`ConfirmAttribution` jobs → merchant.* event emission from existing status-transition seams.
- **Frontend:** §12.1 item 5 (registration `?ref=` + notice); the R&E panel of the shared Integrations Health screen (§12.1 item 4).
- **Tests/proof:** §75.1 `ReferralCaptureTest`, `AttributionLifecycleTest`, `OutboxEmissionTest`, `OutboxDeliveryTest` + signer unit vectors.
- **Acceptance/Exit:** registration latency unchanged within noise (measured); outbox atomicity proven (fact rollback ⇒ no event row); delivery retry/dead-letter demonstrated against sandbox or fixtures; no referrer PII anywhere (schema assertion); audit events present.
- **Risks:** R&E credential/product-code assignment delay (proceed with fixtures; deferred-verification item); registration regression (mitigated by as-built inspection + regression suite).

### Phase 21R‑B — Subscription Events, Activity Qualification, Reconciliation Surface (ADR‑013; NEW)
- **Objective:** emit all subscription financial facts for attributed merchants; run the final-authority monthly qualification engine; expose the signed inbound reconciliation endpoint; close the gap-reconciliation loop.
- **Refs:** §§13.17 (rule/period/decision/inbound tables), 25.6, 58A.2, 58B.1–58B.5 (R‑10…R‑20), 67, 75.1; R&E scope §0.5, §11.
- **Entry criteria:** 21R‑A complete; 20B complete; 20D‑W complete (payment received/cleared sources); 16C + 18B facts available (already built).
- **Backend:** rule-version table + seeded launch rule (10 sessions / 3 validated invoices / paid / clear; calendar month; grace 5 days) → subscription.* emission hooks in issuance/apply/reversal/plan-change/suspension transitions (each inside its owning transaction) → the clearing evaluator in the nightly job → the qualification engine per §58B.3 (set-based) → corrections + the platform-correction permission flow → the inbound reconciliation controller + query classes + replay store → `ReconcileReEventGapsJob`.
- **Frontend:** platform qualification-decisions read screen (under Integrations Health); correction dialog (reason + step-up).
- **Tests/proof:** §75.1 `SubscriptionEventMappingTest`, `QualificationEngineTest`, `InboundReconciliationTest`, `GapReconciliationTest`; cross-phase test: W‑18 reversal → R‑13 correction chain.
- **Acceptance/Exit:** every §58B.1 mapping proven exactly-once; boundary and category determinism proven; decision immutability + versioning proven; end-to-end demo: a referred merchant registers → subscribes → pays via the Wallet sandbox → the month closes → `qualification_decided` is delivered and visible in the R&E sandbox (or fixture-verified with a deferred live check).
- **Risks:** clearing-rule ambiguity vs R&E reward expectations (the §58B.1 clearing rule is documented in the shared integration contract and confirmed with the R&E owner **before** implementation — a blocking ambiguity is recorded if unconfirmed); month-boundary date math (dedicated tests incl. the year boundary).

### Phase 21N — Queues, Notifications, Scheduled Reports (Corrections 21, 24)
- **DB:** `notifications`, `scheduled_report_runs`.
- **Backend:** Horizon + class-separated workers including the integration queues, and the central scheduler inventory including the §67 integration schedule; branded notifications (no secrets/masked PII; recipient authorization) including the §66 integration notification rows; report catalogue read models/materialized views (Section 69); scheduled day-close + cash-up PDFs (idempotent per branch/date/type; private storage; email only authorized Merchant Admin; no new generation during billing read-only while existing remain downloadable).
- **Tests/proof:** job idempotency + tenancy, notification recipient authorization, report formula correctness (partial/split/refund), PDF idempotency, read-only behavior, masking, large-dataset performance.
- **Acceptance/Exit:** queues/notifications/scheduled reports operate idempotently with correct authorization and formulas.

### Phase 21S — Personnel Bulk SMS (Correction 20; ADR-010)
- **DB:** `personnel_sms_campaigns`, `personnel_sms_recipients`, `sms_delivery_attempts`, `sms_billing_entries`.
- **Backend/security:** served-client own-scope + consent + entitlement + billing-status gating; recipient revalidation at preview + confirm; cost preview; transactional snapshots; provider adapter + redaction; retry transient only; dedupe by campaign-recipient; SMS billing roll-up; **no contact export channel**; guessed export routes → 404 + high-severity audit.
- **Frontend:** served-clients view (masked), recipient selection (max batch), composer (char/segment + cost), confirmation, status (no phone list).
- **Tests/proof:** Correction 20.8 list (cannot view/message others' clients, no completed-session no message, no export, billing/entitlement gates, cost-preview accuracy, duplicate-confirm single send, opt-out suppression, cross-tenant/branch denial, no full-phone exposure, log redaction).
- **Acceptance/Exit:** personnel SMS works without ever becoming a contact-export surrogate.

### Phase 22 — Search (Correction 16; security)
- **Backend:** tenant/branch-scoped, permission-aware indexing; never index/return cross-tenant data; never cache unscoped + filter client-side; own-scope masked served-client search + rate limiting; allowlisted sort/filter. No integration tables are indexed (they contain no searchable business content; explicit exclusion recorded, consistent with the R&E rule never to index integration payloads).
- **Tests/proof:** cross-tenant/branch exclusion, own-scope masking, rate limiting, injection-safe queries.
- **Acceptance/Exit:** search is scoped, masked, and isolation-safe.

### Phase 23 — Security Hardening, Responsive/Dark/Accessibility Release Audit, Threat-Model Verification (Corrections 8, 9, 16, 23)
- **Objective:** whole-product release gates after all launch screens exist.
- **Work:** run the per-workflow attacker-model verification (Section 9.1/73), extended to the §9.1 integration scenarios; the pen-test checklist adds webhook forgery/replay and outbox tamper cases; whole-product responsive audit (no horizontal scroll across launch screens), dark-mode audit, and accessibility audit (axe + manual for critical flows); finalize finance/audit export controls; confirm forbidden routes absent (Super-Admin merchant creation, personnel contact export, any `*/mpesa/*` route); complete the requirement traceability matrix (Section 85) and enforce it in CI.
- **Tests/proof:** security-regression suite, responsive/dark/axe across all launch screens, traceability coverage report (no launch requirement without complete mapping), forbidden-route absence tests.
- **Acceptance/Exit:** all release gates pass; traceability is complete; no forbidden capability exists.

### Phase 24 — Performance Optimization (Correction 24.2)
- **Work:** index/query review (no N+1; tested), report read-model/materialized-view tuning, cache key correctness (scoped), opcache/preload, list/report load tests on large datasets; verify p95 targets (Section 72).
- **Tests/proof:** performance tests meet read/write/report targets; N+1 guards; cache-scoping tests.
- **Acceptance/Exit:** measured p95s within Section 72 targets on representative data.

### Phase 25 — Deployment Pipeline and Production Readiness (Correction 24)
- **Work:** production topology (Section 77); expand-and-contract deploy with migration-before-switch + readiness gate + graceful worker drain + scheduler/Horizon coordination; smoke tests; observability + alerts (Section 71); backup + PITR + restore exercise (Section 78); runbooks + incident severities + on-call; secrets management; certificate/secret-expiry alerts; Gate W production re-verification (§80.2); a credential-rotation drill (one full rotation per §77.1 executed in staging for all four machine identities); an integration-alert dry-run; closure of any R&E deferred-verification item (21R‑A entry criteria).
- **Tests/proof:** deployment smoke tests, readiness-gated rollout, restore exercise evidence (boot + tenant counts + financial totals + audit-chain verify + sample downloads, with RTO timing), alert firing tests.
- **Acceptance/Exit:** production deploy is repeatable, observable, recoverable, and meets the measurable production requirements; 20D‑W and 21R‑B are complete (§80.1 launch rule); the final production verification checklist (Section 86) passes.

---

## 81. IDE-Agent Execution Protocol
The implementation agent must, for every phase:
1. Read the complete owning phase before changing code.
2. Read the linked authoritative scope sections in `SERVANA COMBINED.txt`.
3. Inspect the current repository (migrations, `route:list`, policies, services, components, tests, lock files, CI).
4. Prove the current state with commands and evidence.
5. Identify the root cause of every discrepancy before editing.
6. Produce a file-level implementation checklist.
7. Implement only the scoped phase.
8. Avoid unrelated changes and refactors.
9. Add or update tests before declaring completion.
10. Run the complete relevant quality suite (Section 75).
11. Produce the phase's proof artifacts.
12. Update `PROGRESS.md`, `CHANGELOG.md`, the traceability matrix, and any ADRs.
13. Stop when acceptance criteria fail.
14. Never mark a task complete based solely on compilation.
15. Never bypass a failing test by deleting, weakening, skipping, or suppressing it without an approved documented reason.
16. Never weaken security or tenant isolation to make a test pass.
17. Never infer a missing business rule — locate it in scope or record a blocking ambiguity.
18. Never implement a future-only placeholder for a launch requirement.
19. Never expose sequential internal identifiers through public APIs.
20. Never allow a frontend permission check to replace backend enforcement.
21. Never call live partner systems (Wallet, R&E) from CI or unit tests. Fakes/fixtures only; sandbox verification is a separate, explicitly-invoked gate job with recorded evidence.
22. Inspect before wiring: before adding any emission hook into an existing transition (registration, issuance, apply, suspension), open the as-built action, quote the exact insertion point in the PR description, and prove via the existing tests that behavior is preserved.
23. Contract pinning: record the Wallet OpenAPI hash and R&E schema versions in `docs/integrations/*/contract-pins.md`; any pin change is its own reviewed commit.
24. Blocking ambiguities for the integration phases (stop and record; do not guess): the exact Wallet event-type names/payload fields (resolve at Gate W from the Wallet OpenAPI); the R&E-assigned product code for Servana (`SRV` assumed); the R&E confirm-window for attribution expiry; whether R&E sync of campaign rule versions is available at launch (else `source='platform_config'` manual entry is used, which is already supported); confirmation of the §58B.1 `payment_cleared` clearing rule with the R&E owner before 21R‑B implementation.
25. For integration defects, the bug-fix "Evidence" requirement includes correlation-ID traces across both systems where available.

## 82. Phase-Level Acceptance Criteria (every phase)
A phase is complete only when: its objective is met; all required tests (Section 7 + 75) pass in clean containers; the per-feature responsive/dark/accessibility gate passes; required proof artifacts exist; data-dictionary entries, screen specs, state machines, and permission-matrix reconciliation for the phase are merged; the traceability matrix is updated; `PROGRESS.md`/`CHANGELOG.md` reflect actual commits/CI; no C0/C1 regression is introduced; and a reviewer approves. Blocking conditions for progression: any failing acceptance test; any unresolved authoritative ambiguity; any missing spec deliverable; any security/isolation/financial-integrity regression; any forbidden capability introduced.

## 83. System-Level Acceptance Criteria (launch)
Launch requires, in addition to all phase exits:
- Pre-feature remediation gate closed (Section 5.4) and every FEATURE_DELIVERY_OBLIGATION satisfied at its owning phase (Section 5.4a); as-built verification truthful (Phase V).
- Every launch requirement in `SERVANA COMBINED.txt` mapped to an implemented, tested phase in the traceability matrix (Section 85) with no gaps.
- Tenant, branch, own-scope, entitlement, billing-status, operational-status, and period-lock controls enforced server-side and tested.
- Subscription-first billing, Wallet-orchestrated subscription payment + verified-webhook settlement + reconciliation + billing-only recovery, merchant-client payment lifecycle, compensation + payouts + earnings, personnel SMS, files, notifications, reporting, and audit all complete with passing tests.
- Refer & Earn integration complete: non-blocking referral capture; atomic outbox with the exact signing contract; all 17 required event types mapped and proven exactly-once; final-authority qualification with append-only versioned decisions; data-minimized payloads; replay-safe inbound reconciliation. End-to-end: a referred merchant can register with a code, subscribe, pay via Wallet, and qualify after a month of real activity facts — every step auditable, tenant-isolated, idempotent, and reconstructable from correlation IDs, with zero raw provider data and zero referrer PII inside Servana.
- Zero direct provider integration: no provider credentials, callbacks, or reconciliation code in the repository (§9 rule 20 guards green); Gate W re-verified in production (§80.2).
- No manual Super-Admin payment-recording path; no merchant-creation path other than self-registration; no personnel contact-export channel; Merchant Admin not an operational superuser; role boundaries (Branch Manager/HR/Finance/Front Office/Personnel/Audit) enforced.
- Financial integrity: integer money, transactions, locks, idempotency, immutable ledgers, reversal/adjustment corrections, maker/checker, audit chain verified.
- Measurable production requirements met (Sections 72, 77, 78); observability + alerts live; restore exercise passed.
- Accessibility (WCAG 2.1 AA), responsive, and dark-mode release audits passed across all launch screens.

## 84. Risk Register
| ID | Risk | Likelihood | Impact | Mitigation | Owner |
|---|---|---|---|---|---|
| RK-01 | Forged/replayed Wallet webhooks | Low | High | Mandatory algorithm-aware signature verification before parse and before any canonical storage (§9 rule 21, ADR-015); unique first-seen `wallet_event_id` replay protection after verification; uniform 401 + audit | Backend/Security |
| RK-02 | Unapplied confirmed Wallet payments / reconciliation backlog | Med | High | Exception queue + alerts + Super-Admin linking workflow; stale-attempt status queries; nightly allocation reconciliation | Backend |
| RK-03 | Commission/payout double-count or destructive correction | Med | High | Idempotent ledger entries; reversal/adjustment-only; frozen-on-submit; maker/checker; audit | Finance/Backend |
| RK-04 | Cross-tenant/branch leakage via new models | Med | Critical | Global scopes + scoped binding + coverage tests + static analysis | Backend |
| RK-05 | Personnel contact exfiltration via SMS/search | Med | High | Own-scope + masking + no-export + rate limit + enumeration detection + 404/audit | Backend/Security |
| RK-06 | Billing read-only/suspension bypass | Low | High | Billing-status gate + recovery allowlist + tests | Backend |
| RK-07 | Idempotency gaps on financial routes | Low | High | Middleware + `FinancialRouteIdempotencyCoverageTest` | Backend |
| RK-08 | Migration rollback expectations rely on destructive down() | Low | High | Expand-and-contract + forward-repair + manifest (ADR-004) | DevOps/DB |
| RK-09 | Framework advisory left unpatched | Low | High | R1 upgrade + remove ignore + CR/LF tests | Backend |
| RK-10 | File-upload malware/polyglot | Low | High | Magic-byte detection + ClamAV + private signed downloads (10F) | Security |
| RK-11 | Audit-chain break undetected | Low | High | Verifier command + scheduled run + alert | Backend |
| RK-12 | Scheduler missed billing/salary transitions | Low | High | Singleton scheduler + idempotent jobs + lag alerts | DevOps |
| RK-13 | Report metric drift vs. validated-payment definitions | Med | Med | Catalogue formulas + tests with partial/split/refund | Backend/Product |
| RK-14 | Permission registry drift from canonical matrix | Med | Med | YAML↔code↔DB↔TS parity test | Backend |
| RK-15 | Progress files overstating completion | Med | Med | Phase V verification + commit/CI-linked progress | Lead |
| RK-16 | Wallet Servana slice late → launch-blocking | Med | High | Sequencing A‑15; weekly gate check; escalation at T‑2 weeks; 20E/20F fill the gap | 20D‑W |
| RK-17 | Wallet contract drift after pinning | Med | Med | Contract pins + CI contract tests + versioned webhooks | 20D‑W |
| RK-18 | Dual financial truth (allocation drift between Servana and Wallet) | Low | High | Nightly reconciliation + drift exceptions + clearing gate (§58B.1) | 20D‑W |
| RK-19 | Wallet webhook secret leak | Low | High | Secrets custody (§9 rule 24); rotation runbooks; dual-key window; anomaly alerting | 20D‑W |
| RK-20 | R&E credentials/product code delayed | Med | Med | Fixture-first build + deferred-verification item closed before Phase 25 | 21R‑A |
| RK-21 | Clearing-rule mismatch with R&E reward expectations | Med | Med | The §58B.1 rule is written into the shared integration contract and confirmed before build (§81 rule 24); the corrections pathway absorbs residual timing | 21R‑B |
| RK-22 | Event flood / outbox backlog | Low | Med | Depth/age alerts; batch dispatch; per-merchant partitions | 21R‑B |
| RK-23 | Qualification dispute (referrer contests a decision) | Med | Low | Evidence checksums + append-only decisions + the reconciliation API give R&E a complete audit trail | 21R‑B |
| RK-24 | Scope creep: building Wallet/R&E platform features inside Servana | Med | High | §9 rule 20 guard; §2.2 matrix as a review checklist; PR review rule (§0 item 12) | all |

## 85. Requirement Traceability Matrix
Create `/docs/traceability/servana-requirements.csv`. Every launch requirement maps to: `scope_section | requirement_id | description | phase | db_objects | service_or_action | controller_or_endpoint | policy_and_permission | frontend_route_and_component | queue_or_scheduler | audit_event | automated_tests | manual_verification | status | evidence`. Stable requirement IDs use domain prefixes, e.g. `SRV-AUTH-*`, `SRV-TEN-*`, `SRV-BRANCH-*`, `SRV-STAFF-*`, `SRV-CAT-*`, `SRV-CLIENT-*`, `SRV-SCHED-*`, `SRV-INV-*`, `SRV-PAY-*`, `SRV-RCPT-*`, `SRV-REF-*`, `SRV-CASH-*`, `SRV-LOCK-*`, `SRV-PLAN-*`, `SRV-SUB-*`, `SRV-BILL-*`, `SRV-FEE-*`, `SRV-WAL-*` (Wallet integration; replaces the retired `SRV-MPESA-*` prefix), `SRV-RE-*` (Refer & Earn integration), `SRV-COMP-*`, `SRV-PAYOUT-*`, `SRV-EARN-*`, `SRV-SMS-*`, `SRV-FILE-*`, `SRV-NOTIF-*`, `SRV-REPORT-*`, `SRV-AUDIT-*`, `SRV-OPS-*`. CI enforcement: a traceability test parses the CSV and fails when a launch requirement has no phase, no test reference, or status `not_implemented` at the Phase 23 gate; the final verification phase fails if any launch requirement lacks complete traceability. The matrix is updated in every feature-phase PR. Integration mappings required: Wallet scope §§20.2/20.3/21.1/21.2–21.3/22.1–22.2/34/35/36.1/37 → this plan §§13.11/25.4/8.1(ADR‑014)/56.1/56.2/9(rule 21)/24.1/75.1; R&E scope §§0.2/0.4/0.5/2.3/2.5/2.8/11.2/11.4/11.5 and R&E dev plan §§11.7/11.8 → this plan §§8.1(ADR‑013)/58A–58B/13.17/9(rule 23)/58B.3/58A.2/75.1; superseded v3 §§55–58/13.11 → §§55–58/13.11 with SUP references (§1.2). Every §58.1 and §58B.5 edge case maps to a named test in §75.1.

## 86. Final Production Verification Checklist (executable)
Run as the launch gate; each item must produce evidence.
1. Remediation gate file shows all C0/C1 `verified_complete`; remediation completion report signed.
2. Phase V discrepancy register shows no open `contradicted` items; `PROGRESS.md` statuses are commit/CI-linked.
3. `composer audit`, `npm audit`, `gitleaks`, and image scans clean (or approved time-bound suppressions with guard tests); framework is Laravel 12.60+ on PHP 8.3 across all images.
4. Full suites green in clean containers on PostgreSQL 16 + Redis 7 (backend, frontend, e2e + axe), run repeatedly/parallel; skipped tests enumerated with reasons.
5. Coverage/parity guards pass: data-dictionary, tenant-column, route-security contract, financial-route idempotency, permission-matrix parity, OpenAPI/TS parity, traceability.
6. `route:list` proves forbidden routes absent (Super-Admin merchant creation; personnel contact export) and correct classification/middleware per class.
7. Tenant/branch/own-scope/entitlement/billing-status/operational-status/period-lock denial suites pass.
8. Financial integrity proofs: idempotent replay, duplicate Wallet webhook, concurrent writes, reversal/adjustment-only corrections, maker/checker separation, audit-chain verification.
9. Wallet billing payments: STK success/cancel/timeout-late-event/duplicate-event/partial/overpayment-credit/unknown-payment/foreign-merchant/billing-only-recovery/non-billing-suspension-stays-blocked/wallet-outage-retry/reversal, with full payload redaction, verified signatures, and no manual recording path (§58.1 sandbox E2E evidence on file).
10. Compensation/payouts/earnings: earn-at-validation, reversal on refund/void, salary accrual idempotency, payout ownership + freezing + step-up mark-paid, personnel own-scope earnings.
11. Personnel SMS proves no contact export and all Correction 20.8 cases.
12. Files: MIME spoof/malware/oversize rejected; signed-download expiry; cross-tenant/branch and personnel-other-statement denied; read-only blocks new export while existing download works.
13. Reports: formula correctness with partial/split/refund; scheduled day-close + cash-up PDFs idempotent; recipient authorization; masking; read-only behavior.
14. Accessibility (WCAG 2.1 AA), responsive (no horizontal scroll), and dark-mode audits pass across all launch screens.
15. Production readiness: topology deployed; expand-and-contract deploy with readiness gate; observability + alerts firing; backup + PITR + restore exercise evidence (boot, tenant counts, financial totals, audit-chain verify, sample downloads, RTO timing); runbooks + incident severities + on-call documented.
16. Traceability CSV complete: every launch requirement mapped with tests and evidence; zero gaps.
17. Plan-adoption PR merged with all §1.3 artifacts; PROGRESS/CHANGELOG carry real commit refs.
18. grep/static guards prove zero provider symbols, zero `*/mpesa/*` routes, zero `services.mpesa.*` config (§9 rule 20).
19. Route contract test: all `/api/v1/integrations/*` routes carry a partner class (§24.1); none carry Sanctum/tenant middleware.
20. Gate W evidence file present (`docs/integrations/wallet/gate-w-evidence.md`); production re-verification recorded before Phase 25 exit.
21. Audit-chain verifier passes over a run containing every §70 integration audit event type.
22. Rotation drill executed in staging for all four machine identities (§77.1).
23. Outbox atomicity proof (fact rollback ⇒ no event row) and 409-mismatch dead-letter proof on file.
24. Qualification demo evidence: decided v1 + corrected v2 chains delivered and acknowledged (sandbox or fixture-verified with a closed deferred-verification item).
25. No integration table exists without a complete data-dictionary entry (`billing-and-wallet.md`, `refer-earn-integration.md`).
26. Redaction audit: sampled logs contain none of the §24.5 partner-secret items under induced-error load.

---

*End of `Servana Software Development Plan.md` (v4). This standalone plan supersedes all prior versions, including v3 and the separate Wallet/R&E amendment document. Phases V, R1–R7, 10–19 are complete; the next work is the §1.3 plan-adoption PR, then Phase 20A. Do not start any feature phase until the pre-feature remediation gate (Section 5.4) is closed (already satisfied) and, for Phase 20D‑W, until External Gate W (§80.2) is open.*
