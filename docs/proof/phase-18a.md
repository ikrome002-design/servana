# Phase 18A — Merchant-Client Payment Recording — Proof

**Branch:** `phase-18a-payment-recording` (merged) · **Base commit:** `6557469`
(verified Phase 17 squash merge, PR #29). **Status:** `verified_complete` — PR
**#30** MERGED into `main`, squash merge commit
`4a489d04156aec8348eda9a968f830da31668c87` (`4a489d0`, 2026-07-02). Commit lineage:
implementation `baa3678` → local-completion documentation `24ae7e8` → CI-correction
`aef8d51` → governance / final PR head `0e36641`. CI lineage: initial run
`28574550657` FAILED (Backend Pint + E2E body-copy assertion, corrected below);
corrected-head run `28575564965` SUCCESS (Docker failed once on the same head with no
product-code change, passed on rerun); final governance-head run `28576226830` — five
required checks (Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS.
`reviewDecision` intentionally blank under the documented PR-specific solo-maintainer
governance exception — **not** independent reviewer approval. **REM-PAY-001 remains
truthfully open** because it spans Phase 18A recording and the Phase 18B
validation/receipts/refunds/cash-up/period-locks lifecycle; Phase 18A is recorded as
verified evidence and the item closes only when Phase 18B merges with green CI. CI is
authoritative for the Linux browser/Docker/gitleaks gates. Tests run against
PostgreSQL 16 (never SQLite).

Money is integer minor units (`Money` value object) — never float. Full/normalized
payment references and raw client contact are never returned by a Resource, audited,
or logged. Frontend visibility is UX only — the API (policies + `EnsureBranchScope`
+ `EnsurePermission` + the payment-recording-group state machine + billing/period-lock/
idempotency gates) is the security boundary.

Controlling sources: Plan §13.8, §13.15 (canonical DDL), **§41** (the controlling
Merchant-Client Payments workflow), §19.3 (permission matrix), §25 (group machine),
§24.4 (idempotency), §46 (period locks — reused), §80 (roadmap); Scope §4.5 + PART B.

---

## Git integrity & environment (verified at start)

- `git fetch --prune`; branch `main` at `6557469`, `origin/main` at `6557469`,
  `0 0` divergence, working tree clean. `git fsck --full` exit 0 (only dangling
  blobs — non-fatal after prior recovery). Damaged repo
  `…/Servana-damaged-git-2026-06-30` exists but is **not** used (working repo is
  `…/Servana`).
- New branch `phase-18a-payment-recording` created off `6557469`; merge-base `6557469`.
- Runtime: Docker up (server 29.5.3; postgres recovered after a slow cold-start
  fsync then healthy); PHP 8.3.31; Laravel 12.62.0; PostgreSQL 16.14; Redis 7;
  migrations through Phase 17 applied (`php artisan migrate:status`).

## Phase 17 lifecycle reconciliation (done first)

Verified via GitHub: PR **#29** `Phase 17: Implement invoicing` MERGED, mergeCommit
`6557469`, headRefOid `3c4e309`, baseRefName `main`, `reviewDecision` blank
(solo-maintainer governance exception — not independent approval). Implementation
commit `c0fdd83`; initial CI run `28516753439` (head `c0fdd83`) five required checks
(Backend, Frontend, Docker, Security, E2E — Playwright) all SUCCESS; governance /
final PR head `3c4e309` with final CI run `28517236474` (head `3c4e309`) — same five
required checks all SUCCESS. Reconciled `docs/PROGRESS.md`, `docs/CHANGELOG.md`,
`docs/proof/phase-17.md`, `docs/traceability/servana-requirements.csv`
(`SRV-INVOICE-001` → `verified_complete`, dictionary path updated to the consolidated
`invoicing-and-payments.md`) and `docs/remediation/register.yaml` `last_updated`.
All Phase 17 technical decisions/deferrals preserved. **No** new remediation item was
created merely because CI passed. `REM-PERM-001` stays open (Phase 19).

---

## Specification-first gate (mandated before any migration)

**Authority consolidation.** The Phase 17 `docs/architecture/data-dictionary/
invoicing.md` was renamed (`git mv`) to the Plan §13.8 canonical path
`docs/architecture/data-dictionary/invoicing-and-payments.md` so there is exactly one
authority; the three `manifest.yaml` `data_dictionary:` references and the
PROGRESS/proof-17/traceability links were updated. The four Phase 18A tables are
appended as the "Merchant-Client Payments (Phase 18A)" section.

**Documents produced.** (1) data dictionary section (four tables, every column/type/
nullability/default, statuses, method rules, allocation semantics, reference
normalization/encryption/masking, duplicate workflow, unique/partial-unique, idempotency
linkage, period-lock/billing, maker/checker, concurrency/locking, audit, retention,
relationships, factories, tests, 18B handoff); (2)
`docs/architecture/state-machines/payment-recording-group.md`; (3) this proof file.

### Gate A — recordable invoice source → RESOLVED
Record only against `issued`/`partially_paid` (Phase 17 `InvoiceStatus::payableStatuses()`);
authoritative balance = `total_minor − validated_paid_minor` (`Invoice::balanceMinor()`);
other states → `422 invoice_not_recordable`; reuse `FinancialPeriodGuard` + billing gate.

### Gate B — split_payment representation → RESOLVED
Group = the split (no `method` column); components carry concrete methods; `split_payment`
kept in the component CHECK for fidelity but never written as a component method (no
synthetic component). `total_amount_minor = Σ(components)`; single currency enforced.

### Gate C — durable duplicate + uniqueness → RESOLVED
Partial unique index `payment_reference_checks (merchant_id, method, reference_normalized)
WHERE result='unique' AND reference_normalized IS NOT NULL`; `duplicate_suspected` +
`override_approved` rows fall outside the predicate so every attempt persists; race-safe;
original reference never edited; `payment_records` keeps a non-unique reference index.

### Gate D — Finance notification seam → RESOLVED
Masked Laravel mail `Notification` to eligible Finance users (permission-resolved,
tenant/branch scoped, idempotent per `(group, event)`); no full/normalized reference or
unmasked contact; **no Phase 21N `notifications` table**.

### Gate E — period-lock / billing → RESOLVED
Reuse Phase 17 `FinancialPeriodGuard` + `PeriodLockRepository` (`UnlockedPeriodLockRepository`
binding) + billing-mutation gate; locked → `423`; **no `financial_period_locks` table**.

### Gate F — cash / day-close evidence → RESOLVED
`cash` optional reference, no duplicate check; no cash-up table; active recording groups
block branch archival/day-close via `BranchClosureGuard` so pending recordings are not
stranded.

---

## Implementation evidence

### Schema (4 forward-only migrations on PostgreSQL 16)

`2026_07_01_000001_create_payment_recording_groups_table` · `..._000002_create_payment_records_table`
· `..._000003_create_payment_allocations_table` · `..._000004_create_payment_reference_checks_table`.
`invoice_number_sequences` is reused, not recreated. `php artisan migrate` applies all
four cleanly; `\d payment_reference_checks` confirms the Gate C partial unique index
`payment_reference_checks_unique_reservation … WHERE result='unique' AND
reference_normalized IS NOT NULL` and every CHECK/composite-FK. Registered in
`TenantOwnership` (BRANCH_OWNED + COMPOSITE_CONSISTENCY + MODELS) and the migration
manifest. Coverage: `TenantColumnCoverageTest` / `ModelTenancyTraitCoverageTest` /
`MigrationManifestTest` — **17 passed**.

### Domain

Enums `PaymentMethod` / `PaymentRecordingGroupStatus` / `PaymentRecordStatus` /
`PaymentReferenceCheckResult`; models + factories for all four tables; services
`PaymentReferenceNormalizer`, `PaymentMethodReferenceValidator`,
`PaymentGroupTotalsValidator`, `PaymentPendingBalanceCalculator`,
`PaymentReferenceDuplicateChecker` (savepoint-guarded reservation), 
`PaymentRecordingGroupStateMachine`, `PaymentMakerCheckerGuard`,
`PaymentRecordingComposer`, `NotifyFinanceOfRecordedPayment`; actions
`RecordCustomerPaymentGroup`, `RecordCustomerPaymentException`,
`ApproveDuplicatePaymentReference`. The composer runs the full atomic sequence under the
invoice `FOR UPDATE` lock; a suspected duplicate is a committed hold **returned** (not
thrown) so idempotent replay caches the 409.

### HTTP

`PaymentRecordingGroupPolicy` (record / recordException / viewAny / view / override);
Form Requests `RecordPaymentGroupRequest` + `ApproveDuplicateReferenceRequest` +
`PaymentGroupIndexRequest`; thin `PaymentRecordingGroupController` +
`PaymentReferenceCheckController`; masked `PaymentRecordingGroupResource` /
`PaymentRecordResource` / `PaymentReferenceCheckResource`. Routes (5):
`POST /invoices/{invoice}/payment-recording-groups` (+`/exception`) and
`POST /payment-reference-checks/{paymentReferenceCheck}/override` are `financial_mutation`
(R4 idempotency); the override adds `RequireFreshMfa:payment_duplicate_override`;
`GET /payment-recording-groups` + `/{group}` are branch-scoped reads.

### Permissions

Reconciled the legacy placeholder `payments.record/validate/reject/edit_reference/
override_duplicate` to canonical `customer_payment.record` (Front Office),
`customer_payment.view` + `.duplicate_override` + `.record_exception` (Finance).
Phase-18B keys (validate/reject/reference_correct) are NOT introduced. Updated
`PermissionRegistry`, 7 affected auth tests, and regenerated `phase8-matrix.txt`.
`REM-PERM-001` stays open (Phase 19).

### Test results (targeted, PostgreSQL 16)

| Suite | Result |
|---|---|
| `PaymentRecordingSchemaTest` | 14 passed |
| `PaymentRecordingApiTest` | 29 passed |
| `PaymentDuplicateReferenceTest` | 10 passed |
| `PaymentAuditTest` | 5 passed |
| `PaymentNotificationTest` | 4 passed |
| **Payment group total** | **62 passed** |
| Auth suite (reconciliation) | 142 passed |
| Route/idempotency/audit/dictionary/binding coverage | 22 passed |
| OpenAPI contract + TS parity | 14 passed |
| Full parallel suite | 952 passed / 7 skip / 0 fail (after OpenAPI+TS regen) |

Covered: single + split/multi-method recording; group total = Σ components; single-currency;
every method's reference rule; encrypted + masked references (never in API/audit/log);
durable duplicate detection + masked 409 + Finance override (permission + step-up + reason +
maker≠checker) with original preserved and no SQLSTATE/constraint leak; partial recording;
overpayment rejected; active-pending capacity under the invoice lock; allocation sums; invoice
`validated_paid`/status unchanged; components end `pending_validation`; group-level idempotency
(missing key 422, replay caches, reused-key-different-payload 409); period-lock 423;
billing/tenant/branch isolation; typed audit events with safe context + no success on rollback;
masked Finance mail-notification scoped to merchant/branch with none on rollback.

### Static + contract gates

Pint clean; Larastan level 8 **No errors** (544 files); OpenAPI regenerated (110 routes,
`composer api:openapi`) + generated TypeScript refreshed (`npm run api:types`);
`package-lock.json` platform churn reverted.

## Defect log (Bug Fix Protocol)

_For every defect: Observed problem · Evidence · Affected files · Root cause · Why this is
the root cause · Correct fix · Files changed · Tests added/updated · Test command · Test
result · Proof of resolution · Remaining risk._

## Quality gates

_Recorded with actual results only after each gate executes successfully; no gate is
claimed that did not run._

## Explicit deferrals (owning phase)

Group validation/rejection · `payment_validation_events` · validated-paid invoice update ·
invoice payment-state transitions · receipts + `receipt_number_sequences` · receipt reissue ·
refunds · finance disputes · cash-up approval · `financial_period_locks` persistence/UI ·
finance exports → **18B/23**. Preferred-personnel fee rules → 20A. Subscription billing →
20A–20B. M-Pesa → 20D. Percentage-fee ledger → 20E. Compensation → 20F. Earned commission →
20G. Payouts → 20H. Audit dashboard + REM-PERM-001 closure → 19. Notifications/reports →
21N. Personnel SMS → 21S. Search → 22. Release audit → 23. Performance → 24. Deployment →
25. Queue-linked in-progress session abort → unresolved scheduling correction.

## Solo-Maintainer Review Exception - PR #30

An independent reviewer was unavailable because the repository currently has
one eligible maintainer. A PR-specific governance exception was recorded
instead of fabricating approval.

Evidence:

- PR: #30 — MERGED, squash merge commit 4a489d04156aec8348eda9a968f830da31668c87
- implementation commit: baa367874304d72ba4c3fffb4ee021ece800504d
- local-completion documentation commit: 24ae7e8233038fa9341169ba3e21e363fe8c3b46
- CI-correction commit: aef8d5136f3dce0385cabd64e8d3edabe7ebf5ec
- governance / final PR head: 0e36641b7dd66d4a721d62db7b93823bb35ab023
- initial CI run (FAILED): 28574550657
    - Backend failure: Pint found one style issue in
      app/Http/Resources/PaymentRecordingGroupResource.php. Correction: Pint
      formatting only; no behavior or assertion was weakened.
    - E2E failure: the payment test asserted the page body must not contain the
      word "validate", but the correct pending-validation explanatory copy
      truthfully says Finance must validate before a receipt exists. Correction:
      retain the explanatory copy and assert that no Validate button/action is
      available to Front Office. The validation explanation was not removed and
      the role boundary was not weakened.
- corrected-head CI run (SUCCESS): 28575564965 (Docker failed once on the same
  head and passed on rerun without a product-code change)
- final governance-head CI run (SUCCESS): 28576226830
- CI/Backend: SUCCESS
- CI/Frontend: SUCCESS
- CI/Docker: SUCCESS
- CI/Security: SUCCESS
- CI/E2E - Playwright: SUCCESS
- GitHub reviewDecision: intentionally blank
- governance record:
  docs/governance/solo-maintainer-review-exception-pr-30.md

This exception applies only to PR #30 and is not independent reviewer approval.
It must not be described as independent review.
