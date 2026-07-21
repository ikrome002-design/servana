# Phase 20H — Payout Runs and Earnings — Proof

> Lifecycle: **in_progress** (branch `phase-20h-payout-runs-earnings`, based on `origin/main` =
> `dcdbfb69f338f1cbdf13c0a0b507ef600cfe7f14` = the Phase 20G PR #41 squash-merge commit).
> Increment 1 (verification, reconciliation, specification) in progress. **No migration, no commit,
> no push, no PR yet.**
>
> Specification-first, per the Phase 20A/20C/20E/20F/20G precedent: **no migration is created until
> every material gate (H1–H18) is resolved from the Plan, Scope, live matrix, and live repository
> evidence, and the data dictionary + state machines are written.**
>
> Controlling sources: **Plan §62** (Payout Runs, Correction 19.6–19.7), **Plan §63** (Earnings
> Statements and Queries, Correction 19.8–19.9), **Plan §13.12** (canonical compensation DDL —
> `personnel_payout_runs`, `personnel_payout_items`, `earnings_queries`), **Plan §25.5** (payout-run
> `approved → paid` state-machine transition), **Plan §25.4** (ledger/payout state machines),
> **Plan §65** (Files and Media / 10F — earnings statement PDFs), **Plan §80** (Phase 20H roadmap
> entry), **Plan §10.2** (authority boundaries), **Plan §19.2/§19.3** (permission catalogue/matrix),
> **Plan §24** (idempotency/concurrency), **Plan §69** (reporting), **Plan §70** (audit),
> **Plan §71** (observability); ADR-002 (tenancy), ADR-004 (forward-only migrations), ADR-005
> (integer money), ADR-010 (no personnel contact export).
>
> **Phase 20H consumes the financial facts Phase 20G created** (`salary_ledger`,
> `commission_ledger`, `compensation_adjustments`) and produces internal payout workflow and
> personnel earnings surfaces. **Servana does not move money.** No Wallet/provider runtime, no STK /
> PayBill / Till / C2B / Daraja / provider callbacks, no settlement, no direct fund movement.
> Mark-paid records that an **external** payment already happened — it never calls a provider and
> never depends on Wallet Gate W (which remains CLOSED).

---

## 0. Repository boundary (Increment 1 preflight)

| Fact | Value |
|---|---|
| Canonical repo | `C:\Users\nderu\Documents\Development\Product\Servana` |
| Branch | `phase-20h-payout-runs-earnings` |
| Base / `origin/main` | `dcdbfb69f338f1cbdf13c0a0b507ef600cfe7f14` (Phase 20G PR #41 squash merge) |
| Merge base | `dcdbfb69f338f1cbdf13c0a0b507ef600cfe7f14` |
| Divergence at branch creation | `0 0` |
| Git integrity (`git fsck --full`) | clean (one dangling commit `fd95305…` = deleted 20G branch history; harmless) |
| Runtime | PHP 8.3.32 · Laravel 12.62.0 · PostgreSQL 16.14 · Docker stack all `running` |
| Gate W | **CLOSED** (`docs/integrations/wallet/` absent) → Phase 20D-W remains blocked → **Phase 20H is the next executable phase** |

---

## H1 — Prior-phase reconciliation (Phase 20G → verified_complete)

**Verified evidence:**

| Check | Result |
|---|---|
| PR #41 state | **MERGED** into `main` |
| PR #41 title | "Phase 20G: Implement salary and commission ledgers" |
| PR #41 base | `main` |
| PR #41 merge commit | `dcdbfb69f338f1cbdf13c0a0b507ef600cfe7f14` == `origin/main` |
| PR #41 merged at | `2026-07-20T11:54:59Z` |
| PR #41 final head (pre-squash) | `20260e850c465ef2517f356e0c1fbb984fe2a6ed` |
| Phase 20G implementation commit | `51ebb5dd0c44c858c7afadd828dea5891da17fa0` (per PROGRESS/session record; squashed into `dcdbfb6`) |
| Required checks | Backend / Frontend / Docker / Security / E2E — **all COMPLETED + SUCCESS** |
| `reviewDecision` | **blank** — solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-41.md`), **not** independent approval |
| Local `main` == `origin/main` == merge commit | yes (`0 0`) |
| Phase 20G branch | deleted locally **and** on `origin` after merge |

**Decision:** Phase 20G is reconciled from `local_complete pending PR` to **`verified_complete`** in
`docs/PROGRESS.md` and `docs/CHANGELOG.md`, per the established convention that the next branch
reconciles the previous phase. The truthful governance note (solo-maintainer exception, not
independent review) is **preserved, not rewritten**. Phase 20G history is not rewritten as
independently reviewed.

---

## H2 — Gate W and sequencing

| Question | Resolution |
|---|---|
| Gate W status | **CLOSED** — `docs/integrations/`, `docs/integrations/wallet/`, and `gate-w-evidence.md` are absent (no collections-slice evidence, sandbox credentials, pinned Wallet OpenAPI hash, contract suite, STK/C2B transcript, signed-webhook transcript, reconciliation transcript, or explicit PASS). |
| Phase 20D-W | remains **blocked** while Gate W is CLOSED. |
| Why 20H is next | 20H depends only on merged Phase 20G ledgers + Phase 8/15B staff substrate + Phase 20A `merchant_subscriptions` threshold column + Phase 10F file subsystem — **none of which is Gate W-gated**. The v4 sequencing rule ("if Gate W is not open, continue to the next non-Wallet phase and return to 20D-W when Gate W opens") makes 20H executable now. |
| Inherited from 20G-skipped | payout runs/items, HR draft/submit, Finance verify/approve/mark-paid, MA high-value approval, ledger paid-state linkage, personnel earnings/tabs/history, earnings statements/PDFs, earnings queries, MA compensation summary, high-value threshold snapshot. |
| Remains outside 20H | Wallet/provider runtime (20D-W), scheduled report delivery + notification center (21N), personnel bulk SMS (21S), search (22), release-wide audits (23), performance (24), deployment/alerting/runbooks (25). |

---

## H3 — Schema and ownership

Canonical DDL: **Plan §13.12**. Ownership follows the live repository tenancy pattern (ADR-002):
every table carries `merchant_id` + `branch_id` and composite-consistency FKs
`(branch_id, merchant_id) → merchant_branches(id, merchant_id)`, exactly as 20G's
`compensation_adjustments` does.

### New tables (forward-only, ADR-004)

**`personnel_payout_runs`** — **branch-owned** (`BelongsToMerchant` + `BelongsToBranch`).
- `id`; `ulid` char(26) unique (public key, `getRouteKeyName='ulid'`); `merchant_id` FK RESTRICT;
  `branch_id` FK RESTRICT + composite `(branch_id, merchant_id)` FK.
- `period_start` date; `period_end` date; **CHECK `period_end >= period_start`**.
- `currency` char(3) **[schema completion — see decision below]**; CHECK `upper=self, len=3`.
- `high_value_threshold_snapshot_minor` bigint nullable (snapshotted from merchant settings at
  creation; CHECK null-or-`>= 0`).
- `status` varchar CHECK in
  `('draft','submitted','finance_verified','pending_merchant_admin_approval','approved','paid','rejected','cancelled')`.
- `gross_total_minor` bigint (signed — clawbacks may net negative).
- `created_by` (HR user) FK; `submitted_by`, `verified_by` (Finance), `approved_by`, `paid_by`
  nullable FKs; `rejected_by` nullable FK + `rejection_reason` text nullable.
- `external_payment_reference_encrypted` text nullable (encrypted at rest; never logged);
  `paid_at` timestamptz nullable.
- `created_at` / `updated_at`.
- Indexes: `(merchant_id, branch_id, status)`, `(merchant_id, branch_id, period_start, period_end)`.

**`personnel_payout_items`** — **branch-owned**, child of a run.
- `id`; `ulid` char(26) unique; `merchant_id` FK RESTRICT; `branch_id` FK RESTRICT + composite FK;
  `payout_run_id` FK **RESTRICT** + composite `(payout_run_id, merchant_id)` FK; `staff_profile_id`
  FK RESTRICT + composite `(staff_profile_id, merchant_id)` FK.
- `currency` char(3) **[schema completion]** — equals the parent run currency (enforced in the
  snapshot action; no cross-currency item).
- `salary_amount_minor`, `commission_amount_minor`, `adjustment_amount_minor`, `gross_amount_minor`
  bigint (all signed; `gross = salary + commission + adjustment`).
- `source_ledger_refs` jsonb (exact snapshotted row identities: `{salary:[ids], commission:[ids], adjustment:[ids]}`).
- `status` varchar mirroring the run
  `('draft','submitted','finance_verified','pending_merchant_admin_approval','approved','paid','rejected','cancelled')`.
- `created_at` / `updated_at`.
- Unique `(payout_run_id, staff_profile_id, currency)` — one item per staff per currency per run.
- Index `(merchant_id, staff_profile_id)`.

**`earnings_queries`** — **branch-owned + personnel own-scope** (`staff_profile_id`).
- `id`; `ulid` char(26) unique; `merchant_id` FK RESTRICT; `branch_id` FK RESTRICT + composite FK;
  `staff_profile_id` FK RESTRICT + composite FK (own).
- `subject_type` varchar CHECK in `('commission_ledger','salary_ledger','payout_item')`;
  `subject_id` bigint (validated in-scope by the action, not a polymorphic FK).
- `query_type` varchar CHECK in a fixed enum (see H12); `body` text.
- `status` varchar CHECK in `('open','assigned','resolved','rejected')`.
- `assigned_to` nullable FK users; `assigned_role` varchar nullable (`finance`|`hr` routing);
  `resolution_note` text nullable; `resolved_adjustment_id` nullable FK
  `compensation_adjustments` (set only when resolution created a monetary adjustment);
  `responded_by` nullable FK; `responded_at` timestamptz nullable.
- `created_at` / `updated_at`.
- Index `(merchant_id, branch_id, status)`, `(staff_profile_id, status)`.

### Expand migrations (add-constraint only; never edit a shipped migration)

Phase 20G created `commission_ledger.payout_item_id`, `salary_ledger.payout_item_id`, and
`compensation_adjustments.payout_item_id` as **nullable, un-constrained** columns whose append-only
guards already permit `payout_item_id` (and `status`) to transition. Phase 20H adds the FK by
**expand**:

```
ALTER TABLE commission_ledger      ADD CONSTRAINT ... FOREIGN KEY (payout_item_id) REFERENCES personnel_payout_items(id) ...
ALTER TABLE salary_ledger          ADD CONSTRAINT ... FOREIGN KEY (payout_item_id) REFERENCES personnel_payout_items(id) ...
ALTER TABLE compensation_adjustments ADD CONSTRAINT ... FOREIGN KEY (payout_item_id) REFERENCES personnel_payout_items(id) ...
```

Composite `(payout_item_id, merchant_id)` FK where the repo pattern applies (payout item carries
`merchant_id`), preserving tenant consistency.

### Decisions of record (H3)

- **D-H3-1 (currency completion):** The §13.12 summary omits a `currency` column on
  `personnel_payout_runs` / `personnel_payout_items`, but the **no-cross-currency-combination**
  invariant (§H18, Plan §62 "salary/commission/approved adjustments + gross") and the single
  `gross_total_minor`/`gross_amount_minor` fields make a currency dimension **mandatory**. Every
  other money table in the schema carries `currency`. A payout run is therefore **single-currency**;
  eligible-liability selection filters by the run currency; if a branch holds unpaid liability in
  more than one currency, HR creates one run per currency. This is an **additive schema completion
  consistent with the invariant**, not a Plan contradiction. (Launch is KES-only, so the practical
  effect today is nil; the column keeps the invariant enforceable.)
- **D-H3-2 (ledger claim = `payout_item_id` only; status advances forward only at mark-paid):**
  The shipped 20G `CommissionLedgerStatus`/`SalaryLedgerStatus` `allowedTransitions()` are
  **forward-only** (`earned/pending → included_in_payout → paid`) with **no** backward release to
  `earned/pending`. Therefore Phase 20H **claims** a ledger row by setting only its `payout_item_id`
  (which the DB guard lets move freely, including back to `NULL`) — **not** by changing its status.
  Claim happens at **submit (freeze)**; **release** on reject/cancel simply clears `payout_item_id`
  (status untouched); the ledger **status advances forward only at mark-paid**
  (`earned/pending → included_in_payout → paid`, both legal transitions, in one locked
  transaction). This honours the shipped 20G state machines with **no cross-phase enum/state-machine
  change**.

---

## H4 — Eligible liability snapshot source

Payout items snapshot **existing Phase 20G ledger facts**; they are **never** recomputed from
current plans/rules. Eligibility (all within `run.merchant_id`, `run.branch_id`, `run.currency`, and
the ledger business/pay-period date within `[run.period_start, run.period_end]`, `payout_item_id IS
NULL`):

| Source | Included when | Excluded |
|---|---|---|
| `commission_ledger` | `entry_type IN ('earned','adjustment')` **AND** `status = 'earned'` **AND** `payout_item_id IS NULL`, within run merchant/branch/currency and `earned_at::date` in period | `entry_type = 'reversal'` rows; `pending`/`included_in_payout`/`paid`/`reversed`/`adjusted`/`cancelled`; already-linked |
| `salary_ledger` | `entry_type IN ('accrual','adjustment')` **AND** `status = 'pending'` **AND** `payout_item_id IS NULL`, within run merchant/branch/currency and `pay_period_end` in period | `entry_type = 'reversal'` rows; `included_in_payout`/`paid`/`reversed`/`adjusted`; already-linked |
| `compensation_adjustments` | `payout_item_id IS NULL`, within run merchant/branch/currency and `created_at::date ≤ period_end` (approved by presence of `approved_by`; `manual`, `correction`, and already-paid `paid_commission_reversal`/`paid_salary_reversal` clawbacks — signed) | already-linked |

**Reversal netting (verified against 20G `ReverseCommissionEntry`/`ReverseSalaryAccrual`):** an unpaid
ledger reversal creates a negative `entry_type='reversal'` row **and** flips the original to
`status='reversed'`. Excluding **both** `entry_type='reversal'` rows and `status='reversed'`
originals nets a fully-reversed earning to **zero** while still paying any remaining `earned`/`pending`
originals — correct, without paying a phantom negative. Already-**paid** reversals never take this
path (the reverser throws on a paid original); they live in `compensation_adjustments` as signed
clawbacks and are picked up by the adjustments bucket.

**Invariants:** a ledger row is snapshotted **at most once** (enforced by `payout_item_id IS NULL`
guard + `FOR UPDATE` lock at submit + the composite FK); never across merchant/branch/currency
boundary; salary/commission/adjustments land in their own item buckets
(`salary_amount_minor`/`commission_amount_minor`/`adjustment_amount_minor`); `gross_amount_minor =
sum` (may net negative via clawbacks — D-H9-1). Facts are snapshotted, never recomputed from current
plans/rules.

---

## H5 — Payout run lifecycle (state machine)

Plan §25.4 / §25.5:

```
draft ──submit──▶ submitted ──verify──▶ finance_verified
  finance_verified ──(ordinary: gross ≤ threshold or threshold null)──approve_standard──▶ approved
  finance_verified ──(high value: gross > threshold snapshot)──▶ pending_merchant_admin_approval
      pending_merchant_admin_approval ──merchant approve_high_value──▶ approved
      pending_merchant_admin_approval ──reject──▶ rejected
  approved ──mark_paid──▶ paid   (terminal)
  {draft,submitted,finance_verified,pending_merchant_admin_approval} ──reject/cancel──▶ rejected/cancelled
  paid ──▶ (no status rewind; corrections only via a new adjustment run)
```

- HR: `create`/`update_draft`/`submit`/`cancel_draft` (draft only). Submit **freezes** the run.
- Finance: `verify` (submitted → finance_verified), `approve_standard` (ordinary), `reject`,
  `mark_paid`.
- Merchant Admin: `approve_high_value` (pending_merchant_admin_approval → approved), only after
  Finance verification.
- Rejection requires a reason; corrections happen via **rejection → new draft** or an **adjustment
  run**, never silent line edits.
- Invalid transitions fail closed → `422 invalid_state_transition` (typed
  `PayoutRunStateException`).

Items mirror the run status (H7). Runs are **not** period-locked (Plan §25.5
`period_lock_conditions: n/a`).

---

## H6 — High-value threshold (RESOLVED — existing substrate)

- **Source:** `merchant_subscriptions.high_value_payout_threshold_minor` (bigint nullable, CHECK
  null-or-`>= 0`) — created in Phase 20A migration
  `2026_07_11_000003_create_merchant_subscriptions_table.php`, on the `MerchantSubscription` model
  with an `integer` cast. **The substrate already exists — Phase 20H creates no threshold table and
  hardcodes nothing.**
- **Snapshot:** at run **creation**, the current (non-terminal) subscription's
  `high_value_payout_threshold_minor` is copied to
  `personnel_payout_runs.high_value_threshold_snapshot_minor`. Later threshold edits never
  retroactively change an existing run.
- **High-value test:** at verification, a run is high-value when
  `gross_total_minor > high_value_threshold_snapshot_minor`. **Null threshold ⇒ high-value gate
  inactive ⇒ ordinary Finance approval** (documented decision D-H6-1: a merchant that has set no
  threshold has, by the schema's own `nullable`, opted out of the high-value approval step; nothing
  is hardcoded and nothing is assumed).
- No new editing UI is required by Phase 20H (the column is settable through the existing merchant
  subscription substrate); adding a threshold editor is out of scope and not needed for the snapshot
  behavior.

---

## H7 — Payout item snapshot and freeze

- **Creation (draft):** the eligible-liability selector (H4) produces one item per
  `staff_profile × currency`; each item stores the bucketed sums + `source_ledger_refs` (exact row
  ids). This is a **snapshot preview** — source ledgers are **not** yet claimed.
- **Draft edit:** while `status = draft`, items may be **regenerated** (delete + re-snapshot) or the
  period/currency changed; nothing is frozen yet; ledgers are untouched, so no double-claim risk.
- **Submit (freeze):** in a single transaction with `payout_run + candidate ledger rows FOR UPDATE`,
  re-validate eligibility, then **claim** each source ledger row by setting its `payout_item_id`
  (status is **left unchanged** — see D-H3-2), and flip run + items `draft → submitted`. After submit
  the **item core columns are immutable** (DB guard allows only the `status` mirror transition); no
  silent line edits.
- **Reject / cancel (pre-paid):** release claimed ledgers — `payout_item_id → NULL` (status
  untouched) — so the liability returns to the eligible pool (`earned`/`pending`) for a new draft.
  (The 20G ledger guards permit `payout_item_id` to move to `NULL` independently of status; no
  backward status transition is needed or attempted.)
- **Immutability guard:** `personnel_payout_items` gets an append-only-style trigger blocking
  DELETE once `status != draft` and blocking mutation of core columns (only `status` may transition
  post-draft).

---

## H8 — Mark-paid

Plan §25.5 (exact):

- **Preconditions:** `run.status = approved` (high-value runs already passed MA approval);
  `merchants.status = active`; financial mutation allowed; **fresh step-up MFA**;
  `external_payment_reference` present; `paid_date` present; actor holds `payout_run.mark_paid`
  (Finance).
- **Transaction:** single transaction, `payout_run + payout_items FOR UPDATE`; **Idempotency-Key
  REQUIRED** (one mark-paid effect; safe replay returns the stored result).
- **Writes:** `run.status = paid`; `items.status = paid`; linked `salary_ledger` /
  `commission_ledger` rows advance **forward** `earned/pending → included_in_payout → paid` (both
  legal transitions, applied in the same locked transaction — this is the only place ledger status
  advances); store `external_payment_reference_encrypted` + `paid_at`. Linked
  `compensation_adjustments` (no status column) stay linked via `payout_item_id`.
- **No provider call. No Wallet confirmation.** Servana records that an external payment already
  happened; it moves no money and never depends on Gate W.
- **Side-effects:** statement availability trigger (H11); personnel notification is the Phase
  20H-owned minimum (via the existing notification hook only; no notification-center build).
- **Audit:** `payout_run.marked_paid` (**critical**). No success audit survives rollback.
- **Failure codes:** `409` idempotency, `403` stale_step_up, `422` missing_reference,
  `422 invalid_state_transition` if not approved.
- Original monetary amounts are **never** mutated; no double mark-paid (idempotency + status guard);
  no cross-tenant/branch mark-paid (tenancy scope + composite FKs).

---

## H9 — Adjustment-run behavior

- Already-paid reversals from 20G are **negative `compensation_adjustments`** (`paid_commission_reversal`
  / `paid_salary_reversal`, `amount_minor < 0`) — paid history is never rewritten (20G invariant).
- A payout run includes approved eligible adjustments (H4), including negative clawbacks, which
  **reduce** the affected staff/currency item.
- **Net-negative policy (D-H9-1):** if a staff/currency item nets negative (clawbacks exceed current
  earnings), the item is **carried honestly as a negative `gross_amount_minor`** — it is neither
  dropped nor silently absorbed into an unrelated item, and it never destructively rewrites a ledger
  fact. Because **Servana moves no money**, mark-paid on a run containing a negative item records the
  external settlement outcome only; the negative simply reflects that the personnel net-owes for the
  period (carried forward by the standing adjustment linkage). No non-negativity CHECK is placed on
  payout amounts. This is resolvable from the hierarchy (append-only + no-money-movement + no-silent-
  rewrite invariants) and needs **no product-owner decision**.
- No payout item may silently absorb an unrelated correction: each adjustment is linked to exactly
  one item via `payout_item_id` (unique, set-once), and appears in `source_ledger_refs`.

---

## H10 — Earnings overview and tabs (Personnel own-scope)

- `staff_profile_id` is **derived from the authenticated membership** — arbitrary staff ids are
  rejected (`403`/`404`, never leaked). No cross-branch or other-personnel data.
- Overview: per-currency totals of earned/pending/paid across the personnel's own
  salary + commission ledgers + adjustments + payout history.
- **Salary tab** visible only when the personnel's active compensation model ∈
  `{salary_only, salary_plus_commission}`.
- **Commission tab** visible only when the model ∈ `{commission_only, salary_plus_commission}`.
  (`CompensationModel` enum: `commission_only`, `salary_only`, `salary_plus_commission`.)
- Compensation terms (plan summary incl. `suspension_salary_policy`), payout history (own items),
  and downloadable period statements (H11).
- All money is server-authoritative; the SPA computes no authoritative totals.

---

## H11 — Earnings statements / PDF (via 10F)

- Uses the **existing Phase 10F file subsystem** — `uploaded_files.purpose` already includes
  **`earnings_statement`** (Plan §13.13 CHECK enum); **no schema change** and **no new PDF library**.
- Generation: server-side PDF for a `(staff_profile, period)` written to the private disk via the
  10F pipeline (quarantine → scan → available), purpose `earnings_statement`, owner = the personnel
  user, tenant/branch scoped.
- Download authorization: authenticated + tenant + branch + **personnel own-scope** +
  `personnel.my_statements.download` + `available` status + billing read-only policy (existing
  downloads allowed during read-only). Never exposes storage paths — download via an authorized
  endpoint issuing a short-lived signed URL / stream.
- Immutability: a statement for a locked period is immutable; regeneration after a correction issues
  a **new** statement file (never edits the old one).
- **No scheduled delivery** (21N). Immediate availability on mark-paid / on request is the Phase
  20H-owned minimum.

---

## H12 — Earnings queries

- **Table:** `earnings_queries` (single table; resolution captured inline via `assigned_to` /
  `assigned_role` / `resolution_note` / `status` / `resolved_adjustment_id` — the Plan defines **no**
  separate responses table).
- **Create:** personnel creates against **own** `subject_type ∈ {commission_ledger, salary_ledger,
  payout_item}` + `subject_id` that is validated to belong to the acting `staff_profile_id`
  (`personnel.my_earnings_query.create`).
- **query_type** (fixed enum, validated): `commission_disagreement`, `salary_disagreement`,
  `payout_missing`, `payout_amount`, `statement_request`, `other` — routed to `assigned_role`
  (`finance` for ledger/payout monetary types; `hr` for compensation-terms types). Routing sets
  `assigned_to`/`assigned_role`; the run of `open → assigned` is automatic on create or explicit on
  pickup.
- **Respond / resolve:** `earnings_query.respond` (**Finance**, MFA) transitions
  `assigned → resolved | rejected` with a `resolution_note`. **Resolution never mutates a ledger
  silently** — a monetary correction is a **`compensation_adjustments`** entry (reusing the existing
  `compensation.adjustment.create` flow), whose id is recorded in `resolved_adjustment_id`. Personnel
  see status + resolution note only.
- **Decision D-H12-1 (respond permission):** the live matrix grants `earnings_query.respond` to
  **Finance only** (`M/B`). The Plan narrative "Finance/HR assignment by type" describes **routing**
  (`assigned_role`), but the only respond **permission** in the authoritative matrix is Finance's.
  Phase 20H therefore implements **Finance-gated respond**; HR-routed queries are assigned to HR for
  triage but the authoritative resolution permission stays `earnings_query.respond` (Finance),
  matching the matrix. No new HR respond permission is invented. (Matrix = authoritative source;
  hierarchy L2/L10 over narrative.)
- All events audited (created / assigned / responded / resolved / rejected).

---

## H13 — Permissions and roles

All 16 Phase 20H keys already exist in `docs/auth/permission-matrix.yaml` as
`implementation_status: planned`, `owning_phase: Phase 20H`. Increment 5 flips them
`planned → active` and adds the declared positive/negative tests. **Counts: active 113 → 129;
planned 56 → 40; legacy-active unchanged; no legacy retirement.**

| Key | Role(s) | Scope | MFA | Step-up | Severity | Paired action |
|---|---|---|---|---|---|---|
| `payout_run.create` | HR | branch | – | – | warn | – |
| `payout_run.update_draft` | HR | branch | – | – | info | – |
| `payout_run.submit` | HR | branch | – | – | warn | `payout_run.verify` |
| `payout_run.cancel_draft` | HR | branch | – | – | info | – |
| `payout_run.verify` | Finance | merchant/branch | Y | Y | high | `payout_run.submit` |
| `payout_run.approve_standard` | Finance | merchant/branch | Y | Y | high | – |
| `payout_run.reject` | Finance | merchant/branch | Y | – | warn | – |
| `payout_run.mark_paid` | Finance | merchant/branch | Y | Y | **crit** | – |
| `earnings_query.respond` | Finance | merchant/branch | Y | – | info | – |
| `merchant.compensation_summary.view` | Merchant Admin | merchant | Y | – | info | – |
| `merchant.payout.approve_high_value` | Merchant Admin | merchant | Y | Y | **crit** | `payout_run.verify` |
| `personnel.my_compensation.view` | Personnel | own | – | – | info | – |
| `personnel.my_earnings.view` | Personnel | own | – | – | info | – |
| `personnel.my_statements.download` | Personnel | own | – | – | info | – |
| `personnel.my_payouts.view` | Personnel | own | – | – | info | – |
| `personnel.my_earnings_query.create` | Personnel | own | – | – | info | – |

Guardrails: HR never marks/approves payouts; Merchant Admin has **only** high-value approval (no
create/verify/standard-approve/mark-paid); Personnel is strict own-scope; no Phase
21N/21S/22/23/24/25 key is touched. YAML / PHP registry / DB seed / generated `permissions.ts` /
`phase8-matrix.txt` parity is maintained (Increment 5). New `StepUpAction` cases:
`PayoutRunVerify`, `PayoutRunApproveStandard`, `PayoutRunMarkPaid`, `PayoutRunApproveHighValue`.

---

## H14 — API ownership and route classification

Route families under `/api/v1` (tenant + branch middleware groups; `RouteClassification`):

| Family | Method / path | Class | Permission | MFA | Fresh step-up | Idempotency-Key |
|---|---|---|---|---|---|---|
| HR payout | `GET payout-runs`, `GET payout-runs/{ulid}` | read | `payout_run.create` (view via role) | – | – | – |
| HR payout | `POST payout-runs` | branch_mutation | `payout_run.create` | – | – | – |
| HR payout | `PATCH payout-runs/{ulid}` | branch_mutation | `payout_run.update_draft` | – | – | – |
| HR payout | `POST payout-runs/{ulid}/submit` | branch_mutation | `payout_run.submit` | – | – | – |
| HR payout | `POST payout-runs/{ulid}/cancel` | branch_mutation | `payout_run.cancel_draft` | – | – | – |
| Finance payout | `POST payout-runs/{ulid}/verify` | financial_mutation | `payout_run.verify` | Y | Y | – |
| Finance payout | `POST payout-runs/{ulid}/approve` | financial_mutation | `payout_run.approve_standard` | Y | Y | – |
| Finance payout | `POST payout-runs/{ulid}/reject` | financial_mutation | `payout_run.reject` | Y | – | – |
| Finance payout | `POST payout-runs/{ulid}/mark-paid` | financial_mutation | `payout_run.mark_paid` | Y | Y | **required** |
| Merchant Admin | `GET compensation-summary` | read | `merchant.compensation_summary.view` | Y | – | – |
| Merchant Admin | `POST payout-runs/{ulid}/approve-high-value` | financial_mutation | `merchant.payout.approve_high_value` | Y | Y | – |
| Personnel earnings | `GET my/earnings`, `.../compensation`, `.../payouts` | read | `personnel.my_earnings.view` etc. | – | – | – |
| Personnel statements | `GET my/statements/{ulid}/download` | read (file) | `personnel.my_statements.download` | – | – | – |
| Personnel query | `POST my/earnings-queries` | branch_mutation | `personnel.my_earnings_query.create` | – | – | – |
| Finance/HR query queue | `GET earnings-queries`, `POST earnings-queries/{ulid}/respond` | read / financial_mutation | (view via role) / `earnings_query.respond` | Y | – | – |

No generic status routes, no raw ledger-edit routes, no Wallet/provider/callback routes are exposed.
`{ulid}` binds inside tenant scope → foreign ids 404.

---

## H15 — Audit

Typed `AuditEvent` cases (append-only, hash-chained; severity per matrix):

| Event | Severity |
|---|---|
| `payout_run.created` | warn |
| `payout_run.updated` (draft edit) | info |
| `payout_run.submitted` | warn |
| `payout_run.verified` | high |
| `payout_run.approved_standard` | high |
| `payout_run.rejected` | warn |
| `payout_run.high_value_approved` (Merchant Admin) | **critical** |
| `payout_run.marked_paid` | **critical** |
| `earnings_statement.generated` / `.available` | info |
| `earnings_query.created` | info |
| `earnings_query.assigned` | info |
| `earnings_query.responded` / `.resolved` / `.rejected` | info |

No success audit survives rollback (emitted inside the same transaction as the mutation). No secret,
SQLSTATE, internal numeric id, raw stack trace, or private contact data enters an audit payload
(masked/ULID references only). `AuditMutationCoverageTest` / `AuditSeverityCoverageTest` extended.

---

## H16 — Frontend ownership

New screens (source inventory + navigation + §27.1 specs regenerated by their own generators — no
generated file hand-edited):

| Screen | Route | Layout | Role | Permission |
|---|---|---|---|---|
| HR Payout Runs | `hr.payout-runs` → `/hr/payout-runs` | BranchLayout | HR | `payout_run.create` |
| Finance Payout Runs | `finance.payout-runs` → `/finance/payout-runs` | FinanceLayout | Finance | `payout_run.verify` |
| Merchant Admin Compensation Summary | `merchant.compensation-summary` → `/merchant/compensation-summary` | (MA layout) | Merchant Admin | `merchant.compensation_summary.view` |
| Personnel Earnings | `personnel.earnings` → `/personnel/earnings` | (Personnel layout) | Personnel | `personnel.my_earnings.view` |
| Earnings Query (personnel create + Finance/HR queue) | within the above + `finance.earnings-queries` | – | Personnel / Finance / HR | own / `earnings_query.respond` |

Each consumes generated contracts only; Pinia stores typed from generated `api.ts`;
Idempotency-Key mint/reuse/retire on mark-paid; no client-authoritative money. Proof: Vitest;
Playwright at 360/768/1280 + 200% zoom + keyboard + focus restoration + light/dark; axe
serious/critical = 0 on affected screens; role denial + tenant/branch/personnel isolation.

---

## H17 — Reports and notifications boundary

- Statement availability + the payout mark-paid personnel notification (via the **existing**
  notification hook) are the Phase 20H-owned minimum.
- **No** general scheduled report delivery, report subscriptions, or notification-center build
  (**Phase 21N**). The `docs/reporting/report-catalogue.md` payout/earnings report **definitions**
  are recorded (delivery owner 21N), matching the 20G precedent.

---

## H18 — Security and financial invariants (tested)

Row-level locks on run mutation (`FOR UPDATE`); Idempotency-Key on mark-paid + financial mutations;
no double payout item for one source fact (`payout_item_id IS NULL` guard + unique + FK); no double
mark-paid (status guard + idempotency); no payout of reversed/cancelled/paid/already-linked entries;
no cross-currency combination (run currency filter + item unique per currency); no
cross-tenant/branch/personnel leakage (tenancy scopes + composite FKs + own-scope derivation); no
frontend-only eligibility; no silent ledger mutation (all via typed actions + append-only guards);
**no Wallet/provider code; no direct money movement.**

---

## Delivery plans

**Data-dictionary plan:** add `personnel_payout_runs`, `personnel_payout_items`, `earnings_queries`
to `docs/architecture/data-dictionary/` (compensation dictionary), + note the three expand FKs on
the 20G ledgers.

**Migration plan (Increment 2, forward-only):** (1) `create personnel_payout_runs`; (2) `create
personnel_payout_items`; (3) `create earnings_queries`; (4–6) expand `add payout_item_id FK` to
`commission_ledger` / `salary_ledger` / `compensation_adjustments`. Freeze/immutability triggers on
runs + items; idempotency/uniqueness indexes; composite-consistency FKs; `TenantOwnership`
registration; `MigrationManifest` update.

**State-machine plan:** `PayoutRunStateMachine` (H5), `PayoutItemStateMachine` (mirror),
`EarningsQueryStateMachine` (H12) with specs under `docs/architecture/state-machines/`.

**Domain-action plan (Increment 3–4):** `SelectEligiblePayoutLiabilities`, `CreatePayoutRunDraft`,
`UpdatePayoutRunDraft`, `SubmitPayoutRun` (freeze+claim), `VerifyPayoutRun` (+high-value detection),
`ApprovePayoutRunStandard`, `ApprovePayoutRunHighValue`, `RejectPayoutRun`, `CancelPayoutRunDraft`,
`MarkPayoutRunPaid`; earnings read models (`PersonnelEarningsReadModel`), `GenerateEarningsStatement`
(10F), `CreateEarningsQuery`, `RespondToEarningsQuery` (→ optional adjustment).

**API plan (Increment 5):** thin controllers, Form Requests, masked Resources, policies, routes +
`RouteClassification`, MFA/fresh-step-up, Idempotency-Key, OpenAPI + `api.ts` + `permissions.ts`
regen (deterministic), `api:contract:check`.

**Frontend plan (Increment 6):** the five screens in H16 + stores + inventory/nav/specs + Vitest +
Playwright.

**Test plan:** `Phase20HSchemaTest`, `Phase20HEnumParityTest`, factory validity, tenancy coverage,
manifest; `PayoutRunStateMachineTest`, `PayoutItemFreezeTest`, `EarningsQueryStateMachineTest`;
`PayoutEligibilitySelectorTest`, `PayoutSnapshotTest`, `PayoutSubmitFreezeTest`,
`PayoutHighValueThresholdTest`, `PayoutApprovalTest`, `MarkPaidTest` (idempotency, stale step-up,
missing reference, ledger propagation, high-value-requires-MA), `PayoutTenantIsolationTest`,
`PayoutRunConcurrencyTest`; `EarningsOverviewTest`, `EarningsTabVisibilityTest`,
`EarningsStatementDownloadTest` (own-scope, other-personnel denial, signed-URL), `EarningsQueryTest`
(routing, resolve-via-adjustment-only); permission parity/planned-key isolation; route-security;
financial idempotency coverage; audit mutation/severity coverage; OpenAPI determinism; affected
20F/20G/18B regressions; full serial + parallel backend; disposable PG16 proof.

**Explicit exclusions:** Wallet/provider runtime, STK/PayBill/Till/C2B/Daraja/callbacks,
settlement, direct money movement, scheduled report delivery, notification center, bulk SMS, search,
release-wide audits, performance, deployment.

**Blocking conflicts:** none. Every H-gate is resolved from the Plan + live matrix + live repository.
No product-owner decision is required to proceed.

---

## Increment 2 — schema, enums, models, factories, tenancy, state machines (COMPLETE + green)

**Migrations (forward-only, ADR-004):**
- `2026_07_20_000001_create_personnel_payout_runs_table.php`
- `2026_07_20_000002_create_personnel_payout_items_table.php` (freeze guard: DELETE only while draft; snapshot columns immutable)
- `2026_07_20_000003_create_earnings_queries_table.php`
- `2026_07_20_000004/000005/000006_add_payout_item_fk_to_{commission_ledger,salary_ledger,compensation_adjustments}.php` (EXPAND FKs)

**Enums:** `PayoutRunStatus`, `PayoutItemStatus`, `EarningsQuerySubjectType`, `EarningsQueryType`,
`EarningsQueryAssignedRole`, `EarningsQueryStatus` (all with `values()` + parity to DB CHECKs;
lifecycle enums carry `allowedTransitions()`).

**Models:** `PersonnelPayoutRun`, `PersonnelPayoutItem`, `EarningsQuery` (BelongsToMerchant +
BelongsToBranch; ULID route key; encrypted external reference cast; jsonb `source_ledger_refs`).
**Factories:** `PersonnelPayoutRunFactory`, `PersonnelPayoutItemFactory`, `EarningsQueryFactory`
(branch-anchored so composite FKs agree).

**State machines:** `PayoutRunStateMachine`, `EarningsQueryStateMachine` (→ `CompensationStateException`
`422 invalid_state_transition`). Item mirrors run (no independent machine).

**Tenancy:** all three tables added to `TenantOwnership::BRANCH_OWNED`, `::MODELS` (branch), and
`::COMPOSITE_CONSISTENCY`. **Manifest:** 6 entries added. **Docs:** data-dictionary section +
`personnel-payout-run.md` + `earnings-query.md` state-machine specs.

**Cross-phase reconciliations (narrow, test-only; the shipped 20G FK on the ledgers is now real):**
- `Phase20GSchemaTest` — the `payout_item_id` transition test now links a real same-merchant payout item (was bare id `99`).
- `Phase20GTenantIsolationTest` — "no payout_item_id FK yet" flipped to "FK now exists on all three ledgers"; the "20G creates no payout/earnings substrate" guard narrowed to Wallet-only (Gate W CLOSED) since 20H legitimately owns the payout/earnings tables.
- `Phase20FSchemaTest`, `CompensationPlanActionTest`, `CompensationPlanApiTest` — "payout/earnings tables absent" guards flipped to "20F/20G lifecycle writes zero rows" (the tables now exist; the misnamed `earnings_statements`/`personnel_earnings_queries` were never real tables — statements are 10F `uploaded_files`).

**Gates (green):**
- `Phase20HSchemaTest` (13), `Phase20HEnumParityTest` (7), `Phase20HStateMachineTest` (5) — pass.
- Reconciled + coverage batch: `Phase20GSchemaTest`, `Phase20GTenantIsolationTest`, `Phase20FSchemaTest`, `ModelTenancyTraitCoverageTest`, `TenantColumnCoverageTest`, `MigrationManifestTest` — **127 passed** together; `Phase20FSchemaTest` + `CompensationPlanActionTest` + `CompensationPlanApiTest` + isolation + state-machine — **178 passed**.
- **Pint clean**; **Larastan level 8 — 0 errors** (1099 files).

**No cross-phase enum/state-machine change** — the forward-only 20G ledger enums are honoured by the
claim-via-`payout_item_id` design (D-H3-2). No product code outside Phase 20H was changed (only the
five test guards above + the shared `TenantOwnership` registry + the manifest).

---

## Increment 3 — payout domain actions and calculations (COMPLETE + green)

**Audit events** (`AuditEvent`, all `AuditDomain::Compensation`): `payout_run.created` (warn),
`.updated_draft` (info), `.submitted` (warn), `.verified` (high), `.approved_standard` (high),
`.high_value_approved` (**critical**), `.rejected` (warn), `.cancelled` (info), `.marked_paid`
(**critical**); + `earnings_statement.generated` + `earnings_query.created/assigned/resolved/rejected`
(info, for Increment 4). Severity/domain coverage green (`AuditSeverityCoverageTest` 5 passed).

**Services:** `SelectEligiblePayoutLiabilities` (H4 predicate — `entry_type`-aware, currency/branch/
period-bounded, `FOR UPDATE` on demand, reversal-excluding); `PayoutRunItemSnapshotter` (one item per
staff, bucketed sums + `source_ledger_refs`, regenerable while draft, recomputes run gross).

**Actions (10):** `CreatePayoutRunDraft` (snapshots + threshold from `merchant_subscriptions`),
`UpdatePayoutRunDraft` (draft-only regenerate), `SubmitPayoutRun` (lock + re-snapshot + claim
`payout_item_id` + freeze), `VerifyPayoutRun` (submitted→finance_verified + high-value auto-route to
`pending_merchant_admin_approval`), `ApprovePayoutRunStandard` (finance_verified-only → approved),
`ApprovePayoutRunHighValue` (pending_merchant_admin_approval-only → approved), `RejectPayoutRun`
(release claimed ledgers + reason), `CancelPayoutRunDraft`, `MarkPayoutRunPaid` (row lock, forward
ledger settle `earned/pending→included_in_payout→paid`, encrypted external ref + paid date, **no
provider/Wallet call, no money movement**). Each action: single transaction, `FOR UPDATE` row lock,
typed state-machine guard, audit inside the transaction.

**Design confirmations (verified in code):**
- Each approval action enforces its **exact source state** (the shared machine allows both
  finance_verified→approved and pending_merchant_admin_approval→approved, so standard-approve is
  guarded to finance_verified only, high-value to pending_merchant_admin_approval only).
- **Reversal netting** proven against `ReverseCommissionEntry`/`ReverseSalaryAccrual`: an unpaid
  reversal writes a negative `entry_type='reversal'` row **and** flips the original to `reversed`;
  excluding both nets to zero (test: a reversed 30000 + its −30000 both drop, only the kept 50000 pays).
- The external payment reference is **never** placed in an audit payload.

**Gates:** `PayoutRunLifecycleTest` — **11 passed** (snapshot sums; currency + branch isolation;
reversal netting; submit claim + freeze; frozen-run update rejection; ordinary lifecycle → paid with
ledger propagation; high-value routing + standard-approve rejection; reject-release; cancel;
mark-unapproved rejected; no-money-movement). **Pint clean; Larastan level 8 — 0 errors (1110 files).**
Idempotency of mark-paid is enforced at the API boundary (Increment 5 — `Idempotency-Key`); the action
is row-locked and state-guarded.

---

## Increment 4 — earnings backend domain (source inventory + design)

**Source inventory (live repository conventions):**
- **File subsystem (10F):** `App\Domain\Files\Services\GeneratedFileWriter::write(purpose, bytes,
  downloadFilename, mime, ext, merchantId, branchId, uploadedBy)` is the single sanctioned way to
  create a server-generated file (skips quarantine, lands `available`). PDFs are rendered by a
  `{Doc}DocumentRenderer::render(): string` using the dependency-free `App\Support\MinimalPdf::fromLines(lines, title)`
  (no external PDF library — mirrors `ReceiptDocumentRenderer`/`SubscriptionInvoiceDocumentRenderer`).
- **`FilePurpose::EarningsStatement`** is registered (`FilePurposeRegistry`) with `requiresOwner=true`,
  `permission=null` → **download authority is own-scope: `UploadedFile.owner_user_id == caller`**
  (enforced by `FileAccessService::authorizeView` — foreign owner 404; storage paths never exposed;
  short-lived signed URLs). `billingReadOnlyGeneration=true` (read-only blocks new generation, not an
  existing download); retention = the export retention window.
- **`GeneratedFileWriter` hardcodes `owner_user_id => null`** — earnings statements need it set, so
  Increment 4 adds an **optional trailing `?int $ownerUserId = null`** param (backward-compatible; all
  existing callers keep passing nothing = null). Minimal seam extension, not a new architecture.
- **Own-scope resolution (verified in `PersonnelQueueController`):** `StaffProfile::where('merchant_user_id',
  $context->merchantUser()->id)`; the statement owner user id = `staffProfile->merchantUser->user_id`.
  Read-model/action signatures take an **explicit `StaffProfile`** (the caller/Inc5 controller resolves
  own-scope) — a browser never chooses the profile.
- **Read-model pattern:** `CompensationLiabilityReadModel` (per-currency grouped totals, never combines
  currencies, masked ULIDs, `BelongsToMerchant`-bounded). Mirrored for personnel own-scope.
- **Adjustment path:** `RecordCompensationAdjustment::manual(StaffProfile, branchId, amountMinor,
  currency, reason, ?createdBy, approvedBy)` — the only path a query correction may use (Phase 20G
  invariants; Finance-gated at the API in Inc5).
- **Earnings-query model/enums (Inc2):** `EarningsQuery`; `EarningsQuerySubjectType`
  (`commission_ledger`/`salary_ledger`/`payout_item` — the §13.12 CHECK); `EarningsQueryType` (6 cases
  → `routedRole()` finance/hr); `EarningsQueryStatus` (`open→assigned→resolved|rejected`);
  `EarningsQueryStateMachine`. Audit events `earnings_query.created/assigned/resolved/rejected` +
  `earnings_statement.generated` already added in Increment 3.
- **Test queue driver:** `sync` (`phpunit.xml`).

**Increment 4 decisions of record:**
- **D-H11 (statement trigger — on-demand, idempotent):** the Plan (§63 "downloadable period statement";
  §25.5 lists statement PDF as a mark-paid side effect) permits on-demand generation. Increment 4
  generates the statement **on demand for a PAID payout item**, idempotently, and does **not** couple it
  into `MarkPayoutRunPaid` (keeps the Increment 3 payout transaction lean and the payout tests
  untouched). **Availability** = `run.status = paid` (generatable) + `personnel_payout_items.earnings_statement_file_id`
  (already generated). Increment 5/21N may later wrap the action in a queued job.
- **D-H11-link (smallest idempotent linkage):** a nullable `earnings_statement_file_id` FK on
  `personnel_payout_items` (forward-only Increment-4 expand). It sits **outside** the item freeze guard's
  ROW() comparison, so setting it on a paid item is permitted while every snapshot column stays frozen.
  Idempotency + immutability: once set, `GenerateEarningsStatement` returns the existing file and never
  regenerates.
- **D-H12-subject:** earnings-query subjects are exactly the §13.12 CHECK values (`commission_ledger`,
  `salary_ledger`, `payout_item`); each `subject_id` is validated to belong to the acting staff profile
  (own commission/salary row, or an item on a run for that staff). Statement/run/general-question
  subjects are out of the authoritative CHECK and are not added.
- **D-H12-respond:** `RespondToEarningsQuery` resolves/rejects; a monetary correction is created **only**
  through `RecordCompensationAdjustment::manual` and linked via `resolved_adjustment_id`. Finance-gating
  is applied at the API boundary in Increment 5 (domain action is permission-agnostic but records the
  responder).

**No blocker** — statement immutability, file-subsystem ownership, query assignment, monetary-correction
semantics, net-negative treatment, and compensation-model visibility are all resolved from the Plan +
live repository. No permission/route work is performed here (Increment 5).

### Increment 4 — implementation (COMPLETE + green)

**Migration:** `2026_07_20_000007_add_earnings_statement_file_id_to_personnel_payout_items.php`
(nullable FK → `uploaded_files`, outside the freeze-guard ROW() list). Model field + relationship +
manifest + data-dictionary updated.

**Read model — `PersonnelEarningsReadModel`** (own-scope; explicit `StaffProfile`; source-ledger money
view; never combines currencies):
- `overview()` — per-currency `salary/commission/adjustment` × `unpaid/paid` + `net`. **Double-count
  avoided**: money derives from source ledgers only (`<> paid` = outstanding, `= paid` = paid; reversed
  original + reversal net to zero); payout items are shown as history, never re-summed. Adjustments
  split paid/unpaid by whether their linked payout item is paid.
- `tabVisibility()` — `salary_only`→salary; `commission_only`→commission; `salary_plus_commission`→both;
  no current plan → tabs from historical facts; **conflicting active plans → fail closed** (both hidden).
- `payoutHistory()` (own items + run) and `compensationTerms()` (explanatory current plan; safe
  no-plan state; fail closed on conflict).

**Statements — `EarningsStatementDocumentRenderer` + `GenerateEarningsStatement`** (on-demand,
idempotent, immutable): renders via dependency-free `MinimalPdf` (safe facts only — public ULIDs,
integer minor, no internal ids / raw external ref / storage path); writes through the extended
`GeneratedFileWriter` (purpose `earnings_statement`, `owner_user_id` = personnel user → own-scope
download via `FileAccessService`); links via `personnel_payout_items.earnings_statement_file_id`
(set-once). Only a **paid** item may be stated; a second call returns the same file (same sha256); a
later adjustment never rewrites it.

**Queries — `CreateEarningsQuery` + `RespondToEarningsQuery`**: own-scope create with subject
validation (foreign/missing subject → 404 `CompensationScopeException::earningsQuerySubject`,
no leak); `query_type → routedRole` (finance/hr) sets `assigned_role`; server owns status/assignment/
tenant fields. Respond resolves/rejects through `EarningsQueryStateMachine` (a terminal query fails
closed → **no duplicate correction on replay**); a monetary correction is created **only** through
`RecordCompensationAdjustment::manual` and linked via `resolved_adjustment_id` — the source ledger is
never edited.

**Period locks / idempotency (§10–§11):** earnings reads + statements + query creation work over
locked periods (facts are finalized); a monetary correction always flows through the additive
`compensation_adjustments` path (never a ledger edit), so period-lock policy is respected. Statement
generation + query response are row-locked; replay is safe (statement returns existing; a resolved
query cannot be re-resolved).

**Seam extension:** `GeneratedFileWriter::write()` gained an optional trailing `?int $ownerUserId = null`
(backward-compatible — all existing callers unchanged; receipt/file tests green). New exception
factories `CompensationScopeException::earningsQuerySubject/earningsStatement`,
`CompensationValidationException::earningsQueryDecision`.

**Gates (green):**
- `PersonnelEarningsReadModelTest` **10**, `EarningsStatementTest` **4**, `EarningsQueryTest` **6** —
  all pass (own-scope isolation, currency grouping, reversal netting, tab visibility incl. conflict
  fail-closed and historical, statement idempotency/immutability/paid-only/owner, query subject
  validation, correction-via-adjustment-only, replay safety).
- Affected regressions serial: `PayoutRunLifecycleTest` 11, `Phase20HSchema/Enum/StateMachine`,
  `CommissionRefundReversalTest`, `Phase20GCompensationApiTest`, `MigrationManifestTest`,
  `ModelTenancyTraitCoverageTest`, `TenantColumnCoverageTest`, `AuditSeverityCoverageTest` — **78
  passed**; `NoDirectProviderIntegrationTest` + `AuditMutationCoverageTest` + receipts — **16 passed**;
  `Phase20FSchemaTest` + `CompensationPlanActionTest` + `CompensationPlanApiTest` +
  `FileDownloadAuthorizationTest` + `ReceiptApiTest` + `ReceiptDownloadAuthorizationTest` — **184
  passed** (the extended `GeneratedFileWriter` is backward-compatible).
- **Pint clean; Larastan level 8 — 0 errors.**

**Not done here (later increments):** no permission activation, no HTTP routes/controllers/requests/
resources, no OpenAPI/generated-contract changes, no frontend, no queued statement job. Statement
availability is on-demand (D-H11) — Increment 5 exposes the routes + policies; a queued job is a 21N
option.

---

## Increment 5 — API surface + generated contracts (source inventory, decisions, implementation)

### Inc5-A — Source inventory (recorded before any code change, per §8)

**Permission keys (16, live matrix `docs/auth/permission-matrix.yaml`; verified 2026-07-20).** All
`implementation_status: planned`, `owning_phase: Phase 20H`, `audit_event: pending`. Activation flips
each to `active` / `owning_phase: null` / a route-derived `audit_event`, and adds the key + role grant
to `PermissionRegistry` (the runtime source the seeder projects to the DB and the generator projects to
`permissions.ts`). Scope/MFA/step-up/severity are the Plan-encoded fields the parity harness checks —
they are NOT changed:

| Key | Role | Scope | MFA | Step-up | Severity | Route audit_event (derived) |
|---|---|---|---|---|---|---|
| `payout_run.create` | HR | branch | – | – | warn | `payout_run.created` |
| `payout_run.update_draft` | HR | branch | – | – | info | `payout_run.updated_draft` |
| `payout_run.submit` | HR | branch | – | – | warn | `payout_run.submitted` |
| `payout_run.cancel_draft` | HR | branch | – | – | info | `payout_run.cancelled` |
| `payout_run.verify` | Finance | merchant | Y | Y | high | `payout_run.verified` |
| `payout_run.approve_standard` | Finance | merchant | Y | Y | high | `payout_run.approved_standard` |
| `payout_run.reject` | Finance | merchant | Y | – | warn | `payout_run.rejected` |
| `payout_run.mark_paid` | Finance | merchant | Y | Y | crit | `payout_run.marked_paid` |
| `earnings_query.respond` | Finance | merchant | Y | – | info | `earnings_query.rejected; earnings_query.resolved` |
| `merchant.compensation_summary.view` | Merchant Admin | merchant | Y | – | info | `none` (read) |
| `merchant.payout.approve_high_value` | Merchant Admin | merchant | Y | Y | crit | `payout_run.high_value_approved` |
| `personnel.my_compensation.view` | Personnel | own | – | – | info | `none` (read) |
| `personnel.my_earnings.view` | Personnel | own | – | – | info | `none` (read) |
| `personnel.my_statements.download` | Personnel | own | – | – | info | `earnings_statement.generated` |
| `personnel.my_payouts.view` | Personnel | own | – | – | info | `none` (read) |
| `personnel.my_earnings_query.create` | Personnel | own | – | – | info | `earnings_query.created` |

Counts (reconciled to the live `PermissionMatrix::activeKeys()`): **active 112 → 128; planned 56 → 40;
legacy-active unchanged; legacy retirement: none.** (The Inc1 H13 note estimated 113→129; the authoritative
live count is 112→128 — YAML active == PHP registry == DB projection == 128, parity-proven.) The
`PermissionPlannedKeyIsolationTest` `plannedKeys()` count assertion moves 56 → 40.

**StepUpAction inventory (`app/Domain/Auth/Mfa/StepUpAction.php`).** The enum already ships
`PayoutApproval` (`payout_approval`) and `PayoutMarkPaid` (`payout_mark_paid`) as **Phase-20H
harness-only** cases (listed in `businessActions()`). Increment 5 gives them live routes, so they leave
`businessActions()` (the established pattern — every implemented step-up action is excluded once its real
route exists, proven on the route instead of the harness). The §19.3 matrix also requires fresh step-up
on **verify** and **high-value approval**, which have no pre-existing case → **two new cases added:**
`PayoutVerify` (`payout_verify`) and `PayoutHighValueApprove` (`payout_high_value_approve`). Mapping:
verify→`PayoutVerify`, standard approve→`PayoutApproval`, high-value approve→`PayoutHighValueApprove`,
mark-paid→`PayoutMarkPaid`. Reuse of an unrelated billing/compensation-adjustment action is avoided
(§10.2 guardrail).

**Middleware inventory.**
- **MFA** — the whole authenticated tenant surface is wrapped in `EnsurePrivilegedMfa` (routes/api.php
  line 195), which enforces MFA only for privileged roles (Super Admin / Merchant Admin / Finance) and
  passes HR/Personnel through. So `mfa_required: true` (Finance/MA keys) is satisfied group-level; HR/
  Personnel keys are `mfa_required: false` and correctly pass through. **No per-route MFA middleware is
  added** (matches the 20F/20G pattern).
- **Fresh step-up** — `RequireFreshMfa::class.':'.StepUpAction::<case>->value`, attached last.
- **Idempotency** — `EnsureIdempotentRequest`; **required by the `RouteClass::FinancialMutation`
  contract** (`RouteClass::requiredMiddleware()` + `FinancialRouteIdempotencyCoverageTest`), so EVERY
  financial-mutation route carries it. This **supersedes the proof H14 "Idempotency-Key –" dashes on
  verify/approve/reject** (a proof-note simplification): the live repository contract (source-of-truth
  L1) mandates idempotency on the class, it is beneficial (safe replay of a financial-workflow
  transition), and the prompt §10.3/§12 require it. Mark-paid's idempotency is thereby also guaranteed.
- `EnsureBranchScope` no-ops when a route has no `{branch}` binding (it only acts on a `{branch}` param),
  so BranchMutation HR/query-create routes carry it to satisfy the class contract while the controller/
  action enforces the actual branch/own scope (identical to `compensation-plans.*`).
- `EnsureBillingMutable` reads only `merchants.billing_status`; attached to statement **generation**
  (the `FilePurpose::EarningsStatement` `billingReadOnlyGeneration=true` flag — read-only blocks new
  generation, never an existing download).

**Route families + classification (all under the existing `/api/v1` authenticated tenant group).**

| Method / path | Name | Class | Permission | Step-up | Idem |
|---|---|---|---|---|---|
| GET `hr/payout-runs` | `hr.payout-runs.index` | read | `payout_run.create` | – | – |
| GET `hr/payout-runs/{personnelPayoutRun}` | `hr.payout-runs.show` | read | `payout_run.create` | – | – |
| POST `hr/payout-runs` | `hr.payout-runs.store` | branch_mutation | `payout_run.create` | – | – |
| PATCH `hr/payout-runs/{personnelPayoutRun}` | `hr.payout-runs.update` | branch_mutation | `payout_run.update_draft` | – | – |
| POST `hr/payout-runs/{personnelPayoutRun}/submit` | `hr.payout-runs.submit` | branch_mutation | `payout_run.submit` | – | – |
| POST `hr/payout-runs/{personnelPayoutRun}/cancel` | `hr.payout-runs.cancel` | branch_mutation | `payout_run.cancel_draft` | – | – |
| GET `finance/payout-runs` | `finance.payout-runs.index` | read | `payout_run.verify` | – | – |
| GET `finance/payout-runs/{personnelPayoutRun}` | `finance.payout-runs.show` | read | `payout_run.verify` | – | – |
| POST `finance/payout-runs/{personnelPayoutRun}/verify` | `finance.payout-runs.verify` | financial_mutation | `payout_run.verify` | PayoutVerify | ✓ |
| POST `finance/payout-runs/{personnelPayoutRun}/approve` | `finance.payout-runs.approve` | financial_mutation | `payout_run.approve_standard` | PayoutApproval | ✓ |
| POST `finance/payout-runs/{personnelPayoutRun}/reject` | `finance.payout-runs.reject` | financial_mutation | `payout_run.reject` | – | ✓ |
| POST `finance/payout-runs/{personnelPayoutRun}/mark-paid` | `finance.payout-runs.mark-paid` | financial_mutation | `payout_run.mark_paid` | PayoutMarkPaid | ✓ |
| GET `merchant/compensation-summary` | `merchant.compensation-summary.show` | read | `merchant.compensation_summary.view` | – | – |
| GET `merchant/payout-runs` | `merchant.payout-runs.index` | read | `merchant.payout.approve_high_value` | – | – |
| GET `merchant/payout-runs/{personnelPayoutRun}` | `merchant.payout-runs.show` | read | `merchant.payout.approve_high_value` | – | – |
| POST `merchant/payout-runs/{personnelPayoutRun}/approve-high-value` | `merchant.payout-runs.approve-high-value` | financial_mutation | `merchant.payout.approve_high_value` | PayoutHighValueApprove | ✓ |
| GET `personnel/me/earnings` | `personnel.earnings.overview` | read | `personnel.my_earnings.view` | – | – |
| GET `personnel/me/compensation` | `personnel.compensation.show` | read | `personnel.my_compensation.view` | – | – |
| GET `personnel/me/payouts` | `personnel.payouts.index` | read | `personnel.my_payouts.view` | – | – |
| POST `personnel/me/payout-items/{personnelPayoutItem}/statement` | `personnel.statements.generate` | tenant_mutation | `personnel.my_statements.download` | – | – |
| GET `personnel/me/earnings-queries` | `personnel.earnings-queries.index` | read | `personnel.my_earnings_query.create` | – | – |
| GET `personnel/me/earnings-queries/{earningsQuery}` | `personnel.earnings-queries.show` | read | `personnel.my_earnings_query.create` | – | – |
| POST `personnel/me/earnings-queries` | `personnel.earnings-queries.store` | branch_mutation | `personnel.my_earnings_query.create` | – | – |
| GET `finance/earnings-queries` | `finance.earnings-queries.index` | read | `earnings_query.respond` | – | – |
| GET `finance/earnings-queries/{earningsQuery}` | `finance.earnings-queries.show` | read | `earnings_query.respond` | – | – |
| POST `finance/earnings-queries/{earningsQuery}/respond` | `finance.earnings-queries.respond` | financial_mutation | `earnings_query.respond` | – | ✓ |

Statement **download** reuses the existing `files.show` / `files.download-link` / `files.download`
routes (own-scope via `UploadedFile.owner_user_id == caller`, `FilePurpose::EarningsStatement`
`requiresOwner=true`, `permission=null`) — **no parallel download route** (§18). `{personnelPayoutRun}`
/ `{personnelPayoutItem}` / `{earningsQuery}` bind by ULID inside tenant scope → foreign ids 404.

**Audit coverage.** `AuditMutationCoverage::AUDITED` gains the nine payout mutation routes, the two
earnings-query mutation routes, and the statement-generation route → the exact events above (the query
`respond` route lists only its own `resolved`/`rejected` lifecycle events, not the nested
`compensation.adjustment.created` side-effect — the `platform-fee-disputes.resolve` precedent). All
events + severities already exist on the typed `AuditEvent` enum (Increment 3). Reads emit nothing.

**OpenAPI / generated contracts.** dedoc/scramble infers the contract from the live route collection +
FormRequest rules + Resource `toArray()` + DB schema — no manual annotation. `permissions.ts` is
generated from the YAML active set (`servana:permission-types`); `phase8-matrix.txt` is regenerated by
the `PermissionMatrixTest` "writes the matrix proof artifact" test.

### Inc5-B — Decisions of record

- **D-H13-idem:** every `financial_mutation` route carries `Idempotency-Key` (live `RouteClass`
  contract; supersedes the proof-H14 idempotency dashes on verify/approve/reject).
- **D-H14-split:** HR and Finance read the same runs through **separate** `hr/…` and `finance/…` route
  trees, each gated by a key that role actually holds (HR reads via `payout_run.create`; Finance via
  `payout_run.verify`; MA via `merchant.payout.approve_high_value`) — there is no shared `payout_run
  .view` key, so "view via role" is realised by role-owned read gates, not an invented key.
- **D-H14-stepup:** two new `StepUpAction` cases (`PayoutVerify`, `PayoutHighValueApprove`); the four
  payout step-up actions leave `businessActions()` now that they own live routes.
- **D-H14-query-respond-class:** `finance.earnings-queries.respond` is `financial_mutation` (idempotent;
  it may create a `compensation_adjustment`), MFA group-level, no fresh step-up (matrix `SU –`).
- **D-H11-statement-route:** statement generation is `POST …/statement` (`tenant_mutation` +
  `EnsureBillingMutable`), idempotent by nature (returns the existing file); download reuses the 10F file
  endpoints (own-scope). No new download route, no `Idempotency-Key` (the class does not require it and
  generation is inherently idempotent).

### Inc5-C — implementation (COMPLETE + green)

**Permissions:** 16 canonical keys flipped `planned → active` in `docs/auth/permission-matrix.yaml`
(`owning_phase: null`; `audit_event` set to the route-derived string / `none`) and added to
`PermissionRegistry::PERMISSIONS` + `DEFAULT_GRANTS` (HR ×4, Finance ×5, Merchant Admin ×2, Personnel
×5). DB projection + generated `permissions.ts` + `phase8-matrix.txt` regenerated. **Counts: active
112 → 128; planned 56 → 40; legacy-active unchanged; legacy retirement: none.** Four-way parity
(YAML ↔ PHP ↔ DB ↔ TS) green.

**Step-up:** two new `StepUpAction` cases `PayoutVerify` / `PayoutHighValueApprove`; `PayoutApproval`
(standard) + `PayoutMarkPaid` reused; all four removed from the harness-only `businessActions()` (they
own live routes now — the established implemented-action pattern).

**Policies:** `PersonnelPayoutRunPolicy` (HR viewAsHr/create/update/submit/cancel; Finance
viewAsFinance/verify/approveStandard/reject/markPaid; Merchant-Admin viewAsMerchantAdmin/approveHighValue)
+ `EarningsQueryPolicy` (personnel create/viewOwn; Finance viewAsResponder/respond) — registered in
`AppServiceProvider::POLICIES`. The compensation-summary read authorizes inline via
`merchant.compensation_summary.view`.

**Form Requests (16):** `PayoutRunIndexRequest`, `StorePayoutRunRequest`, `UpdatePayoutRunDraftRequest`,
`SubmitPayoutRunRequest`, `CancelPayoutRunDraftRequest`, `VerifyPayoutRunRequest`,
`ApprovePayoutRunRequest`, `ApproveHighValuePayoutRunRequest`, `RejectPayoutRunRequest`,
`MarkPayoutRunPaidRequest`, `MerchantCompensationSummaryRequest`, `PersonnelEarningsIndexRequest`,
`PersonnelEarningsStatementRequest`, `StoreEarningsQueryRequest`, `EarningsQueryIndexRequest`,
`RespondToEarningsQueryRequest`. Every server-owned field (`merchant_id`/`branch_id`/`status`/actor
columns/totals/threshold snapshot/`payout_item_ids`/`external_payment_reference_encrypted`/
`staff_profile_ulid` on own-scope) is `prohibited`; `paid_date` is `before_or_equal:today`; a monetary
correction is valid only on a `resolved` decision.

**Resources (masked):** `PersonnelPayoutRunResource` (presence-only external-ref flag; no encrypted
value/actor ids), `PersonnelPayoutItemResource` (bucketed integer minor + source COUNTS only — never the
raw ledger ids), `EarningsQueryResource` (subject_type only — no internal subject id), and
`EarningsStatementResource` (safe 10F metadata; disk/path/sha `$hidden`). Money is always integer minor.

**Controllers (thin, 6):** `HrPayoutRunController`, `FinancePayoutRunController`,
`MerchantCompensationController`, `PersonnelEarningsController`, `PersonnelEarningsQueryController`,
`FinanceEarningsQueryController` + read model `MerchantCompensationSummaryReadModel` (currency-grouped
payout aggregates + reused liability read model). Statement generation calls `GenerateEarningsStatement`
then `FileAccessService::authorizeView` + `issueSignedUrl`; download reuses the existing `files.*`
routes (own-scope by `owner_user_id`).

**Routes (25) + classification:** 9 GET reads (role-owned permission gates), 4 HR `branch_mutation`,
5 Finance/MA `financial_mutation` (idempotency + fresh step-up on verify/approve/mark-paid/high-value),
1 Finance query-respond `financial_mutation` (idempotency), 1 personnel query-store `branch_mutation`,
1 statement-generate `tenant_mutation` + `EnsureBillingMutable`. Audit route→event map extended (12
mutation routes). `AuditMutationCoverage` + severities honour the typed `AuditEvent` cases.

**Contracts:** `docs/api/openapi.json` regenerated — **235 paths, 280 operations** (23 new Phase 20H
paths); `api.ts` + `permissions.ts` regenerated; `servana:permission-types --check` green;
`api:contract:check` green; **second generation produced no diff (deterministic — verified by file
hash).**

**Gates (green):**
- Phase 20H API suites — `Phase20HPayoutRunApiTest` **14**, `Phase20HEarningsApiTest` **11**,
  `Phase20HPermissionActivationTest` **6**.
- Parity/security/audit: `PermissionMatrixParityTest`, `PermissionMatrixSchemaTest`,
  `PermissionMatrixCatalogueCompletenessTest`, `PermissionMatrixPlanMetadataParityTest`
  (incl. route-derived `audit_event`), `PermissionPlannedKeyIsolationTest`,
  `RouteSecurityContractTest`, `FinancialRouteIdempotencyCoverageTest`, `AuditMutationCoverageTest`,
  `AuditSeverityCoverageTest`, `OpenApiContractTest` — all pass.
- Affected-regression groups (serial): **`--group=auth` 76**, **`--group=compensation` 478** (Phase
  20F/20G/20H incl. Inc2–4 domain suites + the new API suites), **`--group=security --group=audit`
  205**, file/receipt/manifest/tenancy batch **30**.
- **Pint clean; Larastan level 8 — 0 errors (1145 files); `git diff --check` clean.**

**No frontend, no Playwright, no Wallet/provider runtime, no direct money movement, no notification
center, no scheduled report delivery, no Phase 20D-W** — those are Increment 6 / later phases. Phase 20H
lifecycle stays **in_progress**; **no commit / push / PR** in Increment 5.

---

## Increment 6 — frontend (source inventory)

### Inc6-A — Source inventory (recorded before any frontend change, per §5)

**Frontend conventions (live repository).**
- **Routes:** `resources/spa/src/router/routes/{role}.ts` — one `RouteRecordRaw` per screen inside the
  role layout (`BranchLayout` for HR, `FinanceLayout` for Finance, `MerchantLayout`/personnel/merchant
  layouts), `beforeEnter: [requiresAuth, requiresActiveMerchant]`; a screen may add
  `beforeEnter: [requiresPermission('<key>')]` (UX only — the API is authoritative). Router assembles
  per-role arrays.
- **Navigation:** `resources/spa/src/navigation/roleNavigation.ts` — the Phase 20H items already exist as
  **`availability: 'planned'`** placeholders (`hr.payout-runs`, `finance.payout-runs`,
  `merchant.compensation-summary`, `personnel.my-earnings`). Increment 6 flips each to
  `availability: 'live'` + adds `routeName` (the `roleNavigation.spec.ts` snapshot regenerates
  `docs/frontend/navigation/role-navigation.yaml`; live⇒routeName set, planned⇒routeName absent). A new
  `finance.earnings-queries` live item is added.
- **Screen inventory:** `docs/frontend/screens/inventory.json` is the source of truth; four planned 20H
  entries exist (`merchant/compensation-summary`, `hr/hr-payout-prep`, `finance/finance-payouts`,
  `personnel/personnel-my-earnings`). Increment 6 flips them to `status: implemented` + sets `route` +
  `spec`, adds a fifth (`finance/finance-earnings-queries`), regenerates the per-entry §27.1 specs via
  `node scripts/generate-screen-specs.mjs`, and regenerates `inventory.yaml` via `screenInventory.spec.ts`
  (`toMatchFileSnapshot`). Guard: every implemented router route must have an inventory entry, and a
  planned entry must never carry a route.
- **Stores:** Pinia `defineStore` (setup style), typed via `components['schemas'][...]` from
  `resources/spa/src/types/generated/api.ts`; `apiClient` from `@/services/apiClient` (baseURL
  `/api/v1`, typed `err.apiError` envelope); Idempotency-Key = `crypto.randomUUID()` minted-on-submit /
  reused-on-retry (payload hash) / retired-on-success, never surfaced; `forbidden` flag on 403; `$reset`;
  only non-empty filters serialized; only server responses mutate local state (no client money/eligibility/
  snapshots). Template: `stores/compensationLiabilityStore.ts`.
- **Screens:** template `pages/finance/CompensationLiabilities.vue` — `Sv*` UI kit (`SvButton`, `SvCard`,
  `SvInput`, `SvModal`, `SvSelect`, `SvTextarea`, `SvStateBoundary`), `useCan()` for visibility gating,
  `formatMoney` (`@/utils/money`), a11y status region + focus remember/restore + `announce`, state
  computed (loading/empty/error/success + forbidden + no-permission), float-free `majorToMinor` parser,
  step-up-required safe state → `router.push({name:'auth.mfa.challenge'})`, error mapping
  (`step_up_required`/`privileged_mfa_required`/`idempotency_conflict`/`period_locked`/`validation_failed`).
- **Generated 20H schemas present in `api.ts`:** `PersonnelPayoutRunResource`, `PersonnelPayoutItemResource`,
  `EarningsQueryResource`, `EarningsStatementResource`. `permissions.ts` has all 16 active keys.

**Screens to build (5):**

| Screen key | Route name | URL | Layout | Role | Permission |
|---|---|---|---|---|---|
| `merchant/compensation-summary` | `merchant.compensation-summary` | `/merchant/compensation-summary` | MerchantLayout | Merchant Admin | `merchant.compensation_summary.view` |
| `hr/hr-payout-prep` (→ HR Payout Runs) | `hr.payout-runs` | `/hr/payout-runs` | BranchLayout | HR | `payout_run.create` |
| `finance/finance-payouts` (→ Finance Payout Runs) | `finance.payout-runs` | `/finance/payout-runs` | FinanceLayout | Finance | `payout_run.verify` |
| `personnel/personnel-my-earnings` (→ My Earnings) | `personnel.earnings` | `/personnel/earnings` | Personnel layout | Personnel | `personnel.my_earnings.view` |
| `finance/finance-earnings-queries` (new) | `finance.earnings-queries` | `/finance/earnings-queries` | FinanceLayout | Finance | `earnings_query.respond` |

**Stores (4):** `payoutRunStore` (HR draft + Finance/MA workflow; idempotency + step-up),
`merchantCompensationSummaryStore`, `personnelEarningsStore` (own-scope reads + statement generate),
`earningsQueryStore` (personnel create/list + Finance respond; idempotency + optional correction).

**Blocking product decisions:** none. Every screen consumes the Increment-5 generated contract; the file
subsystem supports statement download (own-scope signed link); mark-paid is an external-settlement record
(no provider confirmation); responder ownership is Finance (D-H12-1); high-value approval is MA-only.

### Inc6-B — implementation (COMPLETE + green)

**Contract-truth fix (DEF-20H-001, authorized narrow backend correction):** the generated `api.ts` typed
`PersonnelPayoutRunResource.paid_at` and `EarningsQueryResource.responded_at` as non-nullable, but the
server genuinely returns `null` (an unpaid run / an open query) — the same scramble `?->`-vs-ternary
inference gap recorded as DEF-20F-015. Converted ONLY these two genuinely-nullable 20H fields to the
explicit ternary the generator infers as nullable (JSON byte-identical at runtime), regenerated; OpenAPI
path/operation counts UNCHANGED at 235/280 confirming a schema-nullability-only change; the broader
repo-wide nullable sweep remains the recorded deferred follow-up (not a 20H blocker).

**Stores (4, typed from `api.ts`):** `payoutRunStore` (HR/Finance/MA role-owned route trees; branch
mutations without a key; financial mutations via one `financialAction` path that mints/reuses/retires an
Idempotency-Key by action+payload hash), `merchantCompensationSummaryStore`, `personnelEarningsStore`
(own-scope reads + statement generate; never sends a staff reference), `earningsQueryStore` (personnel
create/list + Finance respond; idempotent respond with an optional nested additive correction). Every
store: loading/empty/error/forbidden state; `$reset`; only non-empty filters; only server responses
mutate local state; no client money, no item snapshots, no key surfaced in the UI.

**Screens (5):** `hr/PayoutRuns.vue`, `finance/PayoutRuns.vue`, `merchant/CompensationSummary.vue`,
`personnel/Earnings.vue`, `finance/EarningsQueries.vue`. `Sv*` kit; `useCan` visibility gating;
`formatMoney`; a11y status region + focus remember/restore + `announce`; float-free `majorToMinor`
parser; step-up-required safe states → `auth.mfa.challenge`; safe error mapping
(`invalid_state_transition`/`idempotency_conflict`/`step_up_required`/`billing_read_only`/
`validation_failed`). HR shows no verify/approve/mark-paid; Finance mark-paid dialog states plainly it
records an external payment and moves no money (raw external reference never displayed after save); MA
shows no mark-paid; Personnel has no staff selector and downloads via the authorised signed file link;
the responder offers only an additive correction, never a ledger editor. No Wallet/provider/STK/PayBill/
Daraja wording anywhere.

**Routes:** `hr.payout-runs`, `finance.payout-runs`, `finance.earnings-queries`,
`merchant.compensation-summary`, `personnel.earnings` (role layouts; screen-level permission gating +
forbidden state, matching `finance.liabilities` — no route guard, so the forbidden state is reachable).

**Navigation + inventory + specs:** the four planned `roleNavigation.ts` placeholders flipped
`planned → live` (routeName added) + a new `finance.earnings-queries` live item; four planned
`inventory.json` entries flipped `implemented` + a fifth added; §27.1 specs regenerated via
`scripts/generate-screen-specs.mjs` (114 specs; only the 5 new files added); `inventory.yaml` +
`role-navigation.yaml` regenerated via their `toMatchFileSnapshot` specs (deterministic — pass without
`-u` afterward).

**Gates (green):**
- **Vitest 435 → 481** (+46): 4 store specs (24) + 5 component specs (22) — permission gating, role
  boundaries, idempotency key mint/reuse/remint, step-up safe state, own-scope no-staff-selector,
  additive-correction-only, no Wallet/provider wording, no client total/snapshot.
- Inventory/nav/RoleNavigation specs: 20 passed.
- **ESLint 0 errors** (new files add no warning); **vue-tsc clean** (no strictness lowered, no ignores);
  **npm run build PASS**.
- **Playwright 397 → 416** (+19; `phase-20h.spec.ts`): HR draft→submit (no mark-paid), Finance mark-paid
  with external ref + not-future paid date + step-up safe state, MA summary currency-grouping +
  high-value approve (no mark-paid), Personnel own earnings/tabs/statement-link/query (no staff
  selector), Finance responder additive correction (no ledger editor), role denial, responsive
  360/768/1280 (no overflow), 200% zoom, keyboard + Escape, **axe serious/critical = 0 light + dark**
  (page + mark-paid dialog). Full suite 416 passed.
- Backend contract reruns (76 passed): `Phase20HPayoutRunApiTest`, `Phase20HEarningsApiTest`,
  `Phase20HPermissionActivationTest`, `RouteSecurityContractTest`, `FinancialRouteIdempotencyCoverageTest`,
  `AuditMutationCoverageTest`, `AuditSeverityCoverageTest`, `OpenApiContractTest` (byte-current, 235/280),
  `PermissionMatrixParityTest`, `PermissionPlannedKeyIsolationTest`, `NoDirectProviderIntegrationTest`,
  `FileDownloadAuthorizationTest`. **Pint clean; Larastan L8 — 0 errors.**

**No completion commit, no push, no PR, no Wallet/provider runtime, no money movement, no notification
center, no scheduled report delivery, no Phase 20D-W.** Phase 20H lifecycle stays **in_progress**; the
next increment is Increment 7 (full local gates + disposable PG16 proof + scope-purity audit + single
completion commit + push, stop before PR).

---

## Increment 7 — local acceptance gates (run) + completion HELD on an inherited dependency advisory

**All Phase 20H functional, contract, frontend, browser, Docker, and non-dependency security gates are
GREEN:**

| Gate | Result |
|---|---|
| `composer validate --strict` | valid |
| `vendor/bin/pint --test` | clean (1474 files) |
| `composer stan` (Larastan L8) | 0 errors |
| Full backend **serial** (`php artisan test`) | **1663 passed, 7 skipped, 0 failed** (9898 assertions, 974s) |
| Full backend **parallel** (`--parallel`, 4 procs) | **1663 passed, 7 skipped, 0 failed** (504s) |
| Disposable **PostgreSQL 16.14** fresh-build proof | 90 tables from 111 migrations; `personnel_payout_runs`/`personnel_payout_items`/`earnings_queries` present; `personnel_payout_items.earnings_statement_file_id` present; `merchant_subscriptions.high_value_payout_threshold_minor` present; `payout_item_id` FKs on `commission_ledger`/`salary_ledger`/`compensation_adjustments`; freeze triggers `personnel_payout_items_no_frozen_delete` + `…_no_snapshot_update`; **no wallet/daraja/stk/paybill/provider-callback/notification-center/scheduled-delivery/sms-provider tables**; DB dropped; dev DB `servana` intact |
| Contract determinism | `openapi.json`/`api.ts`/`permissions.ts` byte-stable across a second generation (hash-verified); `servana:permission-types --check` green; `api:contract:check` green — **235 paths / 280 operations** |
| ESLint | 0 errors (138 pre-existing warnings, none new) |
| vue-tsc | clean |
| Vitest | **481 passed** (96 files) |
| production SPA build | PASS |
| Full Playwright | **416 passed**; axe serious/critical = 0 |
| gitleaks | **clean** — one FALSE POSITIVE fixed: `generic-api-key` matched `API: \`Phase20HPayoutRunApiTest\`` in this proof (reworded to "API suites —"); no real secret |
| Docker dev app / prod app / prod nginx | all built |

**Completion commit HELD — inherited dependency-audit advisory (product-owner decision: Option 1, remediate first):**
- `npm audit --audit-level=high` **fails** on **`axios`** HIGH (GHSA-gcfj-64vw-6mp9 — Node HTTP adapter can use an inherited proxy after interceptor config cloning; production HTTP client; fixed in ≥1.18.0) plus transitive **`brace-expansion`** and **`js-yaml`** HIGH.
- `composer audit --locked`: `guzzlehttp/guzzle` — 5 advisories, all **medium** (below the high/critical guardrail; no high/critical).
- **Proven inherited:** `package-lock.json` and `composer.lock` are **byte-identical to `origin/main`** (`git diff --quiet origin/main --` clean for both) — Phase 20H changed **no** dependency files, so `origin/main` fails the identical gate today; the advisories were disclosed after the branch base `dcdbfb6`.
- Per CLAUDE.md guardrail §7 (no `npm audit`/`composer audit` high+critical) the production axios HIGH blocks the completion commit, and the only fix is a dependency bump that **broadens beyond Phase 20H** (repo-wide dependency maintenance). Per the product-owner decision, dependency remediation is isolated to a **separate branch off `origin/main`** (`security/dependency-audit-high-remediation` in a separate worktree) so the Phase 20H payout/earnings commit stays pure. **Phase 20H is NOT committed/pushed; the dirty tree is preserved; lifecycle remains `in_progress`.**
- Phase 20H closure resumes only after the dependency-remediation branch merges into `main` and the Phase 20H branch is refreshed/rebased from the remediated `main` and its closure gates rerun green (explicit follow-up).

---

## Increment 7 (resumed) — PR #42 remediation merged, branch refreshed, closure gates re-run

The inherited-advisory blocker recorded above is **resolved**. Dependency remediation was completed on a
separate branch off `origin/main` and merged as **PR #42**; the Phase 20H branch was then refreshed onto
the remediated `main` with **no code churn**, and every Increment 7 closure gate is re-run on the new base.

### PR #42 — dependency remediation (verified)

| Fact | Value |
|---|---|
| PR | **#42** — "Security: Remediate inherited dependency audit advisories" |
| State | **MERGED** into `main` |
| Head before squash | `caa7161bece583e009dfe2bfca762dcfe3261689` |
| Squash merge commit | `1879110de6cb1d73ef82403dd7007cca447f8c5c` (== `origin/main`) |
| Merged at | `2026-07-21T14:36:20Z` |
| Final CI run | **29838903181** — Backend / Frontend / Docker / Security / E2E — Playwright all COMPLETED + SUCCESS |
| `reviewDecision` | **blank** — solo-maintainer governance exception (`docs/governance/solo-maintainer-review-exception-pr-42.md`), not independent review |
| Files changed by PR #42 | `composer.lock`, `package-lock.json`, `package.json`, `docs/governance/solo-maintainer-review-exception-pr-42.md` (dependency + governance only) |
| Remediation branch | deleted locally **and** on `origin` after merge |

### Refresh (git integrity preserved)

| Fact | Value |
|---|---|
| Pre-refresh HEAD | `dcdbfb69f338f1cbdf13c0a0b507ef600cfe7f14` (Phase 20G PR #41 base) |
| Post-refresh HEAD | `1879110de6cb1d73ef82403dd7007cca447f8c5c` (PR #42 remediated main) |
| Command | `git reset --keep origin/main` (preserves local changes; moves branch base only) |
| Overlap of Phase 20H dirty tree with PR #42 files | **0** (verified before refresh) |
| Divergence `origin/main...HEAD` after refresh | `0 0` |
| Phase 20H dirty entries preserved | **139** (35 modified tracked + 104 untracked) |
| PR #42 files in Phase 20H diff after refresh | **none** — `git diff origin/main -- composer.lock package-lock.json package.json docs/governance/solo-maintainer-review-exception-pr-42.md` is empty |
| External backup (outside repo) | `C:\Users\nderu\Documents\Development\Product\phase20h-refresh-backup-20260721-181845` (tracked diff patch + 104 untracked files copied) |
| `git fsck --full` | clean (one harmless dangling commit) · `git diff --check` clean |

### Dependency gates now GREEN (the exact gate that was blocked)

| Gate | Result |
|---|---|
| `npm audit --audit-level=high` | **found 0 vulnerabilities** |
| `composer audit --locked --no-interaction` | **No security vulnerability advisories found.** |

### Increment 7 closure gates — RE-RUN GREEN on the refreshed base `1879110`

In-container Composer deps were resynced to the refreshed `composer.lock` first (`composer install` —
`guzzlehttp/guzzle 7.12.1 → 7.15.1`, `guzzlehttp/promises 2.5.0 → 2.5.1`, the composer remediation).

| Gate | Result (refreshed base) |
|---|---|
| `npm audit --audit-level=high` | **found 0 vulnerabilities** |
| `composer audit --locked --no-interaction` | **No security vulnerability advisories found.** |
| `composer validate --strict` | valid |
| `vendor/bin/pint --test` | PASS (1474 files) |
| `composer stan` (Larastan L8) | **0 errors** (1145 files) |
| Full backend **serial** (`php artisan test`) | **1663 passed, 7 skipped, 0 failed** (9898 assertions, 1245s) |
| Full backend **parallel** (`--parallel`, 4 procs) | **1663 passed, 7 skipped, 0 failed** (9898 assertions, 625s) |
| Disposable **PostgreSQL 16.14** proof | DB `servana_p20h_proof_20260721191358`; **111 migrations / 90 tables**; `personnel_payout_runs`/`personnel_payout_items`/`earnings_queries` present; `earnings_statement_file_id` present; `merchant_subscriptions.high_value_payout_threshold_minor` present; `payout_item_id` FKs on `commission_ledger`/`salary_ledger`/`compensation_adjustments`; triggers `personnel_payout_items_no_frozen_delete` + `…_no_snapshot_update`; 5 `earnings_queries` CHECK constraints; **forbidden-table scan empty**; DB dropped; dev DB `servana` intact |
| Contract determinism | `openapi.json` / `api.ts` / `permissions.ts` **byte-stable** across regeneration (SHA256-verified ×3); `servana:permission-types --check` up to date; `api:contract:check` OK — **235 paths / 280 operations** |
| ESLint | **0 errors** (138 pre-existing warnings, none new) |
| vue-tsc (`npm run typecheck`) | clean |
| Vitest (`npm run test`) | **481 passed** (96 files) |
| Production SPA build (`npm run build`) | PASS |
| Inventory / navigation parity | green — `screenInventory` + `roleNavigation` snapshot specs pass within Vitest (byte-current, no `-u`) |
| Full Playwright (`npx playwright test`) | **416 passed, 0 failed**; axe serious/critical = 0 (light + dark) |
| gitleaks (`detect --no-git --redact`) | **no leaks found** |
| Docker dev app / prod app / prod nginx | all **Built** |

**Flake resolved (environment/load).** The first full Playwright run reported 1 failure —
`payment.spec.ts:67` (Phase **18A** payment recording, unrelated to Phase 20H): `getByTestId('available-amount')`
not found within the 5s timeout during the sustained single-worker run. Classification: **environment/load
flake** (page-data render timeout under load; not a code/test defect, not a Phase 20H test). Isolated re-run
of `payment.spec.ts` → **10/10 passed** (the exact test ok in 1.6s); clean full-suite re-run → **416/0 passed**.
No code change made. The first full Vitest run likewise hit forks-pool worker-start timeouts under concurrent
backend load (425/425 that ran passed, 3 worker-start errors); the isolated re-run → **481/481**. Both are
CPU-contention flakes from overlapping heavy suites, resolved by isolated re-runs; no product behaviour changed.

**Scope purity (pre-commit).** Working tree = **139 Phase 20H entries** (35 modified tracked + 104 untracked);
`git diff --check` clean; **0 overlap** with PR #42 files; `git diff origin/main -- composer.lock
package-lock.json package.json` empty (PR #42 dependency files are in the base, absent from the Phase 20H
diff); **0 forbidden paths** (no `node_modules`/`vendor`/`.env`/reports/dumps); forbidden-runtime scan clean —
the only `mpesa`/`Wallet`/`STK`/`PayBill`/`Daraja`/`Till` matches are the legitimate Phase 18A `mpesa_offline`
customer payment-method enum, mark-paid external-reference **test fixtures**, and **negative absence
assertions** proving the UI contains none of those provider terms. **No Wallet/provider runtime, no direct
money movement, no notification-center, no scheduled-report-delivery runtime.**

Phase 20H lifecycle: **local_complete pending PR CI/review/merge** after the single completion commit + branch
push below. **No Phase 20H PR is created** — that requires separate product-owner authorization.
