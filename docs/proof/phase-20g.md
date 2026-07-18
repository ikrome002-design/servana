# Phase 20G — Salary Accrual and Commission Processing — Proof

> Lifecycle: **local_complete pending PR CI/review/merge** (branch `phase-20g-salary-commission-ledgers`,
> based on `origin/main` = `57dce1031ce10c37977540a0e63b1491d444b877` = the post-20F hardening PR #40
> merge commit). Increments 1–7 complete + green; committed as ONE Phase 20G implementation commit and
> pushed (the completion commit hash is reported in the session, not recorded here to avoid a
> hash-only second commit). **NOT** `ci_passed` / `merged` / `verified_complete` — no PR exists yet and
> no independent reviewer approval has occurred.
>
> Specification-first, per the Phase 20A/20C/20E/20F precedent: **no migration is created until
> every material gate (G1–G12) is resolved and the data dictionary + state machines are written.**
>
> Controlling sources: **Plan §60** (Salary Processing, Correction 19.5), **Plan §61** (Commission
> Processing, Correction 19.4), **Plan §13.12** (canonical compensation DDL), **Plan §80** (Phase
> 20G entry), Plan §10.2 (authority boundaries), Plan §13.1–§13.3 (schema conventions), Plan
> §19.2/§19.3 (permission catalogue/matrix), Plan §24 (idempotency/concurrency), Plan §25/§75
> (testing), Plan §67 (scheduled-task ownership), Plan §69 (reporting), Plan §70 (audit), Plan §71
> (observability); ADR-002 (tenancy), ADR-004 (forward-only migrations), ADR-005 (integer money,
> round-half-up, largest-remainder residual). Product-owner decisions of record: the **Actual/Actual
> salary proration convention** (G8) and the **`commission_rule_services` selected-services
> substrate** (§9.1), both captured verbatim in §Decisions below.
>
> **Phase 20G creates financial facts.** It accrues salary (`salary_ledger`), earns commission at
> Finance validation (`commission_ledger`), and records compensation adjustments
> (`compensation_adjustments`). It creates **no** payout run/item, earnings query/statement,
> mark-paid transition, Wallet/provider runtime, or paid-state ledger linkage — those are Phase 20H
> / 20D-W / 21N.

## G1 — Prior-phase and hardening reconciliation

| Fact | Evidence |
|---|---|
| Phase 20F merged | PR #39 squash `f4bc664b7ba77476f9db01dcb0ec1a526dc20538`; `origin/main` == `f4bc664` at 20F close; merged 2026-07-17T12:11:44Z → `verified_complete` |
| Post-20F hardening merged | PR #40 impl `cdcb83fc…`; governance head `53a595bb…`; merge `57dce103…`; merged 2026-07-17T14:43:17Z; CI Backend/Frontend/Docker/Security/E2E all SUCCESS (runs `29588324838`→`29588846573`); `reviewDecision` blank (solo-maintainer exception) → `verified_complete` |
| main synchronized | local `main` == `origin/main` == merge-base == `57dce103…`; divergence `0 0`; tree clean; `git fsck` clean |
| Hardening branches deleted | local + remote `hardening/resource-contracts-and-accessibility-tokens` absent |
| 20G branch created | `phase-20g-salary-commission-ledgers` off `57dce103…`; HEAD = merge-base = `57dce103…`; clean |

## Gate W

**CLOSED.** `docs/integrations/wallet/` (and `gate-w-evidence.md`) absent — no Wallet Servana
Collections Slice evidence, sandbox service-account credentials, pinned Wallet OpenAPI hash, passing
contract suite, sandbox STK/C2B transcript, signed-webhook transcript, reconciliation transcript, or
explicit PASS. Phase 20D-W stays **blocked**. Phase 20G introduces **no** Wallet/provider runtime and
is independently eligible (`20F + 18B(validated payments) → 20G`).

## Decision table (G1–G12 + inherited seams)

| Gate | Decision | Authority |
|---|---|---|
| G1 | Reconciled (above); 20G branch from PR #40 base | PR #39/#40; local git |
| G2 | `commission_ledger`, `salary_ledger`, `compensation_adjustments` per §13.12 canonical DDL; add `commission_rule_services` (§9.1). All BRANCH_OWNED (merchant_id + branch_id; composite FK → `merchant_branches(id, merchant_id)`); public ULID; integer minor units; uppercase currency; backed enums + DB CHECKs; append-only monetary facts (BEFORE UPDATE/DELETE guard triggers); status-only transitions via state machines/actions; ULID route binding inside tenant scope → foreign IDs 404. **`payout_item_id` nullable, NO FK** (20H `personnel_payout_items` does not exist yet → Phase 20H expand migration adds the FK; ADR-004 forward-compatible). | Plan §13.12; ADR-002/004 |
| G3 | Commission earned **only** at Finance validation, through the **pre-built durable idempotent `commission_handoff_events` outbox** written atomically inside `ValidatePaymentRecordingGroup` (kind `validated_allocation`) and `FinalizeRefund` (kind `reversal`). A 20G consumer service reads unconsumed handoffs, creates `commission_ledger` earned/reversal rows idempotently, and stamps `consumed_at`. Invoked after-commit for timeliness **and** by a reconciliation sweep for guaranteed completion. Payment recording, service-session completion, and invoice finalization write no handoff → no earned commission. Commission-creation failure does **not** roll back validation because the durable outbox guarantees eventual, idempotent completion (the sanctioned atomic-outbox in G3). | Plan §61; §24; existing 18B seam |
| G4 | Commission basis computed against the **shipped 20F `CommissionCalculationBasis` enum** (`service_price`, `invoice_item_total`, `paid_amount`, `net_after_discount`) — repository-authoritative (merged, immutable DB CHECK; `personnel_compensation_plans` → `commission_rules` configure against it). Each basis maps to a server-derived per-item amount (see §Basis mapping). Plan §13.12 DDL text (`service_item_net/gross_amount`, `validated_paid_allocation`) predates the 20F F4 refinement — **noted divergence resolved by hierarchy L3**, not a blocker (the shipped migration is immutable; 20G has no discretion). | Repo (L3); Plan §13.12; docs/proof/phase-20f.md §F4 |
| G5 | Fixed commission "capped where required" = **capped at the item's eligible validated allocation** (min(fixed_rate_minor, eligible validated allocation for the item)), grounded in the §13.12 invariant "sum of allocations ≤ eligible validated allocation". No merchant/platform cap-settings surface exists (20F F4) → none invented. | Plan §13.12 invariant; §61 |
| G6 | Earned idempotency: DB unique `(payment_validation_event_id, invoice_item_id, staff_profile_id, entry_type)` filtered to `entry_type='earned'`. Salary idempotency: DB unique `(compensation_plan_id, staff_profile_id, pay_period_segment_key, entry_type)`. Reversal idempotency: DB unique per source reversal event (handoff/refund/void). Enforced in PostgreSQL, not only application code. | Plan §13.12; §24 |
| G7 | Reversal = **new append-only negative row**, `amount_minor` = exact negative of the stored original (never recomputed), `source_entry_id` → original, `reversal_reason` from the canonical enum; idempotent per source reversal event; original row byte-immutable. Already-paid reversal → **negative `compensation_adjustment`** for a future 20H payout (paid history never rewritten; no payout run created in 20G). | Plan §61; ADR-005 |
| **G8** | **Actual/Actual calendar-day salary proration (product-owner decision — see §Decisions).** | Product owner; Plan §60 |
| G9 | **No approved attendance/shift source exists** (no `attendance`/`shift`/`timesheet`/`roster` table or model, migrations or `app/`). monthly/weekly accrue independently; **daily/hourly/per_shift fail closed** with a typed guard — no inferred hours (§60). Owner of an approved attendance/shift substrate = **unbuilt future HR phase** (not 20G, not 20H). | Plan §60; repo audit |
| G10 | `suspension_salary_policy` already shipped on `personnel_compensation_plans` (default `continue`, CHECK `pause`/`continue`; Plan A-11). Prospective override to `pause` = **supersede the plan to a new effective-dated version** (rides the existing immutable effective-dated supersession + approval flow); never rewrites accrued salary. No new substrate. HR proposes; existing plan-approval flow approves. | Plan A-11; §60; 20F schema |
| **§9.1** | **Build normalized `commission_rule_services` substrate in 20G (product-owner decision — see §Decisions).** | Product owner; Plan §61 |
| §9.2 | General compensation-plan activation/expiry scheduler stays **21N** (notification/scheduled-task orchestration). 20G runs only its own **salary-accrual** scheduler and resolves plans/rules by effective dates (existing date-based resolvers). | Plan §67; §80 |
| §9.3 | Pre-approval impact-preview endpoint is **not** a 20G ledger input → stays a **20F configuration-UX follow-up** (existing `BuildCompensationPlanImpactPreview` action). Not closed by 20G. | Plan §59 |
| §9.4 | HR multi-branch selector unchanged — fail-closed branch scope preserved; not a 20G concern. | Plan §10.2 |
| G11 | Activate only `compensation.liability.view` + `compensation.adjustment.create` (Finance default; MFA; `adjustment.create` fresh step-up + high-severity audit; adjustment entry only). Commission-rule selected-services configuration reuses existing 20F compensation permissions — **no `commission.rule.*` invented**. No 20H keys activated. | Plan §19.2/§19.3 (matrix lines 1558–1559) |
| G12 | Salary scheduler: Africa/Nairobi business boundaries (existing `CompensationBusinessDate`), explicit cadence, tenant-aware jobs (`TenantAwareJob`), unique segment key, safe retries, concurrent-run protection, failure visibility, no duplicate accruals. Period locks respected (`FinancialPeriodGuard`/`PeriodLockService`); locked-period correction only via additive adjustment/reversal. Audit via the existing append-only hash-chained system (salary accrued/reversed/adjusted; commission earned/reversed; adjustment created/approved). Denied actions write no success audit. | Plan §24/§67/§70/§71 |

## Basis mapping (G4) — shipped enum → server-derived per-item amount

All amounts integer minor units, server-derived from immutable invoice/validation facts; never
browser-supplied. Per invoice item (`invoice_items`):

| `calculation_basis` | Amount |
|---|---|
| `service_price` | `unit_price_minor × quantity` (gross catalogue price on the item, pre-discount) |
| `invoice_item_total` | `line_total_minor` (the item total as invoiced) |
| `net_after_discount` | `line_total_minor` − discount allocated to the item (0 if no discount substrate applies) |
| `paid_amount` | the item's share of the validation event's validated allocation (largest-remainder split of `payment_validation_events.validated_amount_minor` across eligible items, proportional to each item's `line_total_minor`) |

`applies_to_preferred_personnel_fee` (rule flag) governs whether the item's
`preferred_personnel_fee_minor` is **included in** the basis amount. `paid_amount` is inherently
capped by the validated allocation; the other bases are additionally capped so that per-item earned
commission ≤ the item's eligible validated allocation (G5).

## Decisions (verbatim product-owner rulings of record)

### G8 — Actual/Actual calendar-day salary proration

Africa/Nairobi for all boundaries and day counts; **calendar** days (Sat/Sun/holidays count in both
numerator and denominator); no inferred work calendar/attendance for monthly or weekly salary;
daily/hourly/per_shift remain fail-closed until an approved attendance/shift source exists.

- **Monthly period** = one Nairobi calendar month `[first day 00:00, first day of next month 00:00)`;
  denominator = actual days in that month (28/29/30/31);
  `exact_segment_minor = monthly_salary_minor × payable_days_in_segment ÷ days_in_month`. Not a fixed
  30-day denominator; not working days; not 365/12; no cross-month leap adjustment.
- **Weekly period** = ISO Nairobi week `[Mon 00:00, next Mon 00:00)`; denominator always 7;
  `exact_segment_minor = weekly_salary_minor × payable_days_in_segment ÷ 7`. Not five working days;
  week not merchant-configurable in 20G.
- **Segmentation:** split at any salary-affecting boundary — plan `effective_from`/`effective_to`, a
  superseding plan `effective_from`, prospective pause effective date, resumption date, termination
  boundary, pay-period boundary. Plan windows half-open `[effective_from, effective_to)`; new plan
  payable from `effective_from`; prior plan payable through the day before its `effective_to`; no
  double-count and no gap unless configuration has a genuine gap.
- **Suspension/resumption/termination:** `continue` accrues normally during suspension; prospective
  `pause` — its effective date is the first non-payable date; resumption date is the first payable
  date after a pause; termination date is the **final payable calendar day** (represented internally
  as exclusive boundary `termination_date + 1 day`); never retroactively rewrite an accrued segment.
- **Rounding/residual (integer only):** build all payable segments; compute each segment's exact
  rational without float; sum exact amounts; **round the pay-period total once** via ADR-005
  round-half-up; floor each segment; allocate the residual minor units by **largest remainder**
  (rank by descending fractional remainder; tie-break ascending segment start date → ascending
  compensation-plan ULID → ascending salary-ledger ULID / deterministic segment key); segment rows
  sum exactly to the rounded period total; ≤ one residual unit per segment per pass; never float.
- **Mid-period plan change:** each segment uses the salary snapshotted from the plan effective for
  that segment; period total = rounded sum of exact segment values; never average rates; never
  recompute a prior segment after its ledger fact exists.
- **Invariants:** an unchanged monthly plan payable for a whole month accrues exactly one full
  monthly salary (28/29/30/31 alike); an unchanged weekly plan payable all 7 days accrues one full
  weekly salary; no overlapping accrual for one calendar day; a genuine gap accrues nothing; replay
  creates no duplicate; corrections are additive (reversal/adjustment); originals immutable.

### §9.1 — `commission_rule_services` selected-services substrate

Do not disable `selected_services`; do not reinterpret it as `all_services`/`service_category`; do
not store service IDs in JSON; do not let Finance validation guess eligibility. Add a forward-only
normalized table `commission_rule_services` (`id`, `ulid` if repo convention requires, `merchant_id`,
`branch_id`, `commission_rule_id`, `service_id`, `created_at`[+`updated_at` only if the immutable
pattern requires]) with: unique `(commission_rule_id, service_id)`; composite FKs enforcing
merchant/branch consistency for **both** the rule and the service; `RESTRICT`/archival-safe on service
delete; no JSON; no cross-tenant/branch membership; registered in tenancy + composite-consistency
registries. Branch-owned configuration substrate — no money/payout/settlement fields.

- **Validity:** `all_services` ⇒ 0 memberships + null category; `service_category` ⇒ category + 0
  memberships; `selected_services` ⇒ null category + ≥1 membership before leaving draft (a draft may
  temporarily have 0 while editing; a non-draft may not — enforced by domain validation and a DB
  guard). Existing 20F value-shape constraints unchanged.
- **Immutability/versioning:** memberships mutable only while the rule is `draft`; immutable after
  submit/approve/schedule/activate/supersede/reject/cancel/expire; changing services on an effective
  rule requires a new draft/superseding version; historical memberships never deleted or destructively
  altered.
- **Preflight:** before activating the calculation path, query live rules with
  `applies_to='selected_services'` by status. Draft w/ 0 membership → preserve as draft. **Non-draft
  w/ 0 membership → STOP and report ULIDs/merchant/branch/status/effective window; do not fabricate,
  do not treat as all_services, do not activate earning.** None → record "no data remediation".
- **API/Form Request:** extend the 20F commission-rule draft create/update with
  `selected_service_ulids` (required, non-empty, distinct for `selected_services`; forbidden/empty
  otherwise; each resolves inside the acting merchant+branch and same branch as the rule; archived
  services follow the repo service-selection rule; server owns identities); masked Resource returns
  selected services by ULID; OpenAPI/TypeScript regenerated by generators only.
- **HR frontend:** the commission-rule draft form shows a branch-scoped service multi-select for
  `selected_services` (≥1, add/remove only while draft, server-returned selections, validation errors,
  keyboard/360/768/1280/200%/light+dark/axe 0); switching away sends an empty list; no money computed
  in JS.
- **Finance validation eligibility:** an item under a `selected_services` rule is eligible only when
  its authoritative `service_id` ∈ the resolved rule's immutable membership set; no match → no earned
  row; missing membership substrate for a non-draft `selected_services` rule → **fail closed** (typed
  invariant/config error); never fall back to `all_services`; snapshot rule/service/basis/rate/source
  identities on the earned fact.
- **Permissions:** HR commission-rule configuration reuses existing 20F compensation permissions (no
  `commission.rule.*` invented); Finance only reads liability/source detail via 20G-authorized
  permissions and never edits membership; Merchant Admin never configures commission rules.

Recorded as an inherited Phase 20F integration seam **closed by Phase 20G** (20F correctly recorded
the missing substrate as deferred; 20G closes it because truthful commission calculation requires a
normalized membership source).

## Increment status

- **Increment 1 — reconciliation + specification: COMPLETE.** PR #39/#40 reconciled; stale 20F
  continuity corrected; Gate W recorded CLOSED; 20G branch created; G1–G12 resolved; two
  product-owner decisions captured; this decision table written.
- **Increment 2 — schema, enums, models, tenancy: COMPLETE (verified).** Four forward-only
  migrations applied + verified on PostgreSQL 16 (`commission_rule_services`, `commission_ledger`,
  `salary_ledger`, `compensation_adjustments`, + the additive `invoice_items_id_merchant_id_unique`
  FK-target index); 6 enums; 4 models; 4 factories (all create valid rows); `TenantOwnership`
  registration (BRANCH_OWNED + MODELS + COMPOSITE_CONSISTENCY); migration manifest; data-dictionary;
  2 ledger state-machine specs. **Green:** `Phase20GEnumParityTest` (6), `Phase20GSchemaTest` (16 —
  append-only DELETE/UPDATE guards, earned + accrual + reversal idempotency uniques, selected-
  services membership immutability + same-branch + non-draft-zero-membership guards), plus
  `MigrationManifestTest` / `TenantColumnCoverageTest` / `ModelTenancyTraitCoverageTest` (18).
  §9.1 live-data preflight: **0 existing `selected_services` rules → no remediation.**
- **Increment 3 — domain calculations & state machines: COMPLETE.** All domain financial logic
  built + tested. **Salary:** `LargestRemainderAllocator`, `SalarySegment` VO,
  `SalaryProrationCalculator` (G8 Actual/Actual crux), `SalarySegmenter` (plan-version lineage →
  payable segments; suspension `pause`/resumption/termination via effective-dated plan versions;
  daily/hourly/per_shift fail-closed), `AccrueSalaryForPayPeriod` action (idempotent, subject-lock,
  period-lock aware), `compensation:accrue-salary` scheduled command (Africa/Nairobi daily,
  tenant-aware, closed-period cutoff). **Commission:** `CommissionEarningResolver` (4 shipped bases,
  eligibility, largest-remainder allocation, cap), `EarnCommissionForValidationEvent`,
  `CommissionHandoffConsumer` (atomic outbox consumption, causal-order-safe),
  `ReverseCommissionEntry` / `ReverseSalaryAccrual` (exact-negative or paid adjustment),
  `RecordCompensationAdjustment`, `CommissionLedgerStateMachine` / `SalaryLedgerStateMachine`, 7
  typed audit events. **G10 substrate:** `suspension_salary_policy` was missing from the shipped 20F
  plan table → added by forward-only expand `2026_07_17_000005` (default `continue`, CHECK, immutable
  once non-draft) + `SuspensionSalaryPolicy` enum. **Accrual cutoff decision (§6.3):** accrue at the
  **CLOSED pay-period boundary** — the scheduler only passes a period whose exclusive end has arrived
  in Africa/Nairobi, so no future day and no provisional row; segments (incl. mid-period splits)
  accrue together at close. **Tests green (Increment 3):** `SalaryAccrualTest` (10),
  `SalaryProrationCalculatorTest` (7), `CommissionEarningTest` (10), `CommissionReversalTest` (5),
  `CompensationAdjustmentTest` (3), `Phase20GStateMachineTest` (2), `Phase20GTenantIsolationTest` (4).
  **Reversal scope note:** the consumer's reversal branch consumes the refund-finalization handoff;
  the authoritative full/partial semantics, invoice-void, and payment-reversal seams are **Increment 4**.
- **Increment 4 — 18B financial-integration seams + reversal semantics: COMPLETE (this session).**
  See “Increment 4” below.
- **Increments 5–7: pending** — permissions activation + liability/adjustment API + masked Resources +
  contract/OpenAPI regen, Vue frontend (Finance liability + adjustment; HR selected-services multi-select),
  full local closure + Docker + disposable PG16 fresh-build proof + single completion commit + push.

## Increment 4 — Finance-source integration + reversal semantics

### Source-seam inventory (verified in live code)

| Seam | Source action (in-txn) | Handoff written | Consumer effect | Idempotency identity |
|---|---|---|---|---|
| Finance validation | `ValidatePaymentRecordingGroup` | `validated_allocation` per component | earns one row per (validation event, invoice item, staff) | `(payment_validation_event_id, payment_record_id)` partial unique |
| Refund finalization | `FinalizeRefund` | `reversal` per component, `amount_minor` = reversed **payment** amount | exact-negative reversal only when the WHOLE validated allocation is refunded | `(refund_id, payment_record_id)` partial unique + `commission_ledger (source_entry_id) WHERE reversal` |
| Invoice void | `ExecuteInvoiceVoid` | **none** | **none** — void does not invalidate the validated allocation (`validated_paid_minor` and payment records are untouched); refund is the authoritative reversal path | n/a |
| Payment reject/correction | `RejectPaymentRecordingGroup` / `RequestPaymentGroupCorrection` | **none** | **none** — both require `pending_validation` (pre-earning); no canonical post-validation payment-reversal event exists | n/a |

### Product-owner resolution of record (2026-07-18) — exact-negative reversal

A Plan-vs-continuation-prompt conflict was surfaced and resolved **in favour of the Plan**. ADR-005
(line 417), Plan §61 (line 2305), the commission-ledger data dictionary
(`billing-and-wallet.md:1185`) and state-machine spec (`commission-ledger-entry.md:70–71`) all require
a reversal to be the **exact negative of the original stored amount (never recomputed)** with **at most
one reversal per original** (`commission_ledger (source_entry_id) WHERE entry_type='reversal'`). The
continuation prompt’s proportional-partial-recompute / multiple-reversals-per-original / uniqueness-
including-source-event language was **withdrawn** and is NOT implemented. No schema change was made;
`ReverseCommissionEntry` and the reversal uniqueness are unchanged.

Because commission is earned per validation event across all items and there is **no immutable item-
level refund attribution** (`payment_records`/`refunds` carry only `invoice_id`/`payment_record_id` +
`amount_minor`; the handoff writer sets `invoice_item_id = NULL`), a fractional reversal cannot be
truthfully derived. So the earned rows are reversed **only once the entire validated allocation has
been refunded**, using the cumulative finalized-refund total derived from immutable source rows:

- `cumulative finalized refunds < validated allocation` → **no reversal** (a partial refund never
  fractionally reverses); a valid **no-effect** event, consumed and re-evaluable (a later refund’s own
  handoff completes it). No success audit, no failure noise.
- `cumulative = validated allocation` → **exact-negative** reversal of every remaining earned row,
  exactly once (DB unique is the final backstop). Causal order preserved: if the earning is not yet
  consumed, `originalNotYetEarned` leaves the event retryable.
- `cumulative > validated allocation` → **fail CLOSED**
  (`cumulativeReversalExceedsValidatedAllocation`); no reversal, event stays retryable, a
  `compensation.handoff.failed` audit is the only signal.

`ExecuteInvoiceVoid` writes no commission handoff and the void produces no reversal — proven, not
assumed. Pre-validation reject/correction earn nothing, so they can never reverse commission.

### Changes

- `CommissionHandoffConsumer::consumeReversal` — rewritten to the cumulative-full-reversal rule above;
  resolves the single `validated` event for the component’s recording group and sums finalized refunds
  across the group’s components from immutable rows; `process()` summary gains `deferred_partial`.
- `CompensationLedgerException::cumulativeReversalExceedsValidatedAllocation()` — new fail-closed code.
- `RefundFactory::finalized()` — test-support state (approval + finalization provenance), since a
  `reversal` handoff only exists for a finalized refund.
- Phase 20F guard reconciliation (`CompensationPlanApiTest`, `CompensationPlanActionTest`): the 20G
  ledger tables now legitimately exist, so those two committed guards assert **zero rows** written by a
  20F flow instead of table absence (20H payout/earnings tables still asserted absent). `Phase20FSchemaTest`
  was already reconciled in Increment 2.

### Tests (Increment 4)

- `CommissionRefundReversalTest` (10): full-refund exact-negative + original unchanged; partial <
  allocation → no reversal; multiple partials still < total → no reversal; the completing refund →
  reverse once; replay → no duplicate; DB one-reversal-per-original rejects a second row; cumulative >
  allocation → fail closed (+ retryable audit, no success audit); causal deferral when earning not yet
  consumed; repeated consume → single result; already-paid original → negative adjustment, paid history
  intact.
- `CommissionReversalSeamTest` (2): invoice void reverses nothing + writes no reversal handoff;
  pre-validation rejection earns/reverses nothing.
- `CommissionEarningTest` full-refund reversal case updated to use a **finalized** refund (production
  truth).

## Scope exclusions (owners)

Payout runs/items, earnings queries/statements, mark-paid, payout approval, paid-state ledger linkage
→ **20H**. Wallet/provider runtime → **20D-W** (after Gate W). Notifications/scheduled reports →
**21N**. Approved attendance/shift source → **future HR phase**. Impact-preview endpoint (§9.3) →
20F UX follow-up. General compensation-status scheduler (§9.2) → 21N.

## Increment 5 — API source inventory (recorded before implementation, §6)

**Permissions (canonical, already in the matrix as `planned`, `owning_phase: Phase 20G`):**
- `compensation.liability.view` — scope **merchant**, `mfa_required: true`, `step_up_required: false`,
  `audit_severity: info`, `default_roles: [finance]`, `billing_read_only_behavior: allow_read`.
- `compensation.adjustment.create` — scope **merchant**, `mfa_required: true`, `step_up_required: true`,
  `audit_severity: high`, `default_roles: [finance]`, `billing_read_only_behavior: block`.

**Parity surface (all must agree; verified by tests):** `PermissionRegistry::PERMISSIONS` +
Finance `ASSIGNMENTS`; `docs/auth/permission-matrix.yaml` `implementation_status` + `audit_event`;
DB projection via `PermissionSeeder` (derives from the registry); generated `permissions.ts`
(`servana:permission-types`); `phase8-matrix.txt` (regenerated by `PermissionMatrixTest`).
`PermissionMatrixParityTest` requires `activeKeys()` (YAML) == registry `permissionKeys()`.
`PermissionMatrixPlanMetadataParityTest` derives an active key's `audit_event` from live routes ×
`AuditMutationCoverage::AUDITED`, so the route + audit coverage must land with the flip:
`compensation.adjustment.create` → `compensation.adjustment.created` (the typed event already exists,
`AuditSeverity::High`); `compensation.liability.view` (read) → `none`.

**Step-up:** no compensation-adjustment `StepUpAction` exists → add the smallest canonical value
`CompensationAdjustmentCreate = 'compensation_adjustment_create'` (do NOT reuse a billing/payout action).

**API conventions (template: Phase 20E `PlatformFeeLedgerController` + `PlatformFeeDisputeController`):**
reads use `TenantContext` + `BelongsToMerchant` scope + server-side branch restriction for
branch-scoped roles; `authorize()` via policy; `summary` groups by `currency` (no cross-currency sum);
`paginate(min(max(per_page,1),100))`; masked Resource. Mutations: `EnsurePermission:<key>`,
`RequireFreshMfa:<action>`, `EnsureIdempotentRequest`, `->defaults(RouteClassification::KEY,
RouteClass::FinancialMutation)`; MFA via `EnsurePrivilegedMfa` (group-level); tenant via
`ResolveTenantContext`.

**Ledger schema facts driving the read model** — commission_ledger: `entry_type ∈
(pending_preview, earned, reversal, adjustment)`, `status ∈ (pending, earned, included_in_payout,
paid, reversed, adjusted, cancelled)`; salary_ledger: `entry_type ∈ (accrual, adjustment, reversal)`,
`status ∈ (pending, included_in_payout, paid, reversed, adjusted)`; compensation_adjustments:
`adjustment_type ∈ (manual, paid_commission_reversal, paid_salary_reversal, correction)`, signed
`amount_minor`, source_commission/salary_ledger_id (system paid-reversals only). All BRANCH-owned,
integer minor units, append-only.

**Liability total semantics (server-authoritative, per currency; "earned-unpaid balance", Plan report
defs §80):**
- gross earned commission = Σ `amount_minor` WHERE `entry_type='earned'`;
- commission reversals = Σ WHERE `entry_type='reversal'` (≤0);
- net commission liability = Σ WHERE `entry_type IN ('earned','reversal','adjustment')` AND
  `status NOT IN ('paid','cancelled')` (a reversed original + its negative reversal net to 0);
- gross salary accruals = Σ WHERE `entry_type='accrual'`; salary reversals = Σ WHERE
  `entry_type='reversal'`; net salary liability = Σ WHERE `status <> 'paid'`;
- adjustments = Σ compensation_adjustments `amount_minor` (grouped by currency);
- combined net = net salary + net commission + adjustments (same currency only).

**Adjustment-create truth:** the schema's provenance CHECK forbids a Finance-created source-linked row
(`manual`/`correction` require NULL sources; `paid_*_reversal` are system-generated negatives). So the
API creates a STANDALONE `manual` adjustment (Plan-authorized; `RecordCompensationAdjustment::manual`).
A `source_ledger_ulid` input is NOT accepted. `branch_id` is server-derived from the staff profile's
primary branch (never client-supplied). Signed `amount_minor` (nonzero); fresh step-up is the control.

## Increment 5 — permissions activation + Finance liability/adjustment API (COMPLETE this session)

**Permission flip (atomic; parity green):** `compensation.liability.view` + `compensation.adjustment
.create` flipped `planned → active` (owning_phase → null) across the matrix (`audit_event`:
liability.view=`none`, adjustment.create=`compensation.adjustment.created`), the `PermissionRegistry`
(PERMISSIONS catalogue + Finance ASSIGNMENTS), the DB projection (seeder-derived), the regenerated
`permissions.ts`, and `phase8-matrix.txt`. **Counts:** active 110→112, planned 58→56, legacy-active
unchanged (**legacy retirement: none** — these are new-canonical keys, not successors). Finance is the
sole default role; no other role granted; Phase 20H keys (`merchant.compensation_summary.view`,
payout/earnings) stay PLANNED. Parity proven by `PermissionMatrixParityTest`,
`PermissionMatrixPlanMetadataParityTest` (audit_event route-derived), `PermissionDatabaseProjectionTest`,
`PermissionPlannedKeyIsolationTest` (56), `PermissionLegacyKeyReconciliationTest` (auth suite 196 green).

**Step-up:** new canonical `StepUpAction::CompensationAdjustmentCreate` (`compensation_adjustment_create`,
Phase 20G) — not a reused billing/payout action (§11.7).

**Read model:** `CompensationLiabilityReadModel` — per-currency summary + normalized masked entries over
salary_ledger + commission_ledger. Totals per §9.2 (earned-unpaid balance): net salary = Σ WHERE
status<>'paid'; net commission = Σ WHERE status NOT IN ('paid','cancelled'); adjustments = Σ; combined =
sum per currency. No cross-currency sum; integer minor units; public ULIDs only.

**API (route family `compensation/*`; merchant scope; group MFA):**
`GET compensation/liabilities/summary`, `GET compensation/liabilities`, `GET compensation/adjustments`,
`GET compensation/adjustments/{ulid}` — all `compensation.liability.view` (read). `POST
compensation/adjustments` — `compensation.adjustment.create` + `RequireFreshMfa:compensation_adjustment_
create` + `EnsureIdempotentRequest`, `RouteClass::FinancialMutation`, audit `compensation.adjustment
.created` (HIGH). No update/delete/status route (append-only).

**Adjustment truth (schema-driven):** the provenance CHECK forbids a Finance-created source-linked row,
so the API creates a STANDALONE `manual` adjustment (`RecordCompensationAdjustment::manual`); no
`source_ledger_ulid` accepted; `branch_id` server-derived from the staff's primary branch; signed
nonzero `amount_minor`; server-owned fields rejected (`prohibited`).

**Policies:** `CommissionLedgerEntryPolicy` / `SalaryLedgerEntryPolicy` (viewAny/view →
`compensation.liability.view`), `CompensationAdjustmentPolicy` (viewAny/view → liability.view; create →
adjustment.create). Registered in `AppServiceProvider`. Foreign merchant/branch/staff → 404 (BelongsToMerchant
scope + ULID route key on CompensationAdjustment).

**Contracts:** OpenAPI **211 paths / 253 operations** (4 new compensation routes); deterministic (2nd
generation byte-identical); `api.ts` + `permissions.ts` regenerated (`--check` green); `api:contract:check`
OK; `vue-tsc` clean.

**Tests (Increment 5):** `Phase20GCompensationApiTest` (13): summary per-currency totals + no
cross-currency combine + paid excluded; masked entries + type filter; non-Finance denial (HR/Front
Office); tenant isolation (foreign staff → 404); adjustment create success (fresh step-up + idempotency +
high audit); idempotent one-row replay; stale step-up → 403 `step_up_required` (no row/no audit);
missing Idempotency-Key → 422 `idempotency_key_required`; non-Finance create denial; zero-amount /
server-owned-field / foreign-staff rejection. Plus auth 196, route-security/idempotency-coverage/
audit-mutation/OpenAPI-contract 30, full compensation group **375**. Pint clean; Larastan L8 0 errors.

**Not built (Increment 6+ / other phases):** Finance frontend, HR selected-services UI, Merchant-Admin
compensation summary, Personnel earnings, payout/earnings/mark-paid, Wallet/provider runtime, scheduled
report delivery. Report *definitions* seeded in `docs/reporting/report-catalogue.md`; delivery is 21N.

## Increment 6 — Finance compensation-liability frontend (COMPLETE this session)

Implemented and proved the authorized Phase 20G Finance frontend against the **existing** Increment-5
generated contract. **No backend code, route, permission, or generated contract was changed** — the
frontend consumes the accepted deterministic contract only. `OpenApiContractTest` re-run confirms
`docs/api/openapi.json` stays **byte-current with the generator** (no regen was needed or performed).

### Screen ownership (no new top-level navigation)

One new Finance screen on the existing `FinanceLayout`, reusing the nav slot the roadmap had already
reserved (`roleNavigation.ts:128`, previously `availability: planned`, no route):

| Field | Value |
|---|---|
| Screen ID | `finance-compensation-liabilities` |
| Screen name | Compensation liabilities |
| Route name / path | `finance.liabilities` / `/finance/liabilities` |
| Layout | `FinanceLayout` (existing) |
| Navigation parent | Finance (`roleNavigation.ts` `finance` list) |
| Role / permission | `merchant_finance` / `compensation.liability.view` (+ `compensation.adjustment.create` for the mutation control) |
| Phase | 20G |

Source files updated first, snapshots regenerated by their own tests/generators (no generated file
hand-edited): `resources/spa/src/navigation/roleNavigation.ts` (`planned → live` + `routeName`) →
`docs/frontend/navigation/role-navigation.yaml` (regenerated by `roleNavigation.spec.ts`);
`docs/frontend/screens/inventory.json` (new `implemented` entry) → `docs/frontend/screens/inventory.yaml`
(regenerated by `screenInventory.spec.ts`) + `docs/frontend/screens/finance/finance-compensation-liabilities.md`
(regenerated by `scripts/generate-screen-specs.mjs`; only the one new spec added — generator is deterministic).

### Store / service

`resources/spa/src/stores/compensationLiabilityStore.ts` (Pinia) — typed from the **generated** `api.ts`
(`CompensationLiabilityEntryResource`, `CompensationAdjustmentResource`); the `/summary` per-currency row
shape is declared locally (`LiabilitySummaryRow`) as the endpoint returns a raw server projection, not a
Resource. Actions: `fetchSummary`, `fetchEntries(page)`, `fetchAdjustments(page)`, `fetchAdjustment(id)`,
`applyFilters` (resets to page 1), `resetFilters`, `createAdjustment`, `$reset`. Filters are sent only when
non-empty and only the contract-declared keys; a 403 read sets a `forbidden` flag (safe state, not blank).
**Idempotency lifecycle:** the `Idempotency-Key` is minted on first submit, **reused on a same-payload
retry, re-minted on a changed payload, retired on success** (payload hashed to decide). Local state is
written only from the server response, so a rejected create (stale step-up / period lock / validation)
never leaves a phantom row. No authoritative money is computed; `crypto.randomUUID()` for the key; the key
is never surfaced in the UI.

### Page / components

`resources/spa/src/pages/finance/CompensationLiabilities.vue` + `resources/spa/src/content/compensationLiability.ts`
(labels). Sections: per-currency **summary cards** (net salary / net commission / adjustments / combined
net + gross accrual/earned + reversals; never combined across currencies; server totals only); a
**filter bar** (liability type, entry type, status, currency, staff/branch reference, date from/to;
apply/clear; active-filter count); a paginated **liability-entry list** (signed amount with a non-colour
direction word, type/entry/status badges, staff/date/invoice, safe entry-detail modal); a paginated
**adjustment list** (type label, signed amount, reason, staff/date, detail modal); and a capability-gated
**Record-adjustment dialog**. The dialog uses a **direction selector + positive major-unit amount**
converted to signed `amount_minor` by a string-based `majorToMinor` parser (no floating point); a live
preview shows the signed effect and states a negative adjustment "is not a payment"; only the four
contract fields are sent (`staff_profile_ulid`, `amount_minor`, `currency`, `reason`) — no server-owned
field. Step-up: a `step_up_required` response renders a safe verify state (no secret stored) with a route
to the established `auth.mfa.challenge` flow; the form is preserved for retry. Period-lock / validation /
forbidden all map to safe copy (no SQLSTATE/constraint/class/internal-id leak). Focus is remembered/restored
around dialogs and a `role="status"` region announces async results.

### Authorization behaviour (frontend UX; API is the boundary)

Finance **with** `compensation.liability.view` accesses the surface; **without** it sees a no-access card.
`compensation.adjustment.create` gates the Record-adjustment control (hidden without it). Other roles have
no nav entry (Finance-only nav) and the backend re-authorizes every call regardless of rendering (proven
by `Phase20GCompensationApiTest` non-Finance denials + tenant isolation). No frontend widens branch access;
multi-branch context uses the live acting-context (`auth.branchIds` watch → `$reset` + reload). No
Merchant-Admin/Branch/Personnel/Front-Office/Audit/Super-Admin compensation mutation surface exists.

### Selected-services frontend gate — **State C (contract missing); STOPPED per §8, reported**

The §9.1 `commission_rule_services` **substrate** (table/model/factory/DB guards) exists and the earning
path consumes it, but the §9.1 **API/Form-Request/Resource extension was never wired**: `selected_service_ulids`
appears in **zero** requests/resources and **zero** times in `openapi.json` / `api.ts`; no controller writes
membership rows. Per §8 State C the correct action is to **stop, identify the missing contract + smallest
correction, and not invent client-only storage** — a browser-only selected-services list is prohibited and
was not built. The precise, smallest correction is recorded as a tracked follow-up (add `selected_service_ulids`
to `StoreCommissionRuleRequest`/`UpdateCommissionRuleDraftRequest`, resolve→persist `CommissionRuleService`
memberships in the create/draft actions while draft, expose `selected_service_ulids` on `CommissionRuleResource`,
regenerate the contract twice for determinism, then build the HR draft-form multi-select). This is a
deferred inherited-seam contract addition, not an Increment-6 client workaround. **Remaining risk:** until
that lands, an HR user can pick `applies_to = selected_services` with no way to specify services; the DB
non-draft-zero-membership guard fails such a submit closed (integrity preserved), but the UX is incomplete.

### Minor generated-contract note (not fixed — belongs to the State-C regen)

`CompensationAdjustmentResource.has_source` is typed `string` in the generated `api.ts` (the OpenAPI
generator mis-inferred a boolean expression). The Finance surface does **not** consume `has_source`, so no
frontend workaround was introduced; the correct fix (`(bool)` cast + regen) rides the State-C contract
regeneration rather than a standalone Increment-6 backend edit.

### Tests + gates (this session, all green)

- **ESLint** `eslint .` — 0 errors (138 pre-existing warnings = `origin/main` baseline; new files add none).
- **vue-tsc** `--noEmit` — clean.
- **Vitest** full suite **428 passed / 86 files** (+24 new: store spec 12, component spec 12); includes
  the regenerated nav + inventory snapshots. New: `compensationLiabilityStore.spec.ts` (summary per-currency
  no-combine; entries+pagination; adjustments+detail; filters send only declared keys + reset page; create
  positive/negative sends integer minor units + no prohibited fields + Idempotency-Key; key reuse-on-retry /
  re-mint-on-change; no phantom row on step-up/period-lock/validation reject; forbidden flag; friendly errors;
  reset). `CompensationLiabilities.spec.ts` (multi-currency summary; signed direction cue; filters; empty;
  forbidden; adjustment gating; zero-amount + currency validation before API; signed-direction preview
  ("not a payment"); step-up state + verify route; safe period-lock; successful create refreshes totals).
- **build** `vue-tsc && vite build` — PASS.
- **Playwright** affected `tests/e2e/phase-20g.spec.ts` — **18 passed**: multi-currency summary + entries;
  API filtering; positive create (single POST — no duplicate on submit); negative create; stale step-up
  blocks + safe verify state; locked period safe message; forbidden state; role gating (no-view no-access +
  no-create control hidden); no horizontal overflow at 360/768/1280; usable at 200% zoom; keyboard open +
  Escape; **axe serious/critical = 0 on the page and the adjustment dialog, light + dark**.
- **Backend contract reruns (serial, in-container, no `--parallel`):** `Phase20GCompensationApiTest` **13**;
  `PermissionMatrixParityTest` + `PermissionMatrixPlanMetadataParityTest` + `PermissionPlannedKeyIsolationTest`;
  `RouteSecurityContractTest`; `FinancialRouteIdempotencyCoverageTest`; `AuditMutationCoverageTest`;
  `AuditSeverityCoverageTest`; `OpenApiContractTest` (**openapi.json byte-current**); `NoDirectProviderIntegrationTest`
  — **55 passed / 0 failed** aggregate. Confirms the frontend increment left the backend contract, permission
  parity, route security, idempotency coverage, audit coverage, OpenAPI determinism and provider-isolation
  intact.

### Proof of exclusions (still absent — searched, not assumed)

No Merchant-Administrator compensation summary; no payout runs/items; no earnings statements/queries; no
mark-paid; no Wallet/provider runtime; no Phase 20H or Phase 20D-W surface; no scheduled report delivery.
The word "payout"/"settlement"/"disbursement"/"Wallet"/"paid out" never labels a liability in the UI.

**Full local closure, disposable-PG proof, scope-purity audit, single completion commit + push = Increment 7**
(not started). Lifecycle stays **in_progress**; nothing staged/committed/pushed; no PR.

## Increment 6A — Selected-Services Contract + HR UX closure (in progress this session)

Authorized by the product owner to **close the State-C gap before Phase 20G local completion** (the
`commission_rule_services` substrate exists and the earning path consumes it, but HR cannot configure a
truthful membership set). Same branch, no commit/push.

### Source inventory (§5) — current gap + conventions

| Surface | State |
|---|---|
| `commission_rule_services` table/model/factory/DB guards | present (Inc 2); `Phase20GSchemaTest` proves add-while-draft, freeze-insert/delete-after-draft, block-non-draft-zero-membership, reject-cross-branch-service |
| `CommissionEarningResolver` selected-services consumption | present (`applicabilityMatches` queries membership by `commission_rule_id`+`service_id`) |
| `StoreCommissionRuleRequest` / `UpdateCommissionRuleDraftRequest` `selected_service_ulids` | **missing** |
| `CommissionRuleController` service resolution + membership write | **missing** (has `resolveServiceCategory` precedent: scoped `where('ulid')`→404) |
| `CreateCommissionRuleDraft` / `UpdateCommissionRuleDraft` membership persistence | **missing** (both run inside `DB::transaction`; update locks + re-checks `isEditable()` = draft-only) |
| `CommissionRule` `services()`/`commissionRuleServices()` relationship | **missing** |
| `CommissionRuleResource` `selected_service_ulids` | **missing** |
| OpenAPI / generated `api.ts` `selected_service_ulids` | **missing** |
| HR Compensation selected-services multi-select | **missing** (existing `service_category` uses a raw-ULID text input, not a loaded control) |

`Service` is `BelongsToMerchant`+`BelongsToBranch`; `id`→ULID public key; `name`; `status` enum
(`active`/`archived`); `ServiceResource` masks to `{id: ulid, name, status, ...}`. Transaction ownership is
in the two draft actions (not the controller). The draft-only guard is the actions' `isEditable()` check
**and** the DB triggers. OpenAPI is generated by `php artisan servana:openapi`; TS by `npm run api:types`.

### Service-options source for the HR multi-select — **§5 blocker (design decision required)**

The HR multi-select (§15.1/§15.2) needs a branch-scoped **list** of the acting branch's selectable
services. Verified in-container: **HR does NOT hold `service.view` and it is NOT grantable to HR**
(`defaultGrantsFor('hr')` excludes it; `isGrantableFor('hr','service.view')` = false; matrix
`service.view` `default_roles: [branch_manager]`, `override_policy: revocable_only`). So `GET /services`
(authorizes `service.view` via `ServicePolicy::viewAny`) is **not usable by HR**. There is no other
branch-scoped service-list endpoint HR can read. (Note: the existing Phase-15A `hr.eligibility` page calls
`catalogue.fetchServices` → `GET /services`; that is a pre-existing latent gap, out of 20G scope, but it
confirms no HR-usable service-list endpoint exists.) Per §5 this is stopped-and-reported before building
the HR selection UX; the smallest corrections are enumerated and a decision is requested (below). The
**backend contract** (§6–§14) needs no such endpoint — the server resolves ULIDs itself — so it proceeds.

**Backend contract implementation + tests + OpenAPI regen recorded below as they land; HR UX follows the
service-options decision.**

### Selected-services API contract (COMPLETE, green)

- **Requests** (`StoreCommissionRuleRequest`; `UpdateCommissionRuleDraftRequest` extends it): `selected_service_ulids`
  = `array` of `string size:26 distinct`; `withValidator` coherence — required + ≥1 for `selected_services`,
  prohibited/empty otherwise. Server-owned fields still rejected.
- **Controller** (`CommissionRuleController`): `resolveSelectedServices()` resolves the ULIDs to `Service`
  models inside the acting merchant+branch (a ULID not resolving within the merchant, or a service from
  another branch → `CompensationScopeException::service()` = 404, no existence leak; an archived service →
  `CompensationValidationException::selectedServices()` = 422). Duplicates collapsed. Passed to the actions;
  `selectedServices` relationship eager-loaded on every rule response (no N+1).
- **Actions**: `CreateCommissionRuleDraft` inserts one `CommissionRuleService` per service inside its
  existing `DB::transaction` (only for `selected_services`). `UpdateCommissionRuleDraft` replaces the set
  atomically under the draft lock (delete-all + re-insert; a move away from `selected_services` clears the
  set). The DB triggers (proven by `Phase20GSchemaTest`) freeze memberships once the rule leaves draft;
  historical memberships never reach these paths. Rollback on any failure preserves the original set.
- **Model**: `CommissionRule::commissionRuleServices()` (HasMany) + `selectedServices()` (BelongsToMany via
  `commission_rule_services`, ordered by service name then ULID).
- **Resource** (`CommissionRuleResource`): `selected_service_ulids: string[]` (canonical) + `selected_services:
  [{ulid, name}]` (safe display for HR hydration — HR has no `service.view`). Always an array ([] for
  all_services/service_category); deterministic order; never an internal id/price/management field.
- **Generated-contract truth fix**: `CompensationAdjustmentResource.has_source` cast `(bool)` → generator now
  publishes `boolean` (was mis-inferred `string`); runtime JSON unchanged.
- **Existing-data preflight (re-run)**: `0` existing `commission_rules` with `applies_to='selected_services'`
  → no remediation. No non-draft zero-membership rule exists (DB guard also blocks it).

### Service-options endpoint (product-owner decision — Option 1)

HR cannot hold `service.view` (Branch-Manager-only, not grantable — verified in-container), so a **narrow,
read-only, compensation-scoped** option source was added rather than widening `service.view` or forcing
raw-ULID entry:

- **Route** `GET /api/v1/commission-rule-service-options` → name `commission-rule-service-options.index`,
  declared before `commission-rules/{commissionRule}`; middleware `EnsurePermission:compensation.plan.view`
  (the compensation route group's auth/tenant/branch/MFA apply).
- **Controller** `CommissionRuleServiceOptionController` (thin): authorizes `viewAny` on `CommissionRule`
  (→ `compensation.plan.view`, **never** `service.view`); returns `Service::query()->active()->whereIn(
  'branch_id', context branchIds)->orderBy(name)->orderBy(ulid)` — bounded to the acting merchant+branch by
  the `Service` tenancy scopes.
- **Resource** `CompensationSelectableServiceResource`: `{ulid, name}` ONLY — no id/price/cost/status/category/
  actor/audit/provider/Wallet field. No writes, no idempotency, no step-up, no mutation audit.
- **Pagination/search**: returns all active branch services (bounded per-branch catalogue; a single branch's
  service list is small), deterministically ordered — appropriate for a multi-select; no arbitrary sort/filter.

**Authorization proof** (`CommissionRuleServiceOptionsTest`, 7): HR with `compensation.plan.view` gets its
branch's active services `{ulid,name}` in name order; archived excluded; foreign branch + foreign merchant
never appear; Front Office denied; **a Branch Manager holding `service.view` but not `compensation.plan.view`
is denied** (proves the gate is the compensation permission, not `service.view`); unauthenticated → 401.

### HR selected-services multi-select

`compensationStore`: `SelectableService` type + `serviceOptions`/`serviceOptionsLoading`/`serviceOptionsError`
+ `fetchServiceOptions()` (calls the narrow endpoint — **never** `/services`; cleared by `$reset` on branch
change); `CommissionRulePayload.selected_service_ulids`. `Compensation.vue`: when `applies_to=selected_services`
the draft form shows a branch-scoped checkbox multi-select (options from the endpoint; loading/empty/error
states; ≥1 required; removable selected-summary chips with accessible names; server selection hydrates on
edit; a `watch` clears the stale set and hides the control when `applies_to` changes away; the payload sends
`selected_service_ulids` only for `selected_services`, `[]` otherwise). Non-draft rules show a read-only
"Selected services: …" line. No money computed in JS.

### Service Eligibility inconsistency (observed; NOT changed in 6A)

Observed pre-existing mismatch: the live Phase-15A HR **Service Eligibility** page (`ServiceEligibility.vue`)
calls `catalogue.fetchServices` → `GET /services`, while the canonical matrix does not grant `service.view`
to HR. Increment 6A does **not** widen `service.view`, does **not** alter the matrix, and does **not** refactor
that unrelated screen; the selected-services form uses the dedicated compensation-scoped endpoint instead. The
Service Eligibility authorization mismatch requires a separately scoped verification/remediation (no
authenticated end-to-end/API test was run here to prove the actual runtime denial, so it is recorded as an
observation, not a proven break).

### Contracts + tests (6A, all green)

- **OpenAPI**: **212 paths / 254 operations** (+1 route vs Increment-5's 211/253); deterministic (2nd generation
  byte-identical for both `openapi.json` and `api.ts`); `selected_service_ulids: string[]` in the two request
  schemas + `CommissionRuleResource` (+ `selected_services`); `commission-rule-service-options` present;
  `has_source: boolean`; `permissions.ts` unchanged (no new permission); `api:contract:check` OK 212/254;
  `permission-types --check` up to date; `vue-tsc` clean.
- **Backend (serial):** `CommissionRuleSelectedServicesTest` 17; `CommissionRuleServiceOptionsTest` 7;
  `CommissionRuleApiTest` 36; `CommissionEarningTest` (selected-services member-earns / non-member-no-earn
  retained); `Phase20GSchemaTest` 16; `Phase20GEnumParityTest`; `Phase20GTenantIsolationTest`;
  `Phase20GCompensationApiTest` 13; `CompensationPlanApiTest`; `OpenApiContractTest` (byte-current);
  `RouteSecurityContractTest`; `FinancialRouteIdempotencyCoverageTest`; `AuditMutationCoverageTest`;
  `AuditSeverityCoverageTest`; `PermissionMatrixParityTest`; `PermissionPlannedKeyIsolationTest` — **198
  passed / 0 failed** in one serial run; plus `MigrationManifestTest` + `TenantColumnCoverageTest` +
  `ModelTenancyTraitCoverageTest` **15 passed** (no migration added in 6A). Pint 1398 files clean; Larastan L8
  0 errors.
- **Frontend:** ESLint 0 errors (138 pre-existing warnings = baseline); `vue-tsc` clean; **Vitest 435 / 87
  files** (+7 `Compensation.selectedServices.spec.ts`; store + HR fixtures updated for the two new required
  fields); build PASS. **Full Playwright 397 passed** (368 baseline + 18 Finance liabilities + 11 HR
  selected-services; axe serious/critical = 0 on the new HR form + Finance surfaces, light + dark; 360/768/1280;
  200% zoom; keyboard; hydration; clear-on-change; and proof `/services` is never called from the HR flow).

**Increment 6A COMPLETE + green.** No new permission; `service.view` not granted to HR; no new screen; no
migration; no payout/earnings/mark-paid/Wallet/provider/20H/20D-W surface.

## Increment 7 — full local acceptance, disposable-PG proof, closure (this session)

Ran the full local acceptance battery; every required gate is green. One reconciliation fix was required
(below). No product behaviour changed in Increment 7 beyond that one stale-guard test correction.

### One failure found + fixed (test defect — stale cross-phase guard)

- **Observed:** the full backend **serial** run reported `1 failed / 7 skipped / 1577 passed`; the failure
  was `tests/Feature/ServiceSession/ServiceSessionCouplingTest.php:111` — `Failed asserting that true is
  false` on `Schema::hasTable('commission_ledger')->toBeFalse()`.
- **Root cause:** a committed Phase-16C guard asserted the `commission_ledger` table **did not exist yet
  ("Phase 20G")**; Phase 20G Increment 2 legitimately created it, so the table-absence assertion is stale.
  This is the same class of stale cross-phase guard Increment 4 already reconciled for `CompensationPlanApiTest`
  / `CompensationPlanActionTest` (table-absence → zero-rows); this one is a service-session test and was not in
  the compensation group, so it surfaced only in the full run.
- **Fix (minimal, §4-authorized cross-phase reconciliation):** assert the invariant at the ROW level —
  `DB::table('commission_ledger')->count()->toBe(0)` (session completion earns no commission; commission is
  earned only at Finance validation, Plan §61) — and drop the now-unused `Schema` import. No product code
  touched; the test's intent is unchanged.
- **Proof:** the file re-ran **7 passed** in isolation; Pint clean; the full serial + parallel reruns are
  both **1578 passed / 0 failed / 7 skipped**.

### Full local gate battery (all green)

| Gate | Result |
|---|---|
| `composer validate --strict` | valid |
| Pint `--test` | clean (1398 files) |
| Larastan (level 8) | 0 errors (1077 files) |
| Full backend **serial** (`php artisan test`) | **1578 passed / 0 failed / 7 skipped** (9088 assertions; 870s) |
| Full backend **parallel** (`php artisan test --parallel`) | **1578 passed / 0 failed / 7 skipped** (4 processes, isolated worker DBs; 611s) — identical to serial |
| ESLint | 0 errors (138 pre-existing warnings = `origin/main` baseline) |
| `vue-tsc --noEmit` | clean |
| Vitest | **435 passed / 87 files** |
| Production build (`vue-tsc && vite build`) | PASS |
| Screen-inventory / role-navigation snapshot parity | green (generated YAML current) |
| Full Playwright (`npx playwright test`) | **397 passed / 0 failed** (axe serious/critical = 0 across Finance + HR states, light + dark; 360/768/1280; 200% zoom; keyboard) |
| OpenAPI | **212 paths / 254 operations**; 2nd generation byte-identical |
| `api.ts` / `permissions.ts` | regenerated; 2nd generation byte-identical |
| `servana:permission-types --check` | up to date |
| `api:contract:check` | OK 212/254 |
| `composer audit --locked` | no advisories |
| `npm audit --audit-level=high` | exit 0 (2 moderate `@redocly/openapi-core`→`js-yaml`, below the high gate; = baseline) |
| `gitleaks detect --no-git --redact` | no leaks (~18.25 MB scanned) |
| Docker `compose build app` (dev) | `servana-app:dev` built |
| Docker `-f docker-compose.prod.yml build app` | `servana-app:prod` built |
| Docker `-f docker-compose.prod.yml build nginx` | `servana-nginx:prod` built |

### Disposable PostgreSQL 16 fresh-build proof

- **Database:** `servana_p20g_i7_proof_1784393344` (disposable; created empty, plain `migrate --force` from
  zero — the dev `servana` DB was never `migrate:fresh`ed).
- **PostgreSQL:** 16.14. **Migrations run:** 104 (from zero). **Tables:** 87.
- **Phase 20G tables present:** `commission_rule_services`, `commission_ledger`, `salary_ledger`,
  `compensation_adjustments` (all `to_regclass` non-null); `personnel_compensation_plans.suspension_salary_policy`
  column present.
- **Append-only + immutability triggers present:** `commission_ledger_no_delete` + `commission_ledger_no_monetary_update`;
  `salary_ledger_no_delete` + `salary_ledger_no_monetary_update`; `compensation_adjustments_no_delete` +
  `compensation_adjustments_no_update`; selected-services membership guards `commission_rule_services_guard_ins/upd/del`.
- **Idempotency uniques present:** `commission_ledger_earned_idempotency_unique`, `commission_ledger_reversal_idempotency_unique`,
  `salary_ledger_accrual_idempotency_unique`, `salary_ledger_reversal_idempotency_unique`,
  `commission_rule_services_rule_service_unique` (+ ulid/`(id, merchant_id)` uniques).
- **Composite-consistency FKs present** on `commission_rule_services`: `..._branch_merchant_foreign`,
  `..._rule_merchant_foreign`, `..._service_merchant_foreign` (+ the individual FKs).
- **Forbidden tables ABSENT:** `personnel_payout_runs`, `personnel_payout_items`, `earnings_statements`,
  `payout_runs`, `payout_items` (all `to_regclass` null).
- **Cleanup:** database dropped and verified gone; dev `servana` untouched (still 87 tables).

### Scope-purity audit (immediately pre-stage)

Every changed path classified into an authorized Phase 20G category (compensation domain/actions/services/
value-objects; enums/models/factories; migrations; TenantOwnership + policies + requests + resources +
controllers + routes; read model; Finance liability frontend; HR selected-services closure + option endpoint;
navigation/inventory/specs; generated OpenAPI/`api.ts`/`permissions.ts`; permission matrix/registry/phase8-matrix;
report catalogue; data dictionary / migration manifest / state machines; 20G tests; the narrow Phase-18B/20F
and `ServiceSessionCouplingTest` cross-phase reconciliations). **No forbidden category** (Wallet/provider
runtime, STK/PayBill/Till/C2B/Daraja/settlement, payout runs/items, mark-paid, earnings queries/statements,
Merchant-Admin compensation summary, 20H, 20D-W, `service.view` widening, unrelated Service-Eligibility
refactor, temp artifacts, coverage/playwright-report/test-results, node_modules/vendor, secrets/`.env`). The
only new "payout"/"earnings" symbols in `app/` are pre-existing PLANNED enum cases (`StepUpAction::PayoutMarkPaid`
tagged Phase 20H; `FilePurpose::EarningsStatement`) — not touched by 20G and not runtime.

**Increment 7 COMPLETE.** Lifecycle → **local_complete pending PR CI/review/merge** at the single Phase 20G
completion commit + push. No PR created; branch retained; Phase 20H and Phase 20D-W not started.
