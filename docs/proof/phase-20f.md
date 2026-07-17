# Phase 20F — Compensation Plan Setup and Commission Rules — Proof

> Lifecycle: **in_progress** (branch `phase-20f-compensation-plan-commission-rules`, based on
> `origin/main` = `c0881993ae0c59536013c9b84e182e5000fa1e11` = the Phase 20E PR #38 merge commit).
> **NOT** `local_complete` / `ci_passed` / `merged` / `verified_complete`. No commit, push, or PR has
> occurred for Phase 20F.
>
> Specification-first, per the Phase 20A/20C/20E precedent: **no migration is created until every
> material gate (F1–F10) is resolved and the data dictionary + state machines are written.**
>
> Controlling sources: **Plan §59** (Compensation-Plan Management, Correction 19), **Plan §80**
> (Phase 20F entry), Plan §10.2 (authority boundaries), Plan §13.1–§13.3 (schema conventions),
> Plan §19.2/§19.3 (permission catalogue/matrix); **Scope §12.1–§12.9** (compensation product rules),
> **Scope §18.3** (compensation entities), Scope §6.x (preferred-personnel fee); ADR-002 (tenancy),
> ADR-004 (forward-only migrations), ADR-005 (integer money, round-half-up).
>
> **Phase 20F is HR/admin configuration only.** It defines *how personnel will earn*. It creates
> **none** of the earned financial facts owned by Phase 20G (`salary_ledger`, `commission_ledger`,
> `compensation_adjustments`, earning at Finance validation) or Phase 20H (payout runs/items,
> earnings statements/queries, mark-paid, the Merchant-Administrator compensation summary), and no
> Wallet/provider runtime (20D-W).

---

## 1. Baseline verification (independent, at start)

All checks executed on `main` **before** any file change.

| Check | Command | Result |
|---|---|---|
| Canonical path | — | `C:\Users\nderu\Documents\Development\Product\Servana` |
| Object integrity | `git fsck --full` | **clean** — no errors, no dangling/corrupt objects |
| Branch | `git branch --show-current` | `main` |
| HEAD | `git rev-parse HEAD` | `c0881993ae0c59536013c9b84e182e5000fa1e11` |
| Remote | `git rev-parse origin/main` | `c0881993ae0c59536013c9b84e182e5000fa1e11` |
| Merge base | `git merge-base origin/main HEAD` | `c0881993ae0c59536013c9b84e182e5000fa1e11` |
| Divergence | `git rev-list --left-right --count origin/main...HEAD` | `0  0` |
| Working tree | `git status --short` | empty (clean) |
| Whitespace | `git diff --check` | clean |

### Phase 20E merge verification (PR #38)

| Fact | Value |
|---|---|
| PR | **#38** — "Phase 20E: Implement percentage platform fee engine" (<https://github.com/ikrome002-design/servana/pull/38>) |
| State | **MERGED** |
| Implementation commit | `f6e208a90513bf5ca1c219c456b263ea0d111c5c` — present **exactly once** on PR #38 |
| Governance / final PR head | `24d1cad60539fe40596125240391c48a1b821246` — present **exactly once** on PR #38 |
| Merge commit | `c0881993ae0c59536013c9b84e182e5000fa1e11` (= `origin/main` = local `main`) |
| Merged at | `2026-07-14T06:19:43Z` |
| Final CI run | `29310753740` |
| `reviewDecision` | **blank** — documented solo-maintainer governance exception; **NOT** independent reviewer approval |
| Local 20E branch | `git branch --list phase-20e-percentage-platform-fees` → **absent** (deleted) |
| Remote 20E branch | `git ls-remote --heads origin phase-20e-percentage-platform-fees` → **absent** (deleted) |

Required CI jobs on PR #38 — all `COMPLETED` / `SUCCESS`:

```text
Backend — Pint, Larastan, Pest ............... COMPLETED / SUCCESS
Frontend — ESLint, vue-tsc, Vitest, build .... COMPLETED / SUCCESS
Docker — build images ........................ COMPLETED / SUCCESS
Security — gitleaks .......................... COMPLETED / SUCCESS
E2E — Playwright ............................. COMPLETED / SUCCESS
```

**Phase 20E reconciled to `verified_complete`** in `docs/PROGRESS.md`, `docs/CHANGELOG.md`,
`docs/proof/phase-20e.md`, and `docs/traceability/servana-requirements.csv`
(`SRV-PLATFORM-FEE-001`). Documentation-only: **no Phase 20E implementation logic was altered**, and
the phase is **not** rewritten as independently reviewed — the truthful blank `reviewDecision` and its
solo-maintainer-exception caveat are preserved verbatim.

### Phase 20F branch

| Check | Result |
|---|---|
| Branch | `phase-20f-compensation-plan-commission-rules` |
| HEAD at creation | `c0881993ae0c59536013c9b84e182e5000fa1e11` |
| Merge base vs `origin/main` | `c0881993ae0c59536013c9b84e182e5000fa1e11` (identical) |
| Working tree at creation | clean |

Never worked on `main`; never committed to `main`; no second Phase 20F branch exists.

---

## 2. External Gate W — CLOSED

| Evidence artifact required by §80.2 | Present? |
|---|---|
| `docs/integrations/wallet/gate-w-evidence.md` | **absent** |
| `docs/integrations/wallet/` | **absent** |
| `docs/integrations/` | **absent** (directory does not exist) |
| Wallet Servana Collections Slice evidence | absent |
| Sandbox service-account credential proof | absent |
| Pinned Wallet OpenAPI hash | absent |
| Passing Wallet contract suite | absent |
| Sandbox STK transcript | absent |
| Sandbox C2B transcript | absent |
| Signed webhook transcript | absent |
| Reconciliation transcript | absent |
| Explicit PASS status | absent |

`docs/PROGRESS.md` independently recorded Gate W **CLOSED** at Phase 20E Increment 1; that record
still holds. **Gate W = CLOSED.**

### Sequencing consequence

The active v4 rule (§§79–80) is:

```text
20A -> 20B -> 20C
20B -> External Gate W -> 20D-W
20A + 17/18 -> 20E
If Gate W is not open when 20C exits, continue to 20E/20F and return to 20D-W when Gate W opens.
```

Phase 20E is merged (PR #38). Gate W is closed ⇒ **20D-W remains blocked**. Phase 20F depends only on
substrate already merged into `main` — the HR/staff-profile substrate (Phases 8/15B: `staff_profiles`,
`merchant_users`, `merchant_branches`) and the Phase 20A platform preferred-personnel-fee substrate
(`preferred_personnel_fee_rules`) — so it is **independently eligible**. **Phase 20F is the next
executable phase.** No pivot to 20D-W; no product-owner sequencing question is triggered; **no Wallet
runtime is introduced by Phase 20F**.

---

## 3. Specification gates F1–F10 — decision table

All ten gates are resolved from authoritative sources. **No gate is a blocker.**

| Gate | Decision | Authority / repository evidence |
|---|---|---|
| **F1** Compensation model vocabulary | `compensation_model` ∈ **`commission_only`**, **`salary_plus_commission`**, **`salary_only`**. Kept strictly separate from `staff_profiles.employment_type`. | Plan §59 (explicit); Scope §12.2 "Do Not Overload `employment_type`" (explicit); Scope §12.1 table |
| **F2** Plan ownership & scope | **Branch-owned**: `merchant_id` + `branch_id` + `staff_profile_id`. **One active plan per personnel per branch.** | Plan §59 ("one active plan per staff profile, branch, and date"); Scope §12.9 hard rule; Scope §18.3; `commission_handoff_events` already keys personnel by `staff_profile_id` |
| **F3** Effective dating & overlap | `effective_from date NOT NULL`, `effective_to date NULL` (= ongoing); half-open `[from, to)`; `EXCLUDE USING gist` over (`staff_profile_id` `=`, `branch_id` `=`, `daterange` `&&`) partial `WHERE status IN ('active','scheduled')`. | Plan §59 (date-range exclusion constraint); merged `preferred_personnel_fee_rules_no_overlap` precedent |
| **F4** Monetary fields | Integer minor units only; `percentage_basis_points` **XOR** `fixed_amount_minor`+`currency`; bp bound `0..10000`; **no ledger rows**. | Plan §59; ADR-005; Scope §12.7 Step 3B/3C ("integer minor units"); `preferred_personnel_fee_rules` precedent |
| **F5** Commission-rule applicability | `commission_rules` is a **sibling** table referenced by `personnel_compensation_plans.commission_rule_id` (**nullable**). Configuration only. | **Scope §18.3 (decisive)**: `personnel_compensation_plans` … "commission_rule_id"; Scope §12.7 Step 3A field list; Plan §61 (earning is 20G) |
| **F6** Preferred-personnel-fee applicability | `applies_to_preferred_personnel_fee boolean NOT NULL DEFAULT false` on `commission_rules` = **basis-inclusion** flag. | Plan §59 ("treatment per `applies_to_preferred_personnel_fee`"); **Scope §969** (HR decides whether commission *includes or excludes* the fee **in the commission basis**) |
| **F7** Immutability | Active/effective monetary terms immutable → **supersede-not-edit** (new `effective_from` version + history row + audit + reason + actor). Prior rules **ended, not deleted**. | Plan §59; Scope §12.7 Step 3C ("ended, not deleted"); `preferred_personnel_fee_rules` immutability precedent |
| **F8** Backdated-change approval | Backdated ⇔ `effective_from` < current **`Africa/Nairobi`** business date. Requires submit → approve, mandatory reason, impact preview, maker/checker, fresh step-up, **critical** audit. | Plan §59 ("backdated changes require approval + reason + impact preview + critical-severity audit"); matrix `compensation.plan.approve` (`step_up_required: true`, MC-incompatible with `submit`) |
| **F9** Permissions & role boundaries | Activate **8** `compensation.*` keys (HR, branch). Retire legacy `commissions.manage` → `compensation.plan.update_draft`, `commissions.view` → `compensation.history.view`. | `docs/auth/permission-matrix.yaml` (`owning_phase: Phase 20F`, `canonical_successor` already declared); Plan §10.2; Plan §179 (HR owns compensation setup, branch-scoped) |
| **F10** API & frontend ownership | Exactly **one** Phase 20F frontend surface: **HR Compensation** (`hr.compensation`, permission `compensation.plan.view`). | `docs/frontend/screens/inventory.yaml:418` (`hr-compensation`, `phase: Phase 20F`); `role-navigation.yaml:269`; Scope §12.6 (`HR → Compensation`) |

### F1 — Compensation model vocabulary (detail)

Plan §59 states the vocabulary and the model-specific validation directly:

- `commission_only` — commission rule **required**, salary **null**, no salary ledger
- `salary_only` — salary fields **required**, commission rule **null**, no commission ledger
- `salary_plus_commission` — **both** required

Scope §12.2 is explicit that this must **not** be folded into `employment_type`. The shipped
`staff_profiles.employment_type` CHECK is `('full_time','part_time','contract','commission_only')` —
it shares the *label* `commission_only` but is a different column with a different meaning
(employment relationship vs. compensation model). Scope §12.2: "A full-time employee may earn salary
plus commission; a contractor may receive a salary-only retainer." **No overload; no extra model
values.**

### F2 — Ownership & scope (detail)

Plan §59: "one active plan per **staff profile, branch, and date**". Scope §12.9 hard rule: "there
must be only **one active compensation plan per personnel per branch at a time**". HR is
branch-scoped (Plan §179; Scope §529 — HR "operates strictly within the branch in which the HR user
is assigned"). The subject is `staff_profile_id`, consistent with the merged Phase 18B
`commission_handoff_events` seam which already identifies personnel by `staff_profile_id`.

⇒ All three tables are **BRANCH_OWNED**: non-null `merchant_id` + `branch_id`, composite FK
→ `merchant_branches(id, merchant_id)` for merchant/branch consistency, `BelongsToMerchant` +
`BelongsToBranch`, registered in `TenantOwnership::BRANCH_OWNED`, `::MODELS` (`'branch'`), and
`::COMPOSITE_CONSISTENCY`. Route bindings resolve ULIDs inside tenant scope ⇒ foreign IDs 404.

**Not** merchant-wide, **not** service-scoped, **not** role-scoped, **not** platform-scoped.

### F3 — Effective dating & overlap (detail)

Half-open `[effective_from, effective_to)` so **adjacent windows are legal** (a plan ending
2026-08-01 and the next starting 2026-08-01 do not collide). The partial predicate
`WHERE status IN ('active','scheduled')` means `draft`, `pending_approval`, `superseded`, `expired`,
`rejected`, and `cancelled` **never block** a new effective window. `btree_gist` is already installed
by the merged Phase 20A migration (`2026_07_10_000005`), so no new extension is required.

### F4 — Monetary fields (detail)

| Field | Type | Notes |
|---|---|---|
| `personnel_compensation_plans.salary_amount_minor` | `bigint NULL` | integer minor units; `> 0` when present (Scope §12.7 3B/3C) |
| `personnel_compensation_plans.salary_currency` | `char(3) NULL` | uppercase ISO; default KES at the application layer |
| `personnel_compensation_plans.salary_period` | `varchar(16) NULL` | `monthly`/`weekly`/`daily`/`hourly`/`per_shift` (Plan §60 cadences; monthly recommended at launch per Scope §12.7 3B) |
| `personnel_compensation_plans.salary_payout_day` | `smallint NULL` | optional (Scope §12.7 3B/3C) |
| `commission_rules.percentage_basis_points` | `int NULL` | `BETWEEN 0 AND 10000` |
| `commission_rules.fixed_amount_minor` | `bigint NULL` | `>= 0` |
| `commission_rules.currency` | `char(3) NULL` | present iff fixed |

Value-shape CHECK makes percentage and fixed mutually exclusive, mirroring
`preferred_personnel_fee_rules_value_shape_check`. **No floats. No ledger rows. No `Money`
arithmetic is performed in this phase** — Phase 20F stores configured terms only.

> **F4 residual (non-blocking, recorded).** Scope §12.7 line 2436 requires that "the commission
> percentage cannot exceed the configured merchant/platform maximum". A repository-wide search
> (`max_commission|commission_max|maximum_commission|commission.*maximum`) returns **exactly one hit
> — that Scope sentence itself**. No such configuration exists in `platform_billing_settings`, any
> merchant settings table, the Plan, or elsewhere in the Scope, and **Plan §59 (higher authority in
> the §2 hierarchy) does not require one**. Inventing a merchant/platform maximum would create a new
> settings surface owned by Phase 20A, i.e. scope creep. **Decision:** enforce the structural bound
> `percentage_basis_points BETWEEN 0 AND 10000` (0–100%) at the DB CHECK **and** the Form Request —
> the merged `preferred_personnel_fee_rules_basis_points_range_check` precedent. Recorded as a
> residual risk; **not** a blocker.

### F5 — Commission-rule applicability (detail)

**Scope §18.3 is decisive** and inverts the naive "rules belong to a plan" reading:

> `personnel_compensation_plans` | Compensation model per personnel (§12.15): commission_only /
> salary_plus_commission / salary_only, salary amount/currency/period, **`commission_rule_id`**,
> effective dates, lifecycle status, approval metadata, change reason. One active plan per personnel
> per branch.

and `commission_rules` | "HR-controlled commission configuration." ⇒ `commission_rules` is a
**sibling** configuration record **referenced by** `personnel_compensation_plans.commission_rule_id`
(nullable — null exactly when `compensation_model = 'salary_only'`).

Commission-rule configuration fields (Scope §12.7 Step 3A):

| Field | Required | Values |
|---|---|---|
| Commission type | yes | `percentage` \| `fixed_amount` |
| Commission value | yes | bp (percentage) or minor units (fixed) |
| Commission basis | yes | `service_price` \| `invoice_item_total` \| `paid_amount` \| `net_after_discount` |
| Applies to | yes | `all_services` \| `selected_services` \| `service_category` |
| Applies to preferred personnel fee | optional | boolean (F6) |
| Effective from / to | yes / optional | date / nullable date |
| Notes | optional | internal HR note |

Phase 20F **configures** rules. It does **not** resolve a rule against a business event, does **not**
compute a commission amount, and does **not** create an earned row. Plan §61 assigns earning
**exclusively** to Phase 20G at Finance validation.

### F6 — Preferred-personnel-fee applicability (detail)

Plan §59: "Preferred-personnel-fee treatment per `applies_to_preferred_personnel_fee`."
**Scope §969** settles the semantics unambiguously:

> Finance sees the fee in the invoice, payment, receipt, reports, cash-up, and reconciliation. HR
> decides whether personnel commission **includes or excludes the preferred-personnel fee** through
> compensation/commission rules. Personnel see the job as preferred and see related earnings only if
> HR's commission rule **includes the preferred-personnel fee in the commission basis**.

⇒ `applies_to_preferred_personnel_fee boolean NOT NULL DEFAULT false` on `commission_rules`:

- `true` — the preferred-personnel fee **is included** in the future commission basis
- `false` — the preferred-personnel fee **is excluded** from the future commission basis

It is a **basis-inclusion flag** — **not** a separate commission basis, **not** a rate modifier,
**not** a payout trigger, **not** an earned-commission row. Phase 20G consumes it when earning
commission. No question to the product owner is required.

### F7 — Immutability & supersede-not-edit (detail)

Immutable once `status` ∈ (`pending_approval`, `scheduled`, `active`, `superseded`, `expired`,
`rejected`, `cancelled`) — i.e. everything except `draft`:

```text
compensation_model
staff_profile_id / branch_id / merchant_id     (subject)
salary_amount_minor / salary_currency / salary_period / salary_payout_day
commission_rule_id
effective_from / effective_to
```

Enforced in **three** layers: (1) a PostgreSQL `BEFORE UPDATE` trigger rejecting any change to those
columns outside `draft`; (2) the domain state machine; (3) Form Requests. Mutation of terms is
allowed **only** while `draft`. A change to effective terms is a **supersede**: a new plan version
with a new `effective_from`, a `compensation_plan_history` row, an audit event, a mandatory
`change_reason`, and the actor; the prior row transitions to `superseded` — never destructively
edited, never deleted. Scope §12.7 Step 3C: a previously active commission rule is "**ended, not
deleted**".

### F8 — Backdated-change approval (detail)

```text
business date := CURRENT_DATE in Africa/Nairobi
backdated     := effective_from < business date
```

Lifecycle (Scope §12.9 — 8 statuses):

```text
draft ──submit──> pending_approval ──approve──> scheduled | active
  │                      │
  │                      └──reject──> rejected
  └──cancel──> cancelled
scheduled ──(effective_from reached)──> active
scheduled ──cancel──> cancelled
active ──supersede──> superseded
active ──(effective_to reached)──> expired
```

Controls on a backdated change: mandatory `change_reason` (non-empty CHECK), impact preview computed
before submission, maker/checker (`compensation.plan.submit` is MC-incompatible with
`compensation.plan.approve` ⇒ **the submitter can never approve their own plan**), fresh step-up on
approve (`step_up_required: true`), and a **critical**-severity audit event on backdated approval
(ordinary approval is `high`). Unapproved backdating is blocked at the Form Request, the state
machine, **and** the database. Invalid transitions ⇒ `422 invalid_state_transition`.

Scope §12.8 lists Merchant Admin / Finance as *recommended* approvers for some change classes. The
plan-parity-tested matrix sets `compensation.plan.approve` `default_roles: [hr]` with maker/checker
separation, and Plan §59 says "HR sets up and submits; HR approves per `compensation.plan.approve`
where the scope assigns approval to HR (and Merchant Admin/Finance where policy requires)". **Launch
default = HR approves under maker/checker separation**; the Merchant-Admin/Finance variants are a
policy option, not the shipped default. Matrix + Plan win over the Scope's "recommended" wording.

### F9 — Permissions & role boundaries (detail)

**Activate (8)** — all already declared in `docs/auth/permission-matrix.yaml` as
`implementation_status: planned`, `owning_phase: Phase 20F`, `scope: branch`, `default_roles: [hr]`:

| Key | Severity | Billing read-only | MFA | Step-up | Maker/checker |
|---|---|---|---|---|---|
| `compensation.plan.view` | info | allow_read | no | no | — |
| `compensation.plan.create` | warn | block | no | no | — |
| `compensation.plan.update_draft` | info | block | no | no | — |
| `compensation.plan.submit` | warn | block | no | no | **incompatible with `approve`** |
| `compensation.plan.approve` | high | block | no | **yes** | **incompatible with `submit`** |
| `compensation.plan.reject` | warn | block | no | no | — |
| `compensation.plan.cancel` | warn | block | no | no | — |
| `compensation.history.view` | info | allow_read | no | no | — |

**Retire (2)** — canonical successors already declared in the matrix:

| Legacy key | Canonical successor | Current grants |
|---|---|---|
| `commissions.manage` | `compensation.plan.update_draft` | HR |
| `commissions.view` | `compensation.history.view` | HR, **Merchant Admin**, **Branch Manager** |

`commissions.view` is currently also granted to `merchant_admin` and `branch_manager` in
`PermissionRegistry`. Its canonical successor `compensation.history.view` is **HR-only** in the
matrix (`default_roles: [hr]`), and **Plan §10.2** states the Merchant Administrator "never
configures services/pricing/**commissions**/personnel assignment". ⇒ those two grants are
**RETIRED, not retained** — the same treatment applied to `audit.view_full` in Phase 19 and to the
plural `platform_fees.*` keys in Phase 20E. Nothing functional is lost: both legacy keys are
placeholders with no backing tables, policies, or routes (no compensation table existed before this
phase). Merchant-Administrator compensation oversight arrives properly as
`merchant.compensation_summary.view` in **Phase 20H**; Branch-Manager oversight remains via
`branch.dashboard.view` / `reports.view`.

**Do NOT activate:** `compensation.adjustment.create`, `compensation.liability.view` (both
`owning_phase: Phase 20G`); `merchant.compensation_summary.view`, `payout.*`, `earnings.*` (Phase
20H). No separate `commission.rule.*` namespace is created — the matrix declares none, and commission
rules are governed by the same `compensation.plan.*` keys as the plan that references them.

### F10 — API & frontend ownership (detail)

Repository evidence:

```yaml
# docs/frontend/screens/inventory.yaml:418
- key: hr-compensation
  domain: hr
  route: ~
  status: planned
  phase: Phase 20F
  spec: ~

# docs/frontend/navigation/role-navigation.yaml:269
- key: hr.compensation
  label: Compensation
  availability: planned
  permission: compensation.plan.view
  phase: Phase 20F
```

⇒ **Phase 20F owns exactly one frontend surface: the HR Compensation screen**, hosted on the existing
HR domain (Scope §12.6 `HR → Compensation`). No duplicate navigation is created. Backend surface:
branch-scoped `/api/v1` compensation-plan + commission-rule configuration endpoints, ULIDs only, thin
controllers, no generic status route, **no DELETE route for effective financial terms**, plus OpenAPI
+ generated TypeScript, a Pinia store, Vitest, and Playwright coverage.

#### Inventory/navigation source-of-truth correction

`docs/frontend/navigation/role-navigation.yaml:140-144` tagged `merchant.compensation-summary` as
`phase: Phase 20F`. Its permission `merchant.compensation_summary.view` is
`owning_phase: **Phase 20H**` in the plan-parity-tested permission matrix
(`docs/auth/permission-matrix.yaml:1652-1656`), and Plan §80/§63 place the Merchant-Administrator
compensation summary in Phase 20H (earnings/statement surface). The matrix + Plan **win** over the
navigation tag (source-of-truth hierarchy §2). **Retagged to Phase 20H.** The screen is **not** built
in Phase 20F. This is the same class of inventory mistag already recorded during Phase 20A
(registration-monitoring / plan-management screens mistagged 20A → really 20B).

---

## 4. Data-dictionary plan

Canonical DDL added to `docs/architecture/data-dictionary/billing-and-wallet.md`
(the file that already carries the merged `preferred_personnel_fee_rules` substrate this phase reads):

| Table | Ownership | Purpose |
|---|---|---|
| `commission_rules` | branch-owned | HR-controlled commission configuration (type/value/basis/applies-to/preferred-fee flag/effective window). Sibling record referenced by a plan. |
| `personnel_compensation_plans` | branch-owned | Compensation model per personnel per branch: model, salary terms, `commission_rule_id`, effective window, lifecycle status, approval metadata, change reason. |
| `compensation_plan_history` | branch-owned | Append-only compensation change history (what changed, from/to, actor, reason, backdated flag, superseded-by). |

## 5. Migration plan

Forward-only (ADR-004); **no shipped migration is edited** (Guardrail 12). Order is FK-driven:

| # | Migration | Table | Notes |
|---|---|---|---|
| 1 | `…_create_commission_rules_table` | `commission_rules` | created first — plans FK it |
| 2 | `…_create_personnel_compensation_plans_table` | `personnel_compensation_plans` | nullable `commission_rule_id` FK; gist EXCLUDE; immutability trigger |
| 3 | `…_create_compensation_plan_history_table` | `compensation_plan_history` | append-only history child |

Constraints per table: status CHECKs; `compensation_model` CHECK; model-shape coherence CHECKs
(salary null ⇔ `commission_only`; `commission_rule_id` null ⇔ `salary_only`); non-negative money;
`percentage_basis_points BETWEEN 0 AND 10000`; percentage/fixed value-shape exclusivity; uppercase
ISO currency; `effective_to > effective_from`; non-empty `change_reason`; composite FK
→ `merchant_branches(id, merchant_id)`; composite FK to `staff_profiles(id, merchant_id)`; gist
EXCLUDE for overlap; `ulid` unique.

**Manifest entries (`docs/architecture/migrations/manifest.yaml`) are registered in Increment 2
together with the migration files**, because the repository's `MigrationManifestTest` fails on a
manifest entry whose file is not on disk — the Phase 20E precedent (recorded in that phase's proof).

**No** `salary_ledger`, `commission_ledger`, `compensation_adjustments`, payout, or earnings table is
created in Phase 20F.

## 6. State-machine plan

`docs/architecture/state-machines/personnel-compensation-plan.md` — the 8-status Scope §12.9
lifecycle plus the commission-rule lifecycle, transition table, guards, terminal states, and the
`422 invalid_state_transition` contract.

## 7. Domain-service plan

`app/Domain/Compensation/` (extending the existing Phase 18B handoff seam — `CommissionHandoffEvent`,
`CommissionHandoffWriter`, `CommissionPreviewService` — which is **not modified** by this phase):

```text
Enums/      CompensationModel, CompensationPlanStatus, CommissionRuleStatus,
            CommissionCalculationType, CommissionCalculationBasis, CommissionAppliesTo, SalaryPeriod
Models/     PersonnelCompensationPlan, CommissionRule, CompensationPlanHistory
Services/   PersonnelCompensationPlanStateMachine, CommissionRuleStateMachine
Actions/    CreateCompensationPlanDraft, UpdateCompensationPlanDraft, SubmitCompensationPlan,
            ApproveCompensationPlan, RejectCompensationPlan, CancelCompensationPlan,
            SupersedeCompensationPlan, CreateCommissionRule, UpdateCommissionRuleDraft, EndCommissionRule
Queries/    ResolveEffectiveCompensationPlan, ResolveEffectiveCommissionRule
Support/    DetectBackdatedCompensationChange, BuildCompensationImpactPreview
Exceptions/ CompensationStateException, CompensationOverlapException, CompensationImmutabilityException
```

Resolvers return **configuration** for a subject/date. They compute **no** money and create **no**
rows.

## 8. API plan

Branch-scoped, `auth:sanctum` + `ResolveTenantContext` + `EnsurePermission` + `EnsureBranchScope`;
thin controllers → Form Request → policy → action → masked Resource; ULIDs external; server-owned
fields rejected; fresh step-up on approve; no generic status route; **no DELETE of effective terms**.

## 9. Audit plan

`compensation.plan.created` (warn) · `updated_draft` (info) · `submitted` (warn) · `approved` (high) ·
`rejected` (warn) · `cancelled` (warn) · `superseded` (high) · **`backdated_change_approved`
(critical)** · `commission_rule.created` / `updated_draft` / `ended`. Every mutating route is covered
by `AuditMutationCoverage`; severities asserted by `AuditSeverityCoverage`. The audit domain
`compensation` already exists (Phase 19 `audit.compensation.view` + `AuditLogController::compensation`),
so Phase 19's deferred compensation-domain emissions land with this, their owner phase.

## 10. Frontend plan

Single screen `hr.compensation` (HR Compensation), permission `compensation.plan.view`; Pinia store;
sub-views per Scope §12.6 limited to 20F scope (**Current Compensation, Commission Rules, Salary
Terms, Change History** — *Payout History is Phase 20H and is not built*). Model-driven dynamic
fields (Scope §12.7 Step 2), preferred-fee inclusion control, backdated-change warning, impact
preview, immutable-active-terms display, maker/checker + step-up states, loading/empty/error/forbidden
states. Responsive 360/768/1280, 200% zoom, keyboard/focus restoration, light + dark, axe
serious/critical = 0.

## 11. Test plan

| Area | Tests |
|---|---|
| Schema | `CompensationPlanSchemaTest` — columns, CHECKs, FKs, composite consistency, gist EXCLUDE, trigger, indexes, ULID |
| Model validation (F1) | `CompensationModelValidationTest` — each model's required/forbidden fields; **`SalaryOnlyHasNoCommissionRuleTest`** (Plan §80 named test) |
| Overlap (F3) | `CompensationPlanOverlapTest` — overlap rejected; adjacent legal; draft/superseded/cancelled never block |
| Immutability (F7) | `CompensationSupersedeNotEditTest` — active terms un-editable (DB + domain); supersede creates version + history; prior rule ended not deleted |
| Backdated (F8) | `CompensationBackdatedApprovalTest` — detection, approval required, reason required, critical audit, step-up, maker/checker denial |
| Lifecycle | `CompensationPlanStateMachineTest` — valid/invalid transitions → 422 |
| Isolation | `CompensationIsolationTest` — cross-merchant + cross-branch denial; foreign ULID → 404 |
| API/authz | `CompensationPlanApiTest`, `CompensationMakerCheckerTest` |
| Permissions | `PermissionMatrixTest`, `PermissionLegacyKeyReconciliationTest`, catalogue completeness, planned-key isolation |
| Contracts | `RouteSecurityContractTest`, `AuditMutationCoverageTest`, `AuditSeverityCoverageTest`, `OpenApiContractTest`, `MigrationManifestTest`, `TenantColumnCoverageTest`, `ModelTenancyTraitCoverageTest` |
| Exclusions | scope-purity: no Wallet/provider symbol; **no `salary_ledger`/`commission_ledger`/`compensation_adjustments` table, model, route, or type**; no payout/earnings runtime |
| Frontend | `compensationStore.spec`, `CompensationPlanForm.spec`, `e2e/phase-20f-compensation.spec` (axe, 360/768/1280, 200% zoom, light/dark, keyboard) |

## 12. Explicit exclusions (asserted, not merely stated)

| Excluded | Owner |
|---|---|
| Wallet client/HTTP/credentials/webhooks, provider callbacks, Safaricom/Daraja symbols, subscription payment attempts/payments, billing credits, manual Super-Admin payment recording | **20D-W** (Gate W) |
| `salary_ledger`, `commission_ledger`, `compensation_adjustments`, earned commission rows, salary accrual scheduler, commission earning at Finance validation, refund/void reversal ledger | **20G** |
| `personnel_payout_runs`, `personnel_payout_items`, earnings statements/queries, mark-paid, Merchant-Administrator compensation summary | **20H** |
| Referral runtime | **21R-A/B** |
| Notification persistence, scheduled reports | **21N** |
| Personnel SMS | **21S** |
| Search indexing | **22** |
| Release hardening | **23** |
| Performance | **24** |
| Deployment, centralized alert transport, runbooks | **25** |

A Phase 20F compensation plan **configures future behavior**; it must never create the financial
facts owned by Phase 20G or Phase 20H.

---

## 13. Increment 1 — evidence

**Files changed (documentation + generated fixtures only; no product code, no migration):**

| File | Change | Kind |
|---|---|---|
| `docs/PROGRESS.md` | 20E → `verified_complete`; roadmap row split (20D-W / 20F / 20G / 20H); Phase 20F section; stale `Phase 20C (in_progress)` heading corrected | hand-authored |
| `docs/CHANGELOG.md` | 20E → `verified_complete` with PR #38 evidence; Phase 20F entry | hand-authored |
| `docs/proof/phase-20e.md` | lifecycle → `verified_complete` + reconciliation table | hand-authored |
| `docs/traceability/servana-requirements.csv` | `SRV-PLATFORM-FEE-001` → `verified_complete`; `SRV-COMPENSATION-001` added | hand-authored |
| `docs/proof/phase-20f.md` | this file — F1–F10 decision table | hand-authored |
| `docs/architecture/data-dictionary/billing-and-wallet.md` | 3 Phase 20F tables + title/controlling-sources | hand-authored |
| `docs/architecture/data-dictionary/branches-and-staff.md` | Phase 20F cross-reference + `compensation_model` ≠ `employment_type` note | hand-authored |
| `docs/architecture/state-machines/personnel-compensation-plan.md` | **new** | hand-authored |
| `docs/architecture/state-machines/commission-rule.md` | **new** (repo convention: one file per aggregate) | hand-authored |
| `resources/spa/src/navigation/roleNavigation.ts` | `merchant.compensation-summary` → `Phase 20H` (**source of truth**) | hand-authored |
| `docs/frontend/screens/inventory.json` | `compensation-summary` → `Phase 20H` (**source of truth**) | hand-authored |
| `docs/frontend/navigation/role-navigation.yaml` | retag propagated | **REGENERATED** |
| `docs/frontend/screens/inventory.yaml` | retag propagated | **REGENERATED** |

> **Generated-file discipline.** `role-navigation.yaml` and `inventory.yaml` are **vitest file
> snapshots**, not hand-editable sources (`role-navigation.yaml` header: "Generated from
> `resources/spa/src/navigation/roleNavigation.ts` … Do not hand-edit"; `inventory.yaml` header:
> "Generated from `inventory.json` … Do not hand-edit"). An initial hand-edit of
> `role-navigation.yaml` was **reverted** (`git checkout --`) and redone correctly at the source
> (`roleNavigation.ts` / `inventory.json`), then propagated with
> `npx vitest run -u src/navigation/roleNavigation.spec.ts` and
> `npx vitest run -u src/screens/screenInventory.spec.ts`. Both fixtures then pass **without** `-u`.
> Resulting fixture diffs are exactly one line each (`phase: Phase 20F` → `phase: Phase 20H`).

**Commands run + results (Increment 1):**

| Command | Result |
|---|---|
| `git fetch/fsck/status/rev-parse/merge-base/rev-list` | baseline verified (§1) |
| `gh pr view 38 …` | PR #38 MERGED; commits + 5 CI jobs verified (§1) |
| `php -r` CSV column audit | **49 rows (1 header + 48 data), 15 columns, 0 mismatched** |
| `docker compose up -d postgres redis app` | postgres + redis **healthy**; app started (PostgreSQL 16 — never SQLite, Guardrail 13) |
| `php artisan test --filter=MigrationManifestTest` | **9 passed, 28 assertions**, 0 failed |
| `php artisan test tests/Feature/Auth/PermissionMatrixSchemaTest.php` | **3 passed, 342 assertions**, 0 failed |
| `npx vitest run src/screens/screenInventory.spec.ts` | **8 passed**, 0 failed (no `-u`) |
| `npx vitest run src/navigation/roleNavigation.spec.ts` | **9 passed**, 0 failed (no `-u`) |

**Totals:** backend 12 passed / 0 failed / 0 skipped (370 assertions); frontend 17 passed / 0 failed.
No test selected zero tests. Nothing weakened, skipped, or suppressed.

**Failures encountered, root causes, and fixes (Increment 1):**

1. **Traceability CSV row had 16 columns, schema has 15.** *Root cause:* I emitted middleware and
   permissions as two fields, but the schema carries a single `policy_and_permission` column (the
   merged `SRV-PLATFORM-FEE-001` row proves the convention). *Fix:* merged the two into one field.
   *Rerun:* CSV audit → 15 columns, 0 mismatched. *Not weakened* — no column was dropped.
2. **Hand-edited a generated file.** *Root cause:* `role-navigation.yaml` looked like a source but is
   a vitest file snapshot generated from `roleNavigation.ts`; a hand-edit would have been overwritten
   and would have failed the snapshot guard. *Fix:* reverted the YAML, edited the TS source, and
   regenerated. *Rerun:* both fixture specs pass without `-u`. *No generated file is hand-edited.*

**Remaining risks after Increment 1:**

1. **F4 residual** — the Scope's "configured merchant/platform maximum" commission cap has no
   substrate; the 0–10000 bp structural bound is the conservative stand-in. If the product owner
   later wants a real cap, it is a Phase 20A settings change plus a Phase 20F validation hook.
2. **Scope §12.8 approver variants** — Merchant-Admin/Finance approval of salary/high-value changes is
   "recommended" in the Scope but not in the parity-tested matrix; launch ships HR-approves-under-
   maker/checker. A later policy change is a matrix + registry change, not a schema change.
3. **`salary_period` sub-monthly cadences** — `daily`/`hourly`/`per_shift` are configurable here, but
   Plan §60 requires approved attendance/shift data before they can accrue. Phase 20G must not accrue
   sub-monthly salary without that substrate; Phase 20F only stores the cadence.

**Next action:** Increment 2 — migrations, enums, models, factories, database guards.

---

## Increment 2 — migrations, enums, models, factories, database guards, schema proof

Increment 1 decisions F1-F10 are binding and were not reopened. Nothing here creates an earned
financial fact.

### Migrations (forward-only; no shipped migration edited)

| Order | File | Table |
|---|---|---|
| 1 | `2026_07_14_000001_create_commission_rules_table.php` | `commission_rules` |
| 2 | `2026_07_14_000002_create_personnel_compensation_plans_table.php` | `personnel_compensation_plans` |
| 3 | `2026_07_14_000003_create_compensation_plan_history_table.php` | `compensation_plan_history` |

Order is forced by the FK direction (F5): the plan references the rule; history references the plan.
Manifest entries were added only after the files existed; `MigrationManifestTest` stays green.

### Database guards (PostgreSQL 16)

- **F1 model shape** — `personnel_compensation_plans_model_shape_check`: `commission_only` => no
  salary + rule required; `salary_only` => salary required + `commission_rule_id` NULL;
  `salary_plus_commission` => both. This is the DB guarantee behind Plan §80's named
  "salary-only has no commission rule" test (Scope §12.5).
- **F2 ownership** — all three tables branch-owned; composite FKs `(branch_id, merchant_id)` ->
  `merchant_branches`, `(staff_profile_id, merchant_id)` -> `staff_profiles`,
  `(commission_rule_id, merchant_id)` -> `commission_rules`, `(compensation_plan_id, merchant_id)` ->
  `personnel_compensation_plans`. No reference can cross a merchant boundary (ADR-002).
- **F3 one active plan per personnel per branch** — partial `EXCLUDE USING gist (branch_id,
  staff_profile_id, daterange(effective_from, effective_to, '[)')) WHERE status IN ('active',
  'scheduled')`. Half-open => adjacent windows legal; draft/pending/terminal never block. The repo's
  established nullable-open-ended pattern (`daterange` with a NULL upper bound is unbounded) is used;
  no `COALESCE` is needed and none was added.
- **F4 integer money** — basis points `0..10000`; non-negative fixed amount; salary `> 0`; uppercase
  ISO-3 currency; payout day `1..31`. Value-shape CHECK makes percentage and fixed mutually exclusive.
  No floats. **The F4 residual stands unchanged: no commission-cap settings table was invented.**
- **F7 immutability** — `BEFORE UPDATE` triggers on both configuration tables freeze the
  subject/model/monetary/rule/`effective_from` terms once the row leaves `draft`. The trigger
  distinguishes an **illegal destructive edit** from the **approved supersede/end transition** that
  closes an open-ended window: `effective_to` may change only when `active -> superseded` AND the old
  value was NULL AND the new value is `> effective_from`. Re-opening or rewriting an already-closed
  window is rejected. No monetary field was left mutable.
- **F8 backdating fails closed** — maker/checker CHECK (`approved_by <> submitted_by`),
  actor/timestamp pair coherence, and an approval/status CHECK so `scheduled`/`active`/`superseded`/
  `expired` cannot exist without a recorded approver.
- **Append-only history** — `BEFORE UPDATE OR DELETE` trigger raises on every attempt; the table has
  no `updated_at` (no mutable column at all). The `audit_logs` precedent (Guardrail 5).

### Test evidence

| Gate | Result |
|---|---|
| `Phase20FSchemaTest` | **73 passed / 178 assertions** |
| `Phase20FEnumParityTest` | **15 passed** |
| `MigrationManifestTest` | **9 passed / 28 assertions** |
| `TenantColumnCoverageTest` + `ModelTenancyTraitCoverageTest` | **23 passed / 355 assertions** (with parity) |
| Full backend suite (`--parallel`) | **1269 passed / 7 skipped / 0 failed / 7644 assertions** |
| Pint | **PASS, 1285 files** |
| Larastan level 8 | **No errors (988 files)** — level not lowered, no ignores added |

Full-suite arithmetic: the 20E baseline of 1181 passed + 88 new Phase 20F tests = 1269. No pre-existing
test regressed.

### Disposable PostgreSQL 16 fresh-build proof

Never run against the dev database.

```text
database:               servana_p20f_proof (created + owned by servana, dropped after verification)
PostgreSQL version:     16.14
migrate:fresh --seed:   all migrations DONE + PermissionSeeder DONE
total migrations:       99
Phase 20F migrations:   3 (2026_07_14_000001..000003)
20F tables present:     3/3
exclusion constraints:  1 (personnel_compensation_plans_no_overlap)
20F triggers:           4 (2 immutability + 2 append-only)
20F rows after seed:    0/0/0 (no backfill; the aggregate is inert until HR configures it)
forbidden ledger/payout tables: 0
cleanup:                DROP DATABASE verified (0 rows in pg_database)
```

### Proof that no financial runtime was introduced

`Phase20FSchemaTest` asserts, against the real schema, that Phase 20F creates **no** `salary_ledger`,
`commission_ledger`, `compensation_adjustments` (20G), `personnel_payout_runs`,
`personnel_payout_items`, `personnel_earnings_queries`, or `earnings_statements` (20H); that no
earned/paid/payable/accrued/payout/settled/Wallet column exists on any 20F table; and that the
pre-existing Phase 18B `commission_handoff_events` seam is **unmodified** (still no rate/earned
column). The enum parity test additionally proves no `paid`/`settled`/`earned`/`accrued`/`disbursed`
value exists in any 20F vocabulary.

### Defects found and fixed (root cause, not symptom)

1. **DEF-20F-001 (documentation drift — manifest).** `MigrationManifestTest` failed: three
   `depends_on` entries referenced `2026_06_15_000102_create_merchant_branches_table.php`, which does
   not exist. Root cause: the branches migration is `2026_06_14_000103`. Fix: corrected the three
   references. Rerun: 9 passed.
2. **DEF-20F-002 (test defect).** `Phase20FEnumParityTest` errored: `StaffEmploymentType::values()`
   is undefined (that enum carries no `values()` helper). Root cause: the test assumed a helper from
   another phase's convention. Fix: derived the values via `array_column(...::cases(), 'value')`
   rather than adding a method to an out-of-scope enum.
3. **DEF-20F-003 (test defect — and a genuine F1 finding).** The same test then failed asserting the
   two vocabularies were disjoint: **`staff_profiles.employment_type` already contains
   `commission_only`**, identical to `CompensationModel::CommissionOnly`. Root cause: the test
   asserted an invariant F1 never claimed. F1 says the same label in two columns does **not** make
   the fields interchangeable — it does not say the label sets are disjoint. Fix: the test now pins
   the overlap to exactly `['commission_only']` (so it can never silently widen into a de-facto
   merge) and proves neither column's CHECK accepts the other's exclusive values
   (`full_time`/`part_time`/`contract` are never compensation models; `salary_only`/
   `salary_plus_commission` are never employment types). **F1 is upheld, not reopened.**
4. **DEF-20F-004 (implementation defect — static analysis).** Larastan level 8 reported
   `missingType.generics` on two `BelongsTo` relations. Root cause: `@return` sharing a line with
   prose in a one-line docblock is not parsed. Fix: expanded both docblocks. Rerun: no errors.
5. **Self-caught test-quality defect.** `it allows the same personnel an active plan in each of two
   different branches` originally created two *different* staff profiles, so it proved nothing about
   its own name. Fix: it now creates a second branch under the same merchant and reuses the **same**
   `staff_profile_id`, genuinely proving the exclusion is scoped per branch.

### Remaining risks (carried to Increment 3)

1. **`compensation_plan_history` has no `activated` event.** The accepted data dictionary's `event`
   CHECK is the 8-value set (`created`…`expired`), but the accepted plan state machine defines a
   `scheduled -> active` activation boundary emitting `compensation.plan.activated`. `expired` (the
   other boundary) *is* in the history set, so the omission looks like an Increment 1 oversight
   rather than a decision. Increment 2 followed the dictionary exactly and did **not** invent a
   value. **Increment 3 must resolve this**: either record activation under an existing event or
   ship a forward migration adding `activated`. It does not block Increment 2 — nothing fails today.
2. **Supersede trigger exception is deliberately narrow.** It permits exactly one shape
   (`active -> superseded`, open -> closed, `> effective_from`). If Increment 3's approval
   transaction needs to close a window in any other shape, the DB will reject it — by design. The
   domain action must match this contract rather than the guard being weakened.
3. **`selected_services` membership has no substrate.** `applies_to = 'selected_services'` is
   storable but no per-rule service-selection table exists (the dictionary notes the plan's
   "configured selection surface"). Increment 3/4 must either define the surface or constrain the
   value's use.

**Next action:** Increment 3 — domain actions, state machines, resolvers and audit.

---

## Increment 3 — domain actions, state machines, resolvers, audit

Increment 1 (F1-F10) and the Increment 2 schema are binding and were not reopened, except for the
product-owner-authorized activation correction below.

### Activation history-event correction

**Classification: documentation omission** (which propagated into schema). The accepted data
dictionary's `compensation_plan_history.event` CHECK omitted `activated`, while the equally accepted
plan state machine defines the `scheduled → active` transition AND its `compensation.plan.activated`
audit event — and already lists `expired`, the symmetric boundary partner. Increment 2 followed the
dictionary faithfully, so the schema inherited the gap. The state machine did not overstate; the
dictionary under-stated.

**Approach: edited the still-uncommitted Increment 2 migration** rather than shipping a forward-only
correction. §4.3 permits this with justification; the justification is:

- `git log --all -- database/migrations/2026_07_14_000003_*` returns **empty** — the migration has
  never been committed to any branch, never merged, and has only ever run in disposable/test
  databases.
- Guardrail 12 says *"Never edit a **shipped** migration"* and the manifest header says *"no
  **shipped** migration is ever edited"*. This one has not shipped. The repository's own precedents
  for forward-only expansion (20E `…000007`/`…000008`) all expand migrations merged in **earlier**
  phases.
- A `create_compensation_plan_history_table` + `expand_..._event_check` pair inside a single
  never-merged PR would permanently pollute `main` with a self-correction that corrects nothing any
  real database ever saw.

Per §4.3 the **full Increment 2 schema proof was rerun**, including a fresh disposable PG16 build.

| Surface | State |
|---|---|
| `CompensationPlanHistoryEvent` enum | `activated` added (9 values) |
| DB CHECK `compensation_plan_history_event_check` | includes `activated` (verified on a fresh PG16 build) |
| `docs/architecture/state-machines/personnel-compensation-plan.md` | `activate` transition fully specified + history vocabulary listed |
| `docs/architecture/state-machines/commission-rule.md` | `activate` boundary + the plan-driven linkage table |
| `docs/architecture/data-dictionary/billing-and-wallet.md` | `activated` in the event list + rationale |
| `Phase20FEnumParityTest` | proves enum == CHECK and that `activated` ≠ `approved` |
| `CompensationPlanActionTest` | proves `scheduled → active` writes an `activated` history row |

**Naming note:** the product-owner brief calls the enum `CompensationPlanHistoryEventType`; the
accepted Increment 2 name is `CompensationPlanHistoryEvent` over the `event` column. The accepted
name was kept — the mapping is recorded here rather than churning the schema.

### State machines

`PersonnelCompensationPlanStateMachine` and `CommissionRuleStateMachine` (repository convention:
enum holds the arrow set, the machine is the single authorizer, unlisted pair →
`CompensationStateException` → `422 invalid_state_transition`). Both implement **exactly** the nine
accepted arrows and nothing more; a test enumerates all 8×8 = 64 pairs and proves the other **55**
are rejected, per aggregate. No `paid`/`earned`/`accrued`/`settled`/`disbursed` status exists.

### Domain actions (12) and services

`CreateCompensationPlanDraft`, `UpdateCompensationPlanDraft`, `SubmitCompensationPlan`,
`ApproveCompensationPlan`, `RejectCompensationPlan`, `CancelCompensationPlan`,
`ActivateScheduledCompensationPlan`, `ExpireCompensationPlan`, `CreateCommissionRuleDraft`,
`UpdateCommissionRuleDraft`, plus resolvers. Supporting services: `CompensationBusinessDate`,
`CompensationShapeValidator`, `CompensationPlanHistoryWriter`.

- **Transaction boundary:** every mutating action wraps `DB::transaction`; history is written inside
  the same transaction as the transition that produced it.
- **Lock order:** row lock on the plan (`lockForUpdate`) → `pg_advisory_xact_lock` on
  `(branch_id, staff_profile_id)` → row lock on the incumbent. The advisory lock serializes
  approvals for one subject; the DB `EXCLUDE` remains the final arbiter (SQLSTATE 23P01 → a friendly
  `409 compensation_plan_overlap`).
- **Supersede is a consequence, not a permission:** approving/activating a successor closes the
  incumbent (`active → superseded`, open-ended `effective_to` := successor's `effective_from`) in the
  SAME transaction. Half-open ranges keep the windows adjacent. Proven: the incumbent's
  `salary_amount_minor` is byte-identical afterwards.
- **Commission-rule linkage (§5.2):** a rule has NO independent lifecycle — every non-draft
  transition is driven by the referencing plan inside that plan's transaction (linkage table in the
  commission-rule spec). Guards: a rule is not transitioned when another non-terminal plan still
  references it (ending it would break that plan's F1 shape), and `salary_only` transitions nothing.

### Resolvers

`ResolveEffectiveCompensationPlan` (active + half-open window contains the date; **>1 match →
`effective_plan_conflict`**, never a guess; none → `null`, never a silent default),
`ResolveEffectiveCommissionRule` (`salary_only` → **null always**; commission-bearing models fail
closed with `effective_commission_rule_missing`), `ResolvePreferredPersonnelFeeApplicability`
(F6 basis-inclusion boolean only). All three compute no money and create no row.

### Backdated approval (F8)

Business date = `Africa/Nairobi` (`CompensationBusinessDate`); backdated ⇔
`effective_from < business date` — computed at **submission**, never accepted from input. Approval
requires: fresh step-up (asserted by the action), maker/checker (`approved_by ≠ submitted_by`, also a
DB CHECK), a non-empty reason, and — for a backdated plan — a `CompensationImpactPreview`, else
`backdated_approval_requires_impact_preview`. Approval then emits **CRITICAL**
`compensation.plan.backdated_change_approved` alongside the HIGH `compensation.plan.approved`.

**Impact preview** is deterministic and side-effect free: subject, branch, window, model, salary
terms, commission-rule terms, preferred-fee inclusion, the incumbent it displaces, and the business
date. It computes **no** earned salary, earned commission, payout, arrears, or Wallet settlement
(Plan §61; Scope §12.7 3B — recalculating already-earned commission is a **20G** adjustment).
**Schema note:** the accepted dictionary has no `impact_preview` column, so the preview is an action
INPUT recorded into `compensation_plan_history.changed_fields` + audit context — no column invented.

### Audit

19 typed events added, all `AuditDomain::Compensation` — this **populates the previously empty
`audit.compensation.view` read segment** that Phase 19 deliberately reserved. Severities:
backdated approval **critical**; approve/supersede/rule-approve/rule-end **high**;
create/submit/reject/cancel **warning**; boundaries + draft edits **info**. Context carries public
ULIDs, configured terms, statuses, business date and sanitized reason only — no internal ids, no
personnel contact, no SQLSTATE, no constraint names.

### Test evidence

| Gate | Result |
|---|---|
| `CompensationPlanStateMachineTest` + `CommissionRuleStateMachineTest` | **18 passed / 180 assertions** |
| `CompensationPlanActionTest` | **50 passed / 119 assertions** |
| `CompensationResolverTest` | **24 passed / 33 assertions** |
| `CompensationAuditCatalogueTest` + `CompensationScopeIsolationTest` + `Phase20FEnumParityTest` | **37 passed / 240 assertions** |
| `Phase20FSchemaTest` + manifest + provider + tenancy gates | **97 passed / 527 assertions** |
| `OpenApiContractTest` | **9 passed** (after the regeneration below) |
| `CashUpWorkflowTest` (pre-existing defect fixed — DEF-20F-010) | **9 passed / 55 assertions** |
| **Full backend suite (`--parallel`)** | **1383 passed / 7 skipped / 0 failed / 8206 assertions** |
| Pint | **PASS, 1317 files** |
| Larastan level 8 | **No errors (1014 files)** — level not lowered, no ignores added |

### Disposable PostgreSQL 16 fresh-build proof (rerun)

```text
database:               servana_p20f_proof (dropped after verification; never the dev DB)
PostgreSQL version:     16.14
total migrations:       99      Phase 20F migrations: 3
20F tables present:     3/3     exclusion constraints: 1     triggers: 4
history event CHECK includes `activated`: yes
20F rows after seed:    0/0/0 (no backfill)
forbidden ledger/payout tables: 0
cleanup:                DROP DATABASE verified (0 rows in pg_database)
```

### Defects found and fixed (root cause, not symptom)

1. **DEF-20F-005 (implementation defect — static analysis).** Larastan level 8 reported 10
   `nullsafe.neverNull` errors. Root cause: `?->` used on non-nullable types (`effective_from` is
   non-nullable per the model docblocks) and `?->… ?? false` on nullable objects. Fix: `->` where the
   value cannot be null; explicit `instanceof` narrowing where the object can. No ignores added.
2. **DEF-20F-006 (implementation defect — dead dependency).** Larastan reported
   `ApproveCompensationPlan::$impactPreview is never read, only written`. Root cause: the preview
   builder was injected, but the preview must be an **input** (a backdated approval without one has
   to fail). Fix: removed the unused constructor dependency.
3. **DEF-20F-007 (test defect — caught by an Increment 2 DB guard).** `CompensationResolverTest`
   forced `status = 'active'` without approval metadata and hit
   `personnel_compensation_plans_approval_status_check`. Root cause: the test took a shortcut the
   schema correctly forbids — an approved state cannot exist without a recorded approver. Fix: the
   tests build lifecycle states through the factory's own `status()`/`active()` states. **The guard
   was not weakened**; it caught a bad test.
4. **DEF-20F-008 (implementation defect — API too narrow).** `CompensationResolverTest` failed with
   a `TypeError`: the resolvers accepted `CarbonImmutable` but Laravel's own `today()` returns the
   **mutable** `Carbon`. Root cause: the public signature was narrower than the framework's standard
   helper, so every caller would have had to convert. Fix: widened to `CarbonInterface|string|null`
   across the resolvers and `CompensationBusinessDate`, and `normalize()` now reduces to the calendar
   date first so a UTC timestamp can never shift the Nairobi business day.
5. **DEF-20F-010 (PRE-EXISTING test defect — time-of-day dependent; surfaced, not caused, by
   Phase 20F).** The full suite failed `CashUpWorkflowTest` (2 tests): `expected_minor` came back
   **0** instead of `450000`/`250000`. **This is not a Phase 20F regression** — the tests fail in
   isolation with no compensation code in the run, and Phase 20F touches no payment, refund, or
   cash-up code.

   *Root cause (proven, not guessed).* `CashUpExpectedTotalCalculator` matches
   `(paid_at AT TIME ZONE 'Africa/Nairobi')::date = business_date`. The Phase 18B test helper
   `cashUpComponent()` set `paid_at => CarbonImmutable::now('Africa/Nairobi')` — a Nairobi
   **wall-clock**. `paid_at` is cast `datetime`, so Laravel serializes it to a NAIVE
   `'Y-m-d H:i:s'` string (the `+03:00` offset is dropped) and PostgreSQL reads it in the **UTC**
   session. A direct probe confirmed the round trip:

   ```text
   UTC now       : 2026-07-16 19:28:17+00:00      Nairobi now : 2026-07-16 22:28:17+03:00
   business date : 2026-07-16
   naive  "2026-07-16 22:28:17"        -> AT TIME ZONE 'Africa/Nairobi' date = 2026-07-17  ← mismatch
   offset "2026-07-16 22:28:17+03:00"  -> AT TIME ZONE 'Africa/Nairobi' date = 2026-07-16
   ```

   The +03:00 is therefore applied **twice**, landing the row on TOMORROW's business date, so no
   validated component matches and expected collapses to 0. It only fires when the suite runs
   between **21:00 and 23:59 Nairobi (18:00-20:59 UTC)** — which is why the Increment 2 full suite
   (run earlier in the day) was green at 1269/0 and the Increment 3 runs were not. A latent
   ~3-hour-per-day red window in CI.

   *Production is correct and was NOT touched.* The real recording path
   (`PaymentRecordingGroupController` → `CarbonImmutable::now()`) stores the instant in UTC; only
   the test helper passed a wall-clock. Fix: the helper (and the refund's `finalized_at` in
   `CashUpWorkflowTest`, whose neighbouring `approved_at` already used `now()`) now use
   `CarbonImmutable::now()`, matching production exactly and making the tests time-independent.
   **No production financial calculation, constraint, or timezone rule was changed** (CLAUDE.md §9).
   Rerun: `CashUpWorkflowTest` **9 passed / 55 assertions**.

6. **DEF-20F-009 (generated-artifact drift).** `OpenApiContractTest` failed with
   *"docs/api/openapi.json is stale"*. Root cause: the **pre-existing** Phase 19 audit-read endpoints
   document the `AuditEvent` action enum, so adding 19 compensation events changed the generated
   spec. Fix: `composer api:openapi`. **This added no API surface** — paths/operations stay at
   **196/235**, identical to the 20E baseline, and the only compensation path is the pre-existing
   `/api/v1/audit-logs/compensation`. Increment 3 ships no Phase 20F route, controller, Form Request,
   Resource, or policy.

### Remaining risks (carried to Increment 4)

1. **Fresh step-up is an action parameter, not yet a request control.** `ApproveCompensationPlan`
   refuses to approve when `hasFreshStepUp` is false, so the domain cannot approve without the
   assertion — but the assertion itself is supplied by the caller. **Increment 4 must wire it to the
   real step-up middleware/policy**; until then no HTTP surface can reach the action at all.
2. **Boundary transitions have no scheduler.** `ActivateScheduledCompensationPlan` and
   `ExpireCompensationPlan` are actions with the correct guards (each refuses to run before its
   date), but nothing drives them on a cadence. This is deliberate: Phase 20F ships **no salary
   accrual scheduler**. Resolution is date-correct regardless, because it matches on the effective
   window rather than the stored status — a scheduled-but-not-yet-activated row simply does not
   resolve. The driver is Phase 20G's concern.
3. **`selected_services` still has no membership substrate** (carried from Increment 2).
4. **Time-of-day test fragility is a class, not a single bug.** DEF-20F-010 was one instance of
   "Nairobi wall-clock stored into a timestamptz that is later read with `AT TIME ZONE`". A repo-wide
   grep found the remaining `now('Africa/Nairobi')` uses in tests are legitimate (they take
   `->toDateString()`/`dayOfWeek`, or are scheduling wall-clocks). Two Phase 20E call sites pass a
   Nairobi instant into ledger actions and are currently green; a future test-infra hardening task
   could add a guard test forbidding wall-clock instants on timestamptz columns. Out of Phase 20F
   scope — recorded, not silently fixed.
5. **Commission-rule history is recorded via audit only.** `compensation_plan_history` is keyed to a
   plan, and the accepted schema has no rule-history table; rule transitions are therefore evidenced
   by the typed `commission_rule.*` audit events plus the plan's own history row. The Plan does not
   require a separate table, so none was invented.

**Next action:** Increment 4 — permissions, API, audit coverage, OpenAPI and generated TypeScript.

---

## Increment 4 — permissions, API, security wiring, OpenAPI + generated TypeScript

Increments 1-3 are binding and were not reopened.

### Permission flip (atomic)

| Count | Before | After |
|---|---|---|
| active | 104 | **110** |
| planned | 66 | **58** |
| legacy-active (ratchet) | 10 | **8** |
| PHP registry keys | 104 | **110** |

**Activated (8, all canonical §19.2, `default_roles: [hr]`, `scope: branch`):**
`compensation.plan.view`, `.create`, `.update_draft`, `.submit`, `.approve`, `.reject`, `.cancel`,
`compensation.history.view`. Each flipped `planned → active` with `owning_phase: null` and
`canonical_successor: null` (an active canonical key is in its final form — enforced by
`PermissionLegacyKeyReconciliationTest`), and `audit_event` set from **route-derived evidence**, not
by hand (see below).

**Retired (2), deleted outright — no alias, no compatibility grant** (the Phase 19 `audit.view_full`
precedent; the seeder's `prunePermissions()` drops them from the DB projection):
`commissions.manage → compensation.plan.update_draft`, `commissions.view → compensation.history.view`.

**Role-boundary consequence.** `commissions.view` was granted to **merchant_admin, branch_manager,
hr, personnel, audit** and was **finance-grantable**. Because its canonical successor is HR-only,
every one of those grants is retired rather than carried over (Plan §10.2: the Merchant Administrator
"never configures … commissions"). No broad replacement key was added. Their real successors live in
later phases and stay PLANNED: Finance → `compensation.liability.view` (20G), Merchant Admin →
`merchant.compensation_summary.view` (20H), Personnel → `earnings.*` (20H). Audit already reads the
domain through the masked `audit.compensation.view`, which Phase 20F now populates.
`docs/proof/phase8-matrix.txt` (regenerated BY `PermissionMatrixTest`, not by hand) shows all 8 keys
with a single ✓ in the `hr` column and `·` everywhere else.

**Left PLANNED on purpose:** `compensation.adjustment.create`, `compensation.liability.view` (20G);
`merchant.compensation_summary.view`, `payout.*`, `earnings.*` (20H).

**Parity layers updated:** `docs/auth/permission-matrix.yaml`, `PermissionRegistry`, the DB
projection (via the existing seeder + prune), `resources/spa/src/types/generated/permissions.ts`
(**generator only** — `composer permission:types`), `docs/proof/phase8-matrix.txt`,
`PermissionMatrixTest`, and the two ratchet tests (10→8, 66→58) with the arithmetic proven above.

### API — route inventory (11 new paths / 13 new operations)

All under the tenant group, class `branch_mutation`, HR-only. Rule routes are governed by the
`compensation.plan.*` keys (the matrix declares no `commission.rule.*` namespace).

| Method | URI | Name | Permission | Extra middleware |
|---|---|---|---|---|
| GET | `/api/v1/commission-rules` | `commission-rules.index` | `compensation.plan.view` | — |
| POST | `/api/v1/commission-rules` | `commission-rules.store` | `compensation.plan.create` | EnsureBranchScope |
| GET | `/api/v1/commission-rules/{commissionRule}` | `commission-rules.show` | `compensation.plan.view` | — |
| PATCH | `/api/v1/commission-rules/{commissionRule}/draft` | `commission-rules.draft.update` | `compensation.plan.update_draft` | EnsureBranchScope |
| GET | `/api/v1/compensation-plans` | `compensation-plans.index` | `compensation.plan.view` | — |
| POST | `/api/v1/compensation-plans` | `compensation-plans.store` | `compensation.plan.create` | EnsureBranchScope |
| GET | `/api/v1/compensation-plans/{compensationPlan}` | `compensation-plans.show` | `compensation.plan.view` | — |
| PATCH | `/api/v1/compensation-plans/{compensationPlan}/draft` | `compensation-plans.draft.update` | `compensation.plan.update_draft` | EnsureBranchScope |
| POST | `/api/v1/compensation-plans/{compensationPlan}/submit` | `compensation-plans.submit` | `compensation.plan.submit` | EnsureBranchScope |
| POST | `/api/v1/compensation-plans/{compensationPlan}/approve` | `compensation-plans.approve` | `compensation.plan.approve` | EnsureBranchScope + **RequireFreshMfa:compensation_backdated_change** |
| POST | `/api/v1/compensation-plans/{compensationPlan}/reject` | `compensation-plans.reject` | `compensation.plan.reject` | EnsureBranchScope |
| POST | `/api/v1/compensation-plans/{compensationPlan}/cancel` | `compensation-plans.cancel` | `compensation.plan.cancel` | EnsureBranchScope |
| GET | `/api/v1/compensation-plans/{compensationPlan}/history` | `compensation-plans.history` | `compensation.history.view` | — |

Every route additionally inherits `auth:sanctum`, throttle, `EnforceIdleTimeout`,
`EnsureActivePrincipal`, `EnsurePrivilegedMfa`, `ResolveTenantContext`, `EnsureMerchantActive`.
**No DELETE, no generic status route, no manual supersede route, no ledger/payout/earnings/summary
route, no platform or provider context** — proven by tests asserting 405/404 on those verbs.

### Security wiring

- **Fresh step-up:** the approve route carries `RequireFreshMfa:compensation_backdated_change` —
  the §18 designated *compensation* action that already existed (owner "Phase 20F/20G"), not an
  unrelated one. Its `owningPhase()` now reads "Phase 20F (implemented; 20G extends)" and it was
  removed from `StepUpAction::businessActions()` following the established precedent
  (BillingConfiguration/InvoiceVoid/PlatformFeeDisputeResolution all left the harness list when
  their real routes shipped); it is now proven on the REAL route instead. Step-up is never disabled
  in tests: missing and stale assertions are both proven to 403 `step_up_required`.
- **Maker/checker:** the submitter approving their own plan returns **403 `maker_checker_violation`**
  (a distinct authorization surface, not a validation error), and the plan stays `pending_approval`.
  Enforced in the action AND by a DB CHECK; declared in the matrix as
  `maker_checker_incompatibilities: [compensation.plan.submit]`.
- **Server-owned fields:** `status`, `is_backdated`, `merchant_id`, `branch_id`, `ulid`,
  `created_by`/`submitted_by`/`approved_by`/`rejected_by` + timestamps, and `supersedes_plan_id` are
  never accepted. A test supplies all of them and asserts each came from the server.
- **Impact preview is never accepted from the client.** The approver sends
  `acknowledge_impact_preview`; the controller BUILDS the preview server-side. Without the
  acknowledgement a backdated approval fails closed (422
  `backdated_approval_requires_impact_preview`) and the plan stays pending.

### Idempotency (§14) — reasoned decision, recorded

Compensation configuration routes are `branch_mutation`, **not** `financial_mutation`, so the
repository requires no idempotency key for them, and `FinancialRouteIdempotencyCoverageTest` +
`RouteSecurityContractTest` both pass unchanged. The reason: these routes create **no money fact**
(Phase 20F is configuration only). Replay is still safe by construction, not by convention —
`pending_approval` is the ONLY legal source state for approve, so a replayed approve returns
`422 invalid_state_transition` rather than duplicating history/audit, and the DB `EXCLUDE` blocks a
duplicate active window regardless. When Phase 20G adds routes that DO create money facts, those
become `financial_mutation` and inherit the idempotency requirement automatically.

### Audit coverage

`AuditMutationCoverage::AUDITED` maps all 8 mutating routes to the events their handlers actually
emit on the committed path — including the same-transaction rule events and the incumbent's
supersede. `compensation-plans.approve` maps to five events (approve, the CRITICAL backdated
variant, the incumbent supersede, and the rule's approve/end). `PermissionMatrixPlanMetadataParityTest`
then re-derives `audit_event` from the live route table + this registry and compares it to the YAML,
so the matrix metadata is **repository evidence, not a claim** — the exact strings were read back
from the route table rather than written by hand. Backdated approval is proven **critical** at the
API level (`$critical->severity->value === 'critical'`), and a denied approval is proven to write
**no** success audit. Activation/expiry emit no route event (no scheduler ships in 20F — they are
domain boundary actions), so they are correctly absent from this route-keyed registry.

### Contracts

```text
OpenAPI:        207 paths / 248 operations (was 196/235)  → +11 paths / +13 operations
new paths:      11 compensation/commission paths (the 12th match, /api/v1/audit-logs/compensation,
                is the pre-existing Phase 19 audit read)
api:contract:check   OK — 207 paths, 248 operations
permission:types     regenerated (generator only; 0 legacy keys, 16 compensation references)
api.ts               regenerated via `npm run api:types`
determinism:    second regeneration of openapi.json / api.ts / permissions.ts → IDENTICAL hashes
frontend:       vue-tsc --noEmit clean; Vitest 352/352
```

### Test evidence

| Gate | Result |
|---|---|
| `CompensationPlanApiTest` | **50 passed / 189 assertions** |
| `CommissionRuleApiTest` | **36 passed / 106 assertions** |
| All `tests/Feature/Compensation/` | **290 passed / 1059 assertions** |
| Permission suite (matrix, schema, parity, plan-metadata, legacy ratchet, planned isolation, TS parity, DB projection, catalogue completeness) | **all green** (matrix 3; parity/schema/ratchet/isolation 11/666 assertions) |
| `RouteSecurityContractTest` + `AuditMutationCoverageTest` + `AuditSeverityCoverageTest` | **17 passed / 813 assertions** |
| `OpenApiContractTest` + `FinancialRouteIdempotencyCoverageTest` + `NoDirectProviderIntegrationTest` + TS parity + `AuditEventCoverageTest` | **26 passed / 122 assertions** |
| **Full backend suite (`--parallel`)** | **1469 passed / 7 skipped / 0 failed / 8644 assertions** |
| Vitest | **352 passed / 82 files** |
| Pint | **PASS, 1334 files** |
| Larastan level 8 | **No errors (1029 files)** — level not lowered, no ignores added |

### Defects found and fixed (root cause, not symptom)

1. **DEF-20F-011 (implementation defect — a docblock that ended the comment).** Every API request
   500'd with `ParseError: syntax error, unexpected identifier "assignment"`. Root cause: the policy
   docblock quoted Plan §10.2 as `**commissions**/personnel assignment` — the `**/` sequence CLOSES a
   block comment, so the remaining prose was parsed as PHP. Fix: rephrased the quote; `php -l` now
   clean across all new files. (A comment, not logic — but it took the whole surface down, which is
   exactly why it is recorded.)
2. **DEF-20F-012 (test defect — a security test that proved nothing).** *"denies approval without a
   fresh step-up"* returned **200**. Root cause: `TestCase::actingAs()` injects a fresh MFA session
   by default and `withSession()` state persists across requests within one test, so the create/submit
   setup calls left a VALID assertion behind — `statefulMfa()` clears the flag but not the already-set
   session. The middleware was correctly attached (verified via `route:list`). Fix: `flushSession()`
   before the step-up assertions, so the request genuinely carries none. **The step-up was never
   weakened or bypassed** — missing AND stale assertions are both now proven to 403.
3. **DEF-20F-013 (test defect — wrong error contract).** Six validation tests used
   `assertJsonValidationErrors`, which looks for Laravel's default `errors` key. Root cause: the app
   renders the Plan §11.5 envelope (`error.code` = `validation_failed`, fields under `error.fields`).
   Fix: assert the real envelope.
4. **DEF-20F-014 (implementation defect — static analysis).** Larastan flagged
   `CompensationPlanController::$context` as never read and one unnecessary `?->` on the left of `??`.
   Root cause: an injected `TenantContext` the plan controller genuinely does not need (the rule
   controller does, for `branchIds()`), and a nullsafe on a non-null property. Fix: removed the dead
   dependency; narrowed with `instanceof`. No ignores added.
5. **Non-defect verified, not assumed:** the full Vitest run showed 2 failures
   (`AppointmentDetail.spec`, `PreferredFeeRulesSection.spec`). Both pass in isolation, neither
   references compensation/commissions, and both were run while the backend `--parallel` suite was
   saturating the machine. A clean Vitest run is **352/352**. Pre-existing load-induced flakes, not
   Phase 20F drift — recorded rather than silently re-run.

### Remaining risks (carried to Increment 5)

1. **`CommissionRuleController::resolveActingBranch()` requires exactly one acting branch.** HR is
   branch-scoped so this holds today, but an HR user assigned to two branches would get 403 on rule
   creation. The alternative (accepting a branch ULID in the body) widens the surface, so the
   conservative behaviour ships; if multi-branch HR is real, Increment 5/6 should add an explicit
   branch selector rather than silently picking one.
2. **Fresh step-up is now enforced at the route** (risk 1 from Increment 3 is CLOSED); the action's
   `hasFreshStepUp` parameter remains as defence-in-depth so the domain cannot approve without it.
3. **Boundary transitions still have no scheduler** (carried from Increment 3): activation/expiry are
   domain actions with correct guards but no route and no cadence. Resolution is date-correct
   regardless because it matches the effective window, not the stored status.
4. **The `staff_profile_id` filter on the index** resolves a foreign ULID to "matches nothing"
   rather than 404 — deliberate for a list filter (a 404 would confirm existence), but it means a
   caller cannot distinguish "no plans" from "not your personnel". Acceptable for a read filter.
5. **`selected_services` still has no membership substrate** (carried from Increment 2).
6. Carried from Increment 3: commission-rule history is evidenced by audit events + the plan's
   history row (no separate table is required by the Plan).

**Next action:** Increment 5 — HR Compensation frontend, Vitest, Playwright, responsive/accessibility
proof.

**Working tree:** dirty with the Increment 1-4 changes above. **No commit, no push, no PR has
occurred.**

---

## Increment 5 — HR Compensation frontend, generated-contract integration, store layer, browser proof

Increment 4's permission/API/security design is binding here and was **not** reopened. One screen was
built: **HR Compensation**. No Merchant-Administrator compensation summary, no Branch-Manager
compensation configuration, no Personnel earnings statement, no Finance liability screen, no
commission/salary ledger UI, no payout UI, no Wallet/provider UI was created — those are Phase
20G/20H/20D-W surfaces (§F10).

### Route

```text
route name:  hr.compensation
path:        /hr/compensation
layout:      BranchLayout          (the existing HR/branch shell — no new top-level area)
component:   resources/spa/src/pages/hr/Compensation.vue
guards:      requiresAuth → requiresActiveMerchant (parent /hr)
             requiresPermission('compensation.plan.view')  (child)
```

`requiresPermission` already existed in `router/guards.ts` but had **no caller** anywhere in the SPA
before this increment; `hr.compensation` is its first use. The guard is UX only — `EnsureBranchScope`
+ `EnsurePermission` + the policies + `RequireFreshMfa` remain the security boundary and re-authorize
every call regardless of what the router renders.

### Navigation and screen inventory

Source files were edited and the generated fixtures regenerated through the repository's own
commands/tests — **no generated file was hand-edited**:

| File | Kind | Change |
|---|---|---|
| `resources/spa/src/navigation/roleNavigation.ts` | source | `hr.compensation` `planned` → `live` + `routeName: 'hr.compensation'` |
| `docs/frontend/navigation/role-navigation.yaml` | generated | regenerated via `vitest roleNavigation.spec.ts -u` |
| `docs/frontend/screens/inventory.json` | source | `hr-compensation` → `implemented`, route, spec path, 8 permissions, summary |
| `docs/frontend/screens/inventory.yaml` | generated | regenerated via `vitest screenInventory.spec.ts -u` |
| `docs/frontend/screens/hr/hr-compensation.md` | generated | `node scripts/generate-screen-specs.mjs` (108 specs; only the new file changed → generator is deterministic) |

The nav fixture regeneration also flushed one **pre-existing Increment-1 drift**: the fixture still
carried `merchant.compensation-summary` as `phase: Phase 20F` while `roleNavigation.ts` had already
been retagged `Phase 20H`. The source was already correct; the snapshot is now caught up. That item
remains `availability: planned` with **no route** — proof it was not built.

`merchant.compensation_summary.view`, `compensation.adjustment.create`, `compensation.liability.view`
and the payout/earnings keys remain planned and unbuilt.

### Store layer

`resources/spa/src/stores/compensationStore.ts` — Pinia setup store, repository convention.

```text
types:      components['schemas']['CompensationPlanResource' | 'CommissionRuleResource' |
            'CompensationPlanHistoryResource' | 'CompensationModel' | 'SalaryPeriod']
            → GENERATED api.ts only; no hand-written response shape
state:      plans, current, history, rules, loading, historyLoading, error,
            filterStatus, filterStaffProfile, filterModel
actions:    fetchPlans, fetchPlan, fetchHistory, fetchCommissionRules,
            createCommissionRule, updateCommissionRuleDraft,
            createPlan, updatePlanDraft,
            transition(id, 'submit'|'approve'|'reject'|'cancel', payload)
```

Binding properties, each test-proven:

- **One named transition per verb.** `transition()` accepts only the four named verbs; there is no
  generic status setter, no `supersede` action and no `supersede` URL is ever constructed (supersede
  is a consequence of approval, applied server-side).
- **Local state is written only from the server's response.** Every refusal path (validation,
  forbidden, `step_up_required`, `maker_checker_violation`, `invalid_state_transition`,
  `compensation_plan_overlap`) leaves `plans`/`current` untouched — a rejected approval can never
  leave the screen showing a state the backend did not grant.
- **No authoritative money is computed in JavaScript.** The store holds integer minor units and the
  screen formats them for display. A test asserts the store exposes no key matching
  `earning|payout|ledger|liability|settle|wallet|accrual`.
- **Stale context is dropped.** `$reset()` clears plans/rules/history/filters and the page watches
  `authStore.branchIds`, so a branch change cannot leave another branch's configuration on screen.
- **`compensation_model` is filtered client-side over the loaded page**, deliberately, because the
  contract declares no such query parameter — inventing one was rejected.

### Screen

`resources/spa/src/pages/hr/Compensation.vue` (list + detail/history + two draft forms + one
confirmation dialog, all through `Sv*` components — no parallel design system).

| Surface | Behaviour |
|---|---|
| List | staff display name, status badge, backdated badge, pending-approval indicator, model label, declared salary terms, commission-rule summary, preferred-fee basis copy, effective window, capability-gated actions |
| Filters | status, staff member, compensation model |
| Detail | public ULID, staff display name, model, salary terms + payout day, rule terms, preferred-fee basis, effective window, change reason, append-only history |
| Commission-rule form | type / basis points *or* fixed+currency (mutually exclusive) / basis / applies-to / category when required / preferred-fee inclusion toggle / effective window / reason |
| Plan form | staff / model / salary block *or* rule select per F1 shape / effective window / reason |
| Transitions | submit · approve · reject · cancel, each with a mandatory reason and its own copy |

**Capabilities gate affordances, permissions gate controls, the backend decides.** Every action is
rendered only when the holder has the key AND `capabilities.can_*` is true; a terminal plan shows
`Read-only` and no controls.

**Fresh step-up UX.** The approve dialog states that approval requires a fresh step-up. A
`step_up_required` / `approval_requires_fresh_step_up` response is surfaced as *"Approving a
compensation change requires a fresh step-up verification. Verify again, then approve."* — the SPA
follows the established repository pattern (surface the server's refusal; no silent auto-retry) and
**never fakes success**. No step-up secret is stored in Pinia, local/session storage or any fixture.

**Maker/checker UX.** The submit dialog states up front that the submitter can never approve.
`maker_checker_violation` renders *"A different HR approver must approve this plan."*

**Backdated approval UX (F8).** A plan whose `is_backdated` is true shows a warning block, an impact
preview summary and a **required** acknowledgement checkbox; approval is blocked client-side until it
is ticked, and `acknowledge_impact_preview: true` is sent so the server builds and records the
authoritative preview. The preview itself is never composed client-side and never sent.

**Error handling.** Field errors bind to their controls; everything else becomes one safe summary.
Mapped codes: `step_up_required`, `approval_requires_fresh_step_up`, `maker_checker_violation`,
`backdated_approval_requires_impact_preview`, `invalid_state_transition`, `compensation_plan_overlap`.
List/detail/action/loading/empty/forbidden/no-permission states all exist. A foreign branch/tenant
ULID is a backend 404/403 and surfaces as a safe message.

**Copy.** Canonical terms only (Compensation plan, Commission rule, Salary only, Commission only,
Salary plus commission, Pending approval, Scheduled, Active, Superseded, Expired, Rejected,
Cancelled, Backdated approval, Impact preview, Preferred-personnel fee included/excluded in commission
basis). A Vitest assertion and a Playwright assertion both fail the build if the rendered screen ever
contains `earned`, `payout`, `ledger`, `payable`, `settled`, `settlement` or `wallet`.

### Defects found and fixed (root cause, not symptom)

6. **DEF-20F-015 (generated-contract drift — the contract lied about nullability).** `vue-tsc`
   rejected store fixtures that mirror real server responses: *"Type 'null' is not assignable to type
   'string'"* for `effective_to`, `approved_at`, `submitted_at`, `rejected_at`, `salary_period`,
   `from_status`, `occurred_at`.
   **Root cause:** the OpenAPI generator infers nullability from an explicit `=== null ? null : …`
   ternary but **not** through the nullsafe `?->` operator. The three Phase 20F resources wrote those
   fields with `?->`, so `docs/api/openapi.json` published them as non-nullable `"type": "string"`
   while the server genuinely returns `null` (an open-ended rule, an unapproved plan, a
   `commission_only` plan's salary period, the `created` history event's `from_status`). Proof the
   diagnosis is the operator and not the field: `PreferredPersonnelFeeRuleResource` writes the *same*
   `effective_to`/`approved_at` fields with the explicit ternary and correctly publishes
   `["string","null"]`.
   **Why this is the root cause, not the symptom:** the symptom was a TypeScript error, but the defect
   was the *published contract* — any consumer, not just this SPA, was told a nullable field is
   always present.
   **Fix (smallest correct change):** converted only the genuinely-nullable Phase 20F resource fields
   to the explicit null ternary — a contract-truth change with **no runtime behaviour change** (the
   emitted JSON is byte-identical). Regenerated `openapi.json` + `api.ts`. Path/operation counts are
   unchanged (207/248), confirming the fix touched schema nullability only.
   **Files:** `CompensationPlanResource.php`, `CommissionRuleResource.php`,
   `CompensationPlanHistoryResource.php`.
   **Verification:** `vue-tsc` clean; `api:contract:check` OK 207/248; `OpenApiContractTest`,
   `CompensationPlanApiTest` (50), `CommissionRuleApiTest` (36) all green after the change.
   **Not fixed here (out of scope, recorded):** `PlatformFeeConfigurationResource` (Phase 20E) has the
   same `?->` drift on `effective_to`/`approved_at`. Pre-existing, not Phase 20F's to change.
7. **DEF-20F-016 (implementation defect — misleading copy in my own screen).** The page intro read
   *"nothing here is earned, calculated or paid"* — a disclaimer, but it put a §11-forbidden term on a
   compensation screen and tripped the terminology guard. **Root cause:** negation is not exemption;
   the rule is that the vocabulary must not appear, because a reader skimming a badge/summary does not
   parse the negation. **Fix:** reworded to *"Everything here is a configured term that will apply from
   its effective date — no amounts are calculated here."* The guard now passes on substance.
8. **DEF-20F-017 (test defect — a stub that hid the assertion).** Three component tests asserting
   dialog copy failed. **Root cause:** my `SvModal` stub rendered only `<slot />`, silently dropping
   the `title`/`description` props the real component renders — the tests were asserting against copy
   the stub never emitted. **Fix:** the stub now renders title + description like the real component.
   No assertion was weakened or deleted.
9. **DEF-20F-018 (implementation defect — dead import).** `vue-tsc` flagged `isTerminalPlanStatus` as
   imported-but-unused in the page (the screen uses the server's `capabilities.is_terminal` instead of
   re-deriving it client-side — which is the correct call). **Fix:** dropped the import. The helper
   remains exported and unit-tested for store consumers.
10. **DEF-20F-019 (accessibility defect — light-only colour tokens, serious/WCAG 2 AA 1.4.3).** axe
    reported **serious** contrast violations. Three distinct instances, one root cause.
    **Root cause:** `--color-heading` is the *adaptive* heading token (`#4a2208` light → `#f3f4f6`
    dark), while `--color-brand-deep` is `#4a2208` and is **deliberately never overridden in dark**
    because it is the CTA-text-on-orange colour (ADR-009). Using `brand-deep` as a foreground on a
    background that *does* adapt therefore renders dark-on-dark. Likewise `--color-warning`
    (`#f59e0b`) is the one status token with no dark override, and it fails AA on a light surface at
    badge text size.
    - **(a) My page headings** (`h1` "Compensation", `h2` "Commission rules") used `text-brand-deep`
      → **1.28:1** on the dark background. Fix: `text-heading`.
    - **(b) The shared `SvModal` title** (`#modal-title`) used `text-brand-deep` → **1.07:1** on the
      dark surface. This is a **pre-existing defect in a shared component** that Phase 20F's dialogs
      are simply the first to axe-scan in dark mode. Fixed here because §15/§16 require it and the
      only alternative was suppressing the rule (forbidden). The change is dark-only — in light
      `--color-heading` and `--color-brand-deep` are the same value — and the full Playwright suite
      (364) confirms no regression to any other dialog.
    - **(c) My status badges.** `bg-warning/15 text-warning` → **2.14:1** on white
      (`Pending approval`, `Backdated`, and the history `Backdated approval` marker);
      `bg-primary/15 text-brand-deep` → **1.07:1** in dark (`Scheduled`), because a /15 tint over the
      dark surface *is* dark. Fix: both take the adaptive `text-text` token; the tint still carries
      the semantic hue and the label text still carries the status, so status is never conveyed by
      colour alone. `draft` correctly **keeps** `bg-cream text-brand-deep` — `--color-cream` has no
      dark override either, so that pair stays brand-dark-on-cream (AA) in both themes.
    **Test-coverage defect fixed alongside (this is how it slipped through):** the first axe pass
    scanned a fixture containing only a `draft` plan and never opened the detail/history dialog, so
    four of the five badge variants were never rendered under axe at all. The suite now renders **all
    five statuses at once** and axe-scans the list **plus all three dialogs** (detail+history, plan
    form, backdated approval) in **both** themes — the broadened coverage caught the `Scheduled`
    badge immediately on the next run. **No axe rule was suppressed or narrowed.**
    **Not fixed here (out of scope, recorded):** the same two patterns exist repo-wide —
    ~109 `text-brand-deep` vs ~115 `text-heading` occurrences under `pages/**` (a ~50/50 split), and
    `bg-warning/15 text-warning` in `StaffList.vue`. Pre-existing; a blanket replace would be wrong
    because `text-brand-deep` is *correct* on orange/cream backgrounds.

### Test evidence

| Gate | Result |
|---|---|
| ESLint (`npm run lint`) | **0 errors** (138 pre-existing style warnings; the new files contribute **none**) |
| `vue-tsc --noEmit` | **clean** — strictness not lowered, no `@ts-ignore` added |
| Vitest (full) | **404 passed / 84 files** (was 352/82 → **+52**, 0 failed) |
| — `compensationStore.spec.ts` | **20 passed** |
| — `Compensation.spec.ts` | **32 passed** |
| `roleNavigation.spec.ts` + `screenInventory.spec.ts` | **17 passed** (fixtures regenerated, then green without `-u`) |
| `npm run api:contract:check` | **OK — 207 paths, 248 operations** (unchanged from Increment 4) |
| `npm run build` (vue-tsc + vite) | **PASS** |
| **Playwright `tests/e2e/phase-20f.spec.ts`** | **40 passed** |
| **Playwright (FULL suite)** | **364 passed** (was 324 → **+40**) — run in full because `SvModal` is shared; **no regression** |
| Pint | **PASS, 1334 files** (3 Resources changed) |
| Larastan level 8 | **No errors (1029 files)** — level not lowered, no ignores added |

Backend reruns after the Resource change (the contract fix touched real server code, so these are not optional):

| Gate | Result |
|---|---|
| `CompensationPlanApiTest` | **50 passed / 189 assertions** |
| `CommissionRuleApiTest` | **36 passed / 106 assertions** |
| `OpenApiContractTest` | **9 passed / 15 assertions** |
| `AuditMutationCoverageTest` | **5 passed / 12 assertions** |
| `PermissionMatrixTest` + `…ParityTest` + `…SchemaTest` + `…PlanMetadataParityTest` + `…CatalogueCompletenessTest` + `RouteSecurityContractTest` | **21 passed / 481 assertions** |

**Zero-selection honesty note:** `php artisan test --filter=PermissionMatrixTest` returned
`INFO No tests found.` — it selected **zero** tests and is therefore **not** reported as green. The
suites above were re-run **by file path**, which selected 21 real tests. `PermissionMatrixParityTest`
(the name given in the Increment 5 brief) exists and is included in that path-based run.

### Browser proof (Playwright, real frontend against a stubbed API)

| Requirement | Evidence |
|---|---|
| Responsive 360 / 768 / 1280 | no page-level horizontal overflow at any width, list + status badge operable, ×2 themes |
| 200% zoom | `document.documentElement.style.zoom = '2'` — plan dialog opens, model select and Save remain reachable, no page-level overflow |
| Keyboard | dialog opened with Enter from the trigger; full keyboard-only submit → confirmation flow |
| Focus restoration | Escape closes the dialog and focus returns to the invoking control (`toBeFocused`), also asserted at component level |
| Light + dark | list rendered at 3 widths × 2 themes; all three dialogs axe-scanned in both themes |
| axe | **serious + critical = 0** on the list (all five status badges rendered) and on the detail+history, plan-form and backdated-approval dialogs, in both themes |
| Role denial | merchant_admin, branch_manager, finance, front_office, personnel, audit — none reach `hr.compensation`; HR without `compensation.plan.view` also denied; view-only HR gets no mutation control |
| Maker/checker + step-up | denials surfaced from the server envelope; plan status stays `Pending approval` — approval is never faked |

### Proof of what was NOT built

```text
Merchant Administrator compensation summary : NOT built — nav item `planned`, route null,
                                              permission owning_phase Phase 20H
Branch Manager compensation configuration   : NOT built — no compensation key in any branch nav
Personnel earnings statement                : NOT built — no route, no store, no permission
Finance compensation liability              : NOT built — compensation.liability.view is Phase 20G planned
commission ledger UI / salary ledger UI     : NOT built — no component, no store action, no endpoint
payout UI                                   : NOT built — payout_run.create remains Phase 20H planned
Wallet / provider UI                        : NOT built — no Wallet surface exists anywhere in 20F
supersede control                           : NOT built — deliberately; supersede is a consequence of
                                              approval. No supersede URL is ever constructed (tested).
```

Enforced, not merely asserted: the store test fails if any action key matches
`earning|payout|ledger|liability|settle|wallet|accrual`; the component test fails if the rendered
screen contains `earned|payout|ledger|payable|settled|wallet|settlement`; the Playwright suite fails
if a `Supersede`/`Payout`/`Earnings`/`Ledger`/`Liability`/`Wallet` control is present.

### Remaining risks (carried to Increment 6)

1. **Multi-branch HR still fails closed** (carried from Increment 4). `resolveActingBranch()` 403s
   when the acting context resolves more than one branch. The screen surfaces the server's message
   safely rather than widening branch access, and exactly-one-branch HR — the real case — works. A
   branch selector remains the right answer **if** multi-branch HR is ever real; inventing one now
   would widen the surface without a requirement. **Unchanged by this increment.**
2. **The impact preview shown before approval is descriptive, not the recorded artifact.** The
   `CompensationPlanResource` exposes no preview field, so the dialog composes its acknowledgement
   copy from the plan's own returned facts (subject, model, effective date) and says so explicitly:
   *"The recorded impact preview is built by the server when you approve."* The **authoritative**
   preview is built server-side by `BuildCompensationPlanImpactPreview` and is never accepted from
   the client (F8). If the product wants the approver to see the *exact* recorded preview before
   committing, that needs a backend preview endpoint — a new route, out of scope here.
3. **`selected_services` still has no membership substrate** (carried from Increment 2). The screen
   offers it as a stored `applies_to` value; there is no service-selection UI because there is no
   substrate to select against.
4. **Repo-wide colour-token drift is unfixed** (DEF-20F-019). Phase 20F's own surfaces and `SvModal`
   are AA-clean in both themes and proven so; ~109 other `text-brand-deep` usages and
   `StaffList.vue`'s warning badges are pre-existing and unaudited. Most existing e2e axe tests never
   open dialogs or render every badge variant, so the true blast radius is unknown until swept.
5. **Boundary transitions still have no scheduler** (carried from Increment 3): a `scheduled` plan
   becomes `active` by domain action with no cadence. Resolution stays date-correct because it
   matches the effective window rather than the stored status, but the list can show `Scheduled` for
   a plan whose window has opened.
6. **The e2e drives the real frontend against a stubbed API** (repository convention, per
   `phase-20e.spec.ts`). Genuine authorization, branch scope, step-up freshness, maker/checker and
   the backdated business-date computation are proven by the backend Feature suite
   (`tests/Feature/Compensation/*`, 290 tests), not by these browser tests — which prove frontend
   behaviour, role gating, copy and accessibility.

**Next action:** Increment 6 — full local gates, documentation finalization, single completion commit
and push.

**Working tree:** dirty with the Increment 1–5 changes above. **No commit, no push, no PR has
occurred.**

---

## Increment 6 — full local gates, documentation finalization, scope-purity audit, completion commit

### Deferred follow-ups (product-owner decision — NOT Phase 20F work, NOT Phase 20F blockers)

Both items below were discovered *during* Phase 20F and are real. Both were **deliberately not
executed** in this branch: each is repo-wide, affects unrelated domains, and would fold foreign
cleanup into the Phase 20F completion commit, breaking the one-phase/one-reviewed-branch rule
(CLAUDE.md §5) and the reviewability of this diff. Each requires its own product-owner-authorized
branch/PR **after** Phase 20F local completion.

#### Deferred follow-up 1 — repo-wide nullable Resource/OpenAPI truth sweep

```text
Deferred follow-up:
Repo-wide nullable Resource/OpenAPI truth sweep.

Reason:
Phase 20F corrected the Phase 20F Resources required for HR Compensation.
A broader sweep affects many Resources and generated frontend consumers across
unrelated domains and must be handled in a separate branch/PR.

Known evidence:
OpenAPI/Scramble infers nullability correctly from explicit
$x === null ? null : ... ternaries, but not reliably from ?-> in Resources.

Known example:
PlatformFeeConfigurationResource nullable fields from Phase 20E.

Not a Phase 20F blocker:
The Phase 20F screen, OpenAPI, api.ts, vue-tsc, and contract gates are green.
```

**Measured blast radius (read-only audit, Increment 6 — no file was changed):** 127 `?->` occurrences
across 56 Resource files; **92 direct nullable Resource expressions across 29 Resources** where the
published schema declares `"type": "string"` **and** lists the field as `required: true` while the
server can genuinely emit `null`. `PlatformFeeConfigurationResource` is only 4 of those 92 — it is
not a special case, merely the first one spotted. Because all 92 are `required: true`, correcting
them changes the generated `api.ts` for many unrelated SPA consumers and would likely cascade into
`vue-tsc` failures in unrelated screens — which is the *value* of the sweep, and precisely why it
cannot ride inside the Phase 20F completion commit.

**Scope decision recorded:** **0 of 92 fixed in this branch.** `PlatformFeeConfigurationResource` was
deliberately **not** fixed here either — it is Phase 20E, is not required by the HR Compensation
screen, and fixing it would fold a Phase 20E contract change into the Phase 20F commit while still
leaving the other 88 fields wrong.

#### Deferred follow-up 2 — repo-wide dark-mode heading/warning-badge contrast sweep

```text
Deferred follow-up:
Repo-wide dark-mode heading and warning-badge contrast sweep.

Reason:
Phase 20F corrected HR Compensation and the shared SvModal issue required by
HR Compensation dialogs. A broader token audit affects unrelated screens and
must be handled in a separate accessibility branch/PR with dedicated axe coverage.

Known evidence:
text-brand-deep fails on adaptive dark surfaces.
text-warning fails as small badge foreground on light surfaces.
Some existing tests do not render every badge/dialog state.

Not a Phase 20F blocker:
HR Compensation has axe serious/critical = 0 and full Playwright passed.
```

**Why a blanket replace would be wrong:** `text-brand-deep` is *correct* on orange/cream backgrounds
(`--color-primary` / `--color-cream` are not dark-overridden either), so the fix needs per-screen
judgement. Phase 20F's own `draft` badge legitimately keeps `bg-cream text-brand-deep` for exactly
this reason.

**What Phase 20F did fix, and why it was in scope:** `pages/hr/Compensation.vue` (its own headings and
badges) and the shared `components/ui/SvModal.vue` title — the latter only because Phase 20F's
dialogs depend on it and axe serious/critical could not be suppressed (§16). That shared change was
proven regression-free by the **full** Playwright suite (364 passed).

### Full local gates (Increment 6 — every gate re-run, none inherited from an earlier increment)

| Gate | Result |
|---|---|
| `composer validate --strict` | `./composer.json is valid` |
| Pint | **PASS, 1334 files** |
| Larastan level 8 (`composer stan`) | **[OK] No errors** (1029 files) — level not lowered, no ignores added |
| **Full backend suite (serial)** | **1469 passed / 7 skipped / 0 failed / 8644 assertions** (775.93s) |
| **Full backend suite (`--parallel`, 4 processes)** | **1469 passed / 7 skipped / 0 failed / 8644 assertions** — identical to serial |
| ESLint | **0 errors** (138 pre-existing style warnings; Phase 20F files contribute none) |
| `vue-tsc --noEmit` | **clean** |
| Vitest | **404 passed / 84 files** |
| `npm run build` (vue-tsc + vite) | **PASS** — built in 15.46s |
| **Full Playwright** | **364 passed** |
| axe (HR Compensation list + all three dialogs, light + dark) | **serious = 0, critical = 0** |
| `composer audit --locked` | **No security vulnerability advisories found** |
| `npm audit --audit-level=high` | 2 moderate, **exit 0** (below the high+critical gate) |
| gitleaks (`detect --no-git --redact`) | **no leaks found** (16.88 MB scanned) |
| `docker compose build app` (dev) | `servana-app:dev` **Built** |
| `docker compose -f docker-compose.prod.yml build app` | `servana-app:prod` **Built** |
| `docker compose -f docker-compose.prod.yml build nginx` | `servana-nginx:prod` **Built** |

### Contract determinism (proven by hash, not asserted)

`servana:openapi` → `api:types` → `servana:permission-types` were run **twice**. All three artifacts
hashed **identically** at baseline, after pass 1, and after pass 2 — so the committed contracts are
exactly what the generators produce, and the generators are deterministic:

```text
openapi.json    1B71A99A530D1F4FC49FE05E0F8E9C15F6410305458FD7A8947D7BA34DCAD07D  (baseline = pass 1 = pass 2)
api.ts          DE7A5955784FA4712472A4884F3D432D5CB2EDC427DE73F664046C06E8CD72C2  (baseline = pass 1 = pass 2)
permissions.ts  2B8C1044D8CE0A43C2645BCAFFDA794A5B569F1AFFB979D66DE8C10FFD163A27  (baseline = pass 1 = pass 2)

servana:permission-types --check   permissions.ts is up to date   (exit 0)
api:contract:check                 OK — 207 paths, 248 operations (exit 0)
OpenAPI generator                  248 production routes
```

### Disposable PostgreSQL 16 fresh-build proof (re-run in Increment 6)

Never run against the dev database — a throwaway database was created, verified, and dropped.

```text
database:                servana_p20f_i6_proof (created + owned by servana, dropped after verification)
PostgreSQL version:      16.14
migrate:fresh --seed:    all migrations DONE + PermissionSeeder DONE (24,515 ms)
total migrations:        99
Phase 20F migrations:    3 (2026_07_14_000001..000003)
20F tables present:      3/3
exclusion constraints:   1 (personnel_compensation_plans_no_overlap)
20F triggers:            4 (2 immutability + 2 append-only)
20F rows after seed:     0/0/0 (no backfill; the aggregate is inert until HR configures it)
forbidden 20G/20H tables: 0  (salary_ledger, commission_ledger, compensation_adjustments,
                              personnel_payout_runs, personnel_payout_items,
                              personnel_earnings_queries, earnings_statements — all absent)
cleanup:                 DROP DATABASE verified (0 rows in pg_database)
dev database:            untouched (servana still present)
```

Every figure matches the Increment 2 proof exactly — the schema is reproducible from zero.

### Scope-purity audit (before staging)

Every changed path classifies into an authorized category:

| Category | Paths |
|---|---|
| Phase 20E `verified_complete` reconciliation | `docs/proof/phase-20e.md` |
| Phase 20F documentation/proof | `docs/proof/phase-20f.md`, `docs/CHANGELOG.md`, `docs/PROGRESS.md`, `docs/traceability/…csv`, both data dictionaries, `migrations/manifest.yaml`, both state-machine specs |
| Phase 20F migrations/schema | 3 migrations (`2026_07_14_000001..000003`) |
| Phase 20F enums/models/factories/tenancy | `app/Domain/Compensation/{Enums,Models}`, 3 factories, `TenantOwnership.php` |
| Phase 20F domain actions/resolvers/audit | `app/Domain/Compensation/{Actions,Services,ValueObjects,Exceptions}`, `AuditEvent.php`, `AuditMutationCoverage.php` |
| Phase 20F permissions/API/security | `PermissionRegistry.php`, `StepUpAction.php`, `AppServiceProvider.php`, `routes/api.php`, controllers, requests, policies, `permission-matrix.yaml`, `phase8-matrix.txt`, 3 permission tests |
| Phase 20F generated contracts | `openapi.json`, `api.ts`, `permissions.ts` |
| Phase 20F frontend screen | `Compensation.vue`, `compensationStore.ts`, `roleNavigation.ts`, `router/routes/hr.ts`, `inventory.json`/`.yaml`, `role-navigation.yaml`, `hr-compensation.md` |
| Phase 20F frontend tests | `Compensation.spec.ts`, `compensationStore.spec.ts`, `tests/e2e/phase-20f.spec.ts` |
| Phase 20F narrow Resource nullability correction | `CompensationPlanResource`, `CommissionRuleResource`, `CompensationPlanHistoryResource` |
| Phase 20F narrow SvModal accessibility correction | `components/ui/SvModal.vue` |
| Phase 18B test-helper timestamp correction | `tests/Pest.php`, `tests/Feature/Finance/CashUpWorkflowTest.php` |

**Excluded work verified ABSENT from the diff** (checked by path, not by claim):

```text
app/Http/Resources/PlatformFeeConfigurationResource.php   ABSENT  (repo-wide nullable sweep deferred)
resources/spa/src/pages/hr/StaffList.vue                  ABSENT  (repo-wide token sweep deferred)
any other Phase 17/18/19/20A/20B/20C/20E Resource         ABSENT
salary/commission ledger, adjustments, payouts, earnings  ABSENT
Merchant Administrator compensation summary               ABSENT
Wallet/provider runtime, Phase 20D-W/20G/20H              ABSENT
node_modules, vendor, .env, secrets                       ABSENT
test-results/, playwright-report/, coverage reports       ABSENT (test-results/ is gitignored;
                                                                  playwright-report/ not present)
```

The Phase 18B `paid_at` / `finalized_at` corrections are test-helper-only: both store the **instant**
(`CarbonImmutable::now()`) exactly as the production recording/finalize paths do, instead of a Nairobi
wall-clock that PostgreSQL would read verbatim in the UTC session — which made the cash-up business
date land on *tomorrow* whenever the suite ran between 21:00 and 23:59 Nairobi. No production code and
no response value changed.

## Solo-Maintainer Review Exception - PR #39

- PR: #39
- implementation commit: a42e13e66413a27020a07180d1fb7a8b7cda2f27
- verified corrective head: d8bc799468428091cb2aa97a61cbc5cdad269706
- successful corrective CI run: 29578358637
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E - Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record: docs/governance/solo-maintainer-review-exception-pr-39.md

The initial CI failures were corrected by test-only commit d8bc799468428091cb2aa97a61cbc5cdad269706. Full local backend verification and all five corrective CI checks passed.

This exception applies only to Phase 20F and is not independent reviewer approval.

Phase 20G, Phase 20H, Phase 20D-W and later Wallet, salary, commission, payout, settlement, referral, notification, SMS, search, performance and production-readiness domains remain deferred to their documented owning phases.
