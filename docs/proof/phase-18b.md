# Phase 18B — Validation, Receipts, Refunds, Disputes, Cash-Up, Period Locks — Proof

**Branch:** `phase-18b-financial-validation-controls` · **Base commit:** `4a489d0`
(`4a489d04156aec8348eda9a968f830da31668c87`, verified PR #30 squash merge). **Status:**
`verified_complete` — PR **#31** `Phase 18B: Implement validation receipts and finance
controls` MERGED into `main`, merge commit `64bd0a117dcdc819a8baf4b9bec3c3eb09635edc`
(implementation `ed07c8b`, CI-correction `a0d4dede7ce62e5dbcb7a27467b15ba592ccf6d3`,
governance `a8f988b68872eb3e352bc7f70dbb362bfb320cf3`). CI: initial run `28694148176`
FAILED, corrected-head run `28695121157` SUCCESS, final governance-head run
`28695314469` SUCCESS. `reviewDecision` blank under the documented PR-specific
solo-maintainer exception (`docs/governance/solo-maintainer-review-exception-pr-31.md`)
— **not** independent reviewer approval. `REM-PAY-001` closed `verified_complete` on
this merge. This file records controlling decisions, the specification-gate resolutions
(A–J), per-slice evidence, the full browser-verification history, and final gate
results. Tests run against PostgreSQL 16 (never SQLite).

Money is integer minor units (`Money`) — never float. Full/normalized payment
references, external refund references, raw client contact, private file paths and
signed URLs are never returned by a Resource, audited, or logged. Frontend visibility
is UX only; the API (policies + branch scope + permission + state machines +
period-lock/idempotency/step-up gates) is the security boundary.

Controlling sources: Plan §2.1, §5.3, §5.4a, §8, §9, §10/§19, §19.3, §13.8, §13.14,
§13.15, §13.16, §14–§15, §23–§24, §25, §41–§46, §65, §67, §70, §75–§76, §80, §§81–82,
§85; Scope §4.5, PART B; ADR-0007.

---

## Git integrity & environment (verified at start)

- Branch safety block ran clean: `git fetch --prune` ok; `git fsck --full` exit 0
  (only dangling blobs — non-fatal after prior recovery); PR #30 verified MERGED,
  title `Phase 18A: Implement payment recording`, mergeCommit
  `4a489d04156aec8348eda9a968f830da31668c87`, reviewDecision blank, required checks
  Backend/Frontend/Docker/Security/E2E all SUCCESS; `main` and `origin/main` at
  `4a489d0`, `0 0` divergence, working tree clean; new branch
  `phase-18b-financial-validation-controls` created off `4a489d0`, merge-base
  `4a489d0`. Damaged repo `…/Servana-damaged-git-2026-06-30` not used.
- Runtime: Docker stack healthy (app, nginx, postgres 16, redis 7, meilisearch,
  minio, mailpit, worker, scheduler, file-worker); PHP 8.3; Laravel 12.62.0;
  PostgreSQL 16; migrations through Phase 18A applied.

## Phase 18A lifecycle reconciliation (done first)

PR **#30** `Phase 18A: Implement payment recording` verified MERGED, squash merge
commit `4a489d04156aec8348eda9a968f830da31668c87`. Commit lineage: implementation
`baa3678` → local-completion documentation `24ae7e8` → CI-correction `aef8d51` →
governance / final PR head `0e36641`. CI: initial run `28574550657` FAILED (Backend
Pint style in `app/Http/Resources/PaymentRecordingGroupResource.php` — formatting
only, no behavior/assertion weakened; E2E payment test asserted the page body must not
contain "validate" while the correct pending-validation copy truthfully says Finance
must validate before a receipt exists — corrected to keep the copy and assert no
Validate action is available to Front Office, preserving the role boundary);
corrected-head run `28575564965` SUCCESS (Docker failed once on the same head with no
product-code change, passed on rerun); final governance-head run `28576226830` — five
required checks all SUCCESS. `reviewDecision` blank under the documented PR-specific
solo-maintainer governance exception — **not** independent reviewer approval (and not
described as independent review).

Reconciled from stale `local_complete`/pending wording to `verified_complete`:
`docs/PROGRESS.md`, `docs/CHANGELOG.md`, `docs/proof/phase-18a.md`,
`docs/remediation/register.yaml`, `docs/traceability/servana-requirements.csv`.
`REM-PAY-001` kept **truthfully open** (`in_progress`) because it spans Phase 18A
recording and Phase 18B; it closes only when Phase 18B merges with green CI. No new
remediation item was created merely because CI passed. `REM-PERM-001` stays open
(Phase 19).

---

## Specification-first gate resolutions (Gates A–J)

Full detail in `docs/architecture/data-dictionary/invoicing-and-payments.md`
(Financial Validation Controls section) and `docs/architecture/adr/0007-maker-checker-period-locks.md`.

- **A — validation-event schema:** group-level; `payment_validation_events` parented
  by `payment_recording_group_id`; components traceable via
  `payment_records.payment_recording_group_id`; one event per decision.
- **B — whole-group decision:** `validated`/`rejected`/`correction_required` only; no
  partial group validation; all components move to the group decision atomically.
- **C — commission handoff:** no outbox existed → smallest durable seam
  `commission_handoff_events` (per-component `validated_allocation`), no invented
  rate/earned/payable; not a ledger; validation cannot commit `paid` with a missing
  seam row.
- **D — refund allocation:** `refunds.payment_record_id` component boundary;
  multi-component refund = atomic multi-row workflow sharing `refund_group_ulid`; no
  unallocated group-level amount.
- **E — non-destructive refund accounting:** preserve originals; reduce
  `validated_paid_minor`; deterministic invoice state; per-component proportional
  reversal seam (largest-remainder); never overwrite future commission.
- **F — period reopen governance:** Finance locks + executes reopen; Merchant Admin
  approves exceptional reopen only where `exception_required`; request ⟂ approve;
  reason + fresh step-up + audit; minimal columns on `financial_period_locks`.
- **G — existing `branch_cash_ups`:** not recreated, shipped migration not edited;
  forward-only expand/backfill/constrain to canonical schema.
- **H — cash-up expected total:** server-derived per method from validated components
  on the Africa/Nairobi business date minus finalized refunds; client counted only.
- **I — export launch types:** six current types requestable; `compensation`/
  `payouts`/`billing` schema-enumerated but rejected (`422 unsupported_export_type`).
- **J — receipt generation/PDF:** automatic on validation (no manual issue); durable
  row in txn + outbox `GenerateReceiptPdf` via Phase 10F `receipt_pdf`; never issued
  for download until `ready`; reissue additive, original immutable, new number.

State machines: `payment-recording-group.md` (extended), `refund.md`,
`finance-dispute.md`, `cash-up.md`, `financial-period-lock.md`, `finance-export.md`.
ADR: `0007-maker-checker-period-locks.md`.

---

## Slice evidence (recorded as each slice is verified)

> Each slice: targeted tests run on PostgreSQL 16; failures handled via the Bug Fix
> Protocol below; result recorded here before proceeding. No partial commit/push.

### Slice 1 — Specification-first layer
Status: **complete.** ADR-0007 authored; data-dictionary Phase 18B section (gates A–J
+ 10 tables incl. the `commission_handoff_events` seam) authored; group state-machine
doc extended; refund/finance-dispute/cash-up/financial-period-lock/finance-export
state-machine docs authored; this proof baseline created; Phase 18A reconciled.

### Slice 2 — Schema & structural substrate
Status: **complete.** 10 forward-only migrations
(`2026_07_02_000001…000010`): `payment_validation_events`,
`receipt_number_sequences`, `receipts`, `refunds`, `finance_disputes`,
`branch_cash_ups` evolution (Gate G — additive expand/backfill/constrain, shipped
migration untouched), `cash_up_lines`, `financial_period_locks` (btree_gist
no-overlap EXCLUDE), `finance_exports`, `commission_handoff_events` (20G seam). All
apply cleanly on PostgreSQL 16 and are **reversible** (rollback batch → re-migrate,
and a full from-scratch re-migrate, both clean). 10 models + 8 enums + 9 factories;
`BranchCashUp` evolved additively; `CashUpStatus` extended (+correction_requested,
+locked) with `allowedTransitions()`. Registered in `TenantOwnership`
(BRANCH_OWNED×6, TENANT_OWNED×3, MODELS, COMPOSITE_CONSISTENCY×6) and the migration
manifest (10 entries). **Tests:** `TenantColumnCoverageTest` (5),
`ModelTenancyTraitCoverageTest` (4), `MigrationManifestTest`,
`DataDictionaryCoverageTest` all pass; Larastan level 8 **No errors** on
Payments/Receipts/Refunds/FinanceOps/Compensation/Branches/Tenancy. Fix: added a
`merchant_id`-leading index to each branch-owned table (coverage test requires it).

### Slice 3 — Group validation + receipt (atomic) — **COMPLETE**
State machines extended: `PaymentRecordingGroupStatus`
(`correction_required → pending_validation`), `PaymentRecordStatus`
(`allowedTransitions()` coherent with the group). Built:
`ReceiptNumberAllocator` (per-merchant `SELECT … FOR UPDATE`, gap-free, never
`MAX()+1`), `MinimalPdf` (dependency-free PDF writer — **no** third-party renderer
added, which would be a pinned-stack change), `GeneratedFileWriter` (creates an
available private `UploadedFile` for generated bytes), `ReceiptDocumentRenderer`,
`ReceiptIssuer`, `GenerateReceiptPdf` (`TenantAwareJob` outbox), `CommissionHandoffWriter`
(per-component `validated_allocation` seam — no invented rate), and the centerpiece
**`ValidatePaymentRecordingGroup`** transactional action performing the full atomic
sequence (period gate → lock group/invoice/components → maker≠checker → revalidate
total/currency/sum → immutable validation event → components validated → group
validated → invoice `validated_paid_minor` + derived `paid`/`partially_paid` →
gap-free original receipt (durable, PDF pending) → per-component 20G handoff → safe
audit → `GenerateReceiptPdf` dispatched afterCommit). HTTP: policy `validate`,
`ReceiptResource` + `PaymentValidationEventResource`, controller `validateGroup`,
route `POST payment-recording-groups/{group}/validate` (`financial_mutation` + R4
idempotency + branch scope + `customer_payment.validate`; bodiless → added to
`VALIDATION_EXEMPT`). Permission: activated canonical `customer_payment.validate`
(Finance default) + matrix test; maker/checker separation structural + `PaymentMakerCheckerGuard`.
Audit: all 32 Phase-18B events added to `AuditEvent` + severity buckets. OpenAPI
regenerated (111 routes) + TS types.

**Tests (26, all green on PostgreSQL 16):** `PaymentGroupValidationTest` (9 — whole-group
validation, partially_paid/paid derivation, split→one receipt, FO forbidden, maker≠checker,
invalid-state, idempotency+replay, 423 period lock, 404 foreign tenant),
`PaymentGroupValidationAtomicityTest` (2 — full rollback on side-effect failure; invoice
never paid without receipt), `ReceiptNumberConcurrencyTest` (2 — gap-free sequential;
rollback consumes no number), `ReceiptIssuanceTest` (2 — outbox PDF → ready + real
`%PDF-` file in the private domain; idempotent regeneration),
`PaymentRecordingGroupStateMachineTest` (4), `PaymentValidationSchemaTest` (5 — append-only,
one-validated-per-group partial unique, validated-amount + reason CHECKs),
`CommissionValidationHandoffTest` (2 — per-component seam, no rate columns, idempotent).
Contract: `RouteSecurityContractTest` (15), `AuditEventCoverageTest`, `PermissionMatrixTest`,
`OpenApiContractTest`/`OpenApiTypeParityTest` (14) all green. Larastan L8 clean.

**Bug fixes (Bug Fix Protocol summaries):** (1) `DEF-18B-002` — `TenantAwareJob`
readonly promoted properties blocked a subclass (`GenerateReceiptPdf`) with its own
constructor args (PHP forbids a child initializing a parent readonly promoted prop);
made the two ids non-readonly write-once. (2) Test-only: `withHeader('Idempotency-Key')`
in the `recordPaymentGroup` helper persisted on the shared test client, so a "missing
key" assertion (as a different user → different idempotency scope) still carried a key;
fixed by sending an explicit empty key. (3) `RouteSecurityContractTest` required the
bodiless validate route in `VALIDATION_EXEMPT`.

### Slice 4 — Rejection / correction / reference correction — **COMPLETE**
Actions: `RejectPaymentRecordingGroup` (group+components→rejected, immutable rejected
event, mandatory reason, invoice untouched, no receipt/commission),
`RequestPaymentGroupCorrection` (→correction_required, immutable event, invoice
untouched, no receipt), `ResubmitPaymentRecordingGroup` (correction_required→
pending_validation, no event — returns to the queue), `CorrectPaymentReference`
(correctable group only, method-aware validation, original reference-check evidence
preserved [append-only], new encrypted display + new durable `payment_reference_checks`
result, masked before/after audit — full/normalized reference never surfaced). All
period-lock-gated, maker≠checker, `financial_mutation` + R4 idempotency. Permissions:
activated `customer_payment.reject` + `customer_payment.reference_correct` (Finance
defaults + matrix test). HTTP: 4 routes (`/reject`, `/request-correction`, `/resubmit`
[bodiless → `VALIDATION_EXEMPT`], `payment-records/{record}/correct-reference`), policy
methods, `PaymentGroupDecisionRequest` + `CorrectPaymentReferenceRequest`,
`PaymentRecordController`, `PaymentRecord`→policy map. OpenAPI 115 routes + TS.

**Tests (13, green):** `PaymentGroupRejectionCorrectionTest` (7 — reject one-event/
components-rejected/invoice-untouched/no-receipt, reason required, correction→resubmit→
pending, resubmit-invalid-state, FO forbidden, maker≠checker), `PaymentReferenceCorrectionTest`
(3 — correct preserving evidence + masked + audit-redacted, not-correctable 422, FO
forbidden). Contract/permission/audit/idempotency coverage (30) + Larastan L8 clean.
**Regression fixed:** the 18A `PaymentRecordingApiTest` asserted validate/reject routes
were absent (correct for 18A); updated to guard the still-valid invariants (no manual
receipt-issue route per Gate J; no hard-delete) now that Phase 18B adds those routes.

### Slice 5 — Receipts (list/detail/reissue/download) — **COMPLETE**
Permission reconciliation: `receipts.view`→`receipt.view`, `receipts.reissue`→
`receipt.reissue` (canonical), `receipt.reissue` promoted to a Finance **default**
(FilePurposeRegistry receipt_pdf hint + 6-role view grants + matrix test all updated;
REM-PERM-001 stays open). `ReceiptController` (index with invoice filter + pagination,
show, reissue, download-link); `ReceiptPolicy` (viewAny/view/reissue/download);
`ReissueReceipt` action (new immutable row + new gap-free number always referencing the
**original**, original never mutated, new `receipt_pdf` outbox job; `PL n/a`);
`ReissueReceiptRequest` + `ReceiptIndexRequest`. Downloads reuse the **Phase 10F file
boundary**: `download-link` re-checks authorization at issuance (`FileAccessService.
authorizeDownload`) and the existing `files.download` signed route re-checks again at the
byte stream; audits `receipt.downloaded` (safe). Routes: GET `receipts`, GET
`receipts/{receipt}`, POST `receipts/{receipt}/reissue` (financial_mutation + idempotency),
POST `receipts/{receipt}/download-link` (`VALIDATION_EXEMPT`). OpenAPI 119 routes + TS.

**Tests (18, green):** `ReceiptReissueTest` (5 — new row+number refs original, immutable
original, reason required, FO forbidden 403, reissue-of-reissue refs original, foreign
404), `ReceiptDownloadAuthorizationTest` (5 — signed link + `receipt.downloaded` audit
redacted, FO can download, 409 not-ready, foreign 404, resource exposes no
path/file_id/signature/internal id), `ReceiptApiTest` (4 — list masked, invoice filter,
detail, HR forbidden), + `ReceiptIssuanceTest`/`ReceiptNumberConcurrencyTest`. Route
security / OpenAPI / audit-coverage / permission-matrix (33) all green; Larastan L8 clean.

### Slice 6 — Refunds & finance disputes
Status: **in progress (refund domain + permission reconciliation done + green).**
Done: extended the invoice state machine for §44 refunds (`partially_paid →
refund_pending`; `refund_pending → issued/partially_paid/paid`; `refund_pending` no
longer terminal) with `InvoiceStateMachineTest` updated + green. Refund domain:
`RefundStateMachine`, `RefundException`, `RefundableBalanceCalculator` (remaining =
validated − finalized/in-flight), and the 4 actions — `RequestRefund` (validated
component, amount ≤ remaining refundable, method-aware external reference, invoice →
refund_pending preserving prior state), `ApproveRefund` (approver ≠ requester, fresh
step-up via route, period-gated), `RejectRefund` (restores prior paid state when no
in-flight refund remains, validated_paid unchanged), `FinalizeRefund` (additive:
reduces validated_paid, derives invoice state, component adjusted/reversed, group
reversed only when every component reversed, durable per-component 20G reversal
handoff, balance-out-of-range rollback). Permission reconciliation: `refunds.request`
→`refund.create` (Finance default), `refunds.approve`→`refund.approve` +
`refund.finalize` (Finance grantable — a distinct membership from the requester;
actor guard also enforces requester≠approver≠finalizer; REM-PERM-001 owns
registry-level incompatibility), `disputes.manage`→`finance_dispute.manage` (Finance
default) — across registry, FilePurposeRegistry, matrix test, and 5 override/
preview/freshness auth tests. **Verified green:** Larastan L8 clean on
Refunds/Invoicing/Auth; 68 affected auth + invoice-state-machine tests pass.
**Slice 6 COMPLETE.** Refund HTTP: `RefundController` (index/store/show/approve/reject/
finalize), `RefundPolicy`, `RefundResource` (masked — no plaintext reference/internal
id), `RequestRefundRequest`/`RefundIndexRequest`, 6 routes (all `financial_mutation` +
R4 idempotency; approve+finalize carry `RequireFreshMfa` — new `RefundApproval` step-up
action added alongside `RefundFinalization` without weakening it). Finance disputes:
`FinanceDisputeStateMachine` + `FinanceDisputeException`, 4 actions (create/start-review/
resolve/reject), `FinanceDisputePolicy` (finance-only), `FinanceDisputeResource`,
`FinanceDisputeController` + 6 routes (BranchMutation; PL n/a; invoice/payment-record
linkage; private Phase 10F `dispute_evidence`; source never mutated). Bug fixes:
`invoices.previous_status` CHECK is void-only, so refund reject/finalize **derive** the
restored state from `validated_paid_minor` (no previous_status write); test helper
`grantOverride` seeds the permission catalogue + sets tenant `merchant_id`; step-up tests
enroll the approver (`confirmedTotp`) + use stale/fresh assertions. **Tests (18 + 40
contract, green):** `RefundWorkflowTest` (4 — request→approve→finalize non-destructive,
partial→adjusted/partially_paid, reject restores, maker≠checker), `RefundAllocationTest`
(5 — over-refund, remaining-refundable, non-positive, foreign 404, reference redaction),
`RefundStepUpTest` (2 — approve+finalize stale step-up denied), `FinanceDisputeWorkflowTest`
(7 — lifecycle, linkage, note, invalid transition, private evidence redaction, FO/HR
forbidden, foreign 404). RouteSecurity/PermissionMatrix/AuditCoverage/Idempotency/
OpenApi/MfaStepUp (40) green. OpenAPI 131 routes + TS. Larastan L8 clean; Pint clean.

### Slice 7 — Cash-up & day-close guards — **COMPLETE**
`CashUpStateMachine` (draft→submitted→approved→rejected/correction_requested→submitted;
approved→locked; invalid → `422 invalid_state_transition`). `CashUpExpectedTotalCalculator`
(Gate H, server-authoritative): per concrete method, Σ validated `payment_records`
.validated_amount_minor paid on the Africa/Nairobi business date − Σ finalized refunds of
that method finalized that day; pending/rejected/correction excluded; `split_payment` never
a line. `CashUpSnapshotWriter` rebuilds lines from server expected + Branch-Manager counts
(header = Σ lines). Actions CreateOrUpdateCashUpDraft / Submit / Approve (approver ≠
submitter → `403 maker_is_checker`) / Reject / RequestCorrection / Resubmit / Lock — each
period-gated, `lockForUpdate`, typed audit, no destructive overwrite. `CashUpPolicy` +
`CashUpResource` + requests + `CashUpController`; routes `cash-ups.*` (`financial_mutation` +
idempotency; draft PUT `branch_mutation`; reads via policy). Day close: kept
`dayCloseBlockers()` operational-only (Phase-16 contract intact) and added
`financialDayCloseBlockers()` (`cash_up_not_approved` / `pending_payment_validations` /
`unissued_receipts`) merged by `CloseBranchDay`. Tests: `CashUpWorkflowTest` (10),
`CashUpIsolationTest` (2), `BranchDayCloseCashUpGuardTest` (7) — green. Three cross-phase
day-close tests updated to seed an approved cash-up (financial gate).

### Slice 8 — Database-backed period locks & exceptional reopen — **COMPLETE**
`DatabasePeriodLockRepository` rebound in `AppServiceProvider` (replaces the always-open
stub → `423 financial_period_locked` now enforced across every existing financial mutation
with no call-site change; merchant-wide `branch_id null` OR matching branch; ambient
`MerchantScope` + explicit `merchant_id`, no `withoutGlobalScopes`). `FinancialPeriodLock
StateMachine`, `FinancialPeriodLockException`, actions CreateFinancialPeriodLock (overlap →
`422 overlapping_period_lock`, EXCLUDE-backed), RequestPeriodReopen, ApprovePeriodReopen
Exception (MA ≠ requester → `403`), ExecutePeriodReopen (fresh MFA; exception-required
refused without a distinct MA approval). `FinancialPeriodLockPolicy` + Resource + requests +
controller; routes `period-locks.*` (execute carries `RequireFreshMfa:period_reopen`; approve
+ execute bodiless → VALIDATION_EXEMPT). Finance owns create + reopen; MA owns
`merchant.period_reopen.approve_exception` only; `period_lock.reopen ⟂
merchant.period_reopen.approve_exception`. Tests: `FinancialPeriodLockTest` (9),
`PeriodReopenGovernanceTest` (5) — green.

### Slice 9 — Finance exports — **COMPLETE**
`FinanceExportCsvBuilder` (masked, merchant + optional-branch scope applied IN the query,
`chunkById`, no full/normalized reference or client contact). `RequestFinanceExport` (reject
compensation/payouts/billing → `422 unsupported_export_type`; dispatch on `reports-exports`
afterCommit), `GenerateFinanceExport` (`TenantAwareJob`, idempotent skip if not `queued`,
Phase-10F `GeneratedFileWriter` `FilePurpose::FinanceExport`, `expires_at` from retention),
`RevokeFinanceExport`, `ExpireFinanceExport`. `FinanceExportPolicy` + Resource + requests +
controller; download-link does atomic `download_count++` / first-once / last via
`lockForUpdate` + `FileAccessService` signed URL + re-auth; `409 finance_export_not_ready`
when not ready/expired/revoked. New `StepUpAction::FinanceExportCreate` on the request route.
`finance_export.*` is `PL n/a`. Tests: `FinanceExportTest` (9, incl. masking + idempotency +
expiry/download-count) — green.

### Slice 10 — Permissions / routes / API / OpenAPI — **COMPLETE**
Canonical permission reconciliation in `PermissionRegistry` + `PermissionMatrixTest` +
`FilePurposeRegistry`: removed legacy `periods.lock` / `cashup.submit` /
`cashup.review_approve` / `periods.reopen` / `exports.finance`; added `branch.cash_up.submit`
(Branch Manager), `cash_up.view/approve/reject/request_correction` (Finance), `period_lock
.create/reopen` (Finance), `merchant.period_reopen.approve_exception` (Merchant Administrator —
no routine lock authority), `finance_export.create/download` (Finance). Incompatibilities
proven: `branch.cash_up.submit ⟂ cash_up.approve`, `period_lock.reopen ⟂
merchant.period_reopen.approve_exception`. OpenAPI regenerated (**152 operations / 130 paths**)
+ TypeScript regenerated + `api:contract:check` OK. Green: `PermissionMatrixTest`,
`RouteSecurityContractTest`, `FinancialRouteIdempotencyCoverageTest`, `MfaStepUpTest`,
`AuditEventCoverageTest`, `TenantColumnCoverageTest`, `ModelTenancyTraitCoverageTest`,
`MigrationManifestTest`, `DataDictionaryCoverageTest`, `TenancyStaticAnalysisTest`,
`RouteBindingTenantSafetyTest`, `OpenApiContractTest`, `OpenApiTypeParityTest`. Full 18B
feature filter: **90 passed / 0 failed / 479 assertions**. Pint + Larastan L8 clean.
`REM-PERM-001` stays open (Phase 19 owns `docs/auth/permission-matrix.yaml` + per-key parity).

### Slice 11 — Frontend, navigation, screen specs — **COMPLETE (local gates green; Playwright per §Quality gates)**
Stores (Pinia, generated-contract-aligned, idempotency keys on financial mutations, no signed
URL/secret persisted): `cashUpStore`, `receiptStore`, `refundStore`, `financeDisputeStore`,
`periodLockStore`, `financeExportStore`, and `paymentStore` extended (validate/reject/
request-correction/correct-reference/resubmit). Screens — Finance: task inbox (→
`finance.dashboard`), pending-validations + extended payment-group detail (whole-group
validate/reject/request-correction/reference-correct/resubmit; no partial; receipt-issued
banner), receipts list/detail (reissue gated by `receipt.reissue`), refunds list/detail
(request + approve/reject/finalize, irreversible-finalize warning), disputes list/detail
(source read-only), cash-up review list/detail (approve/reject/request-correction/lock),
financial periods (create + reopen request/execute), exports (request/download/revoke, only
the six supported types); Branch Manager: cash-up (server expected read-only, counted entry,
variance, submit/resubmit, read-only when submitted/approved/locked, no approve control);
Merchant Administrator: exceptional-reopen approvals (approve only, no lock/execute); Front
Office: receipts (view + download only, no reissue). Navigation flipped 9 planned Phase-18B
items → `live` + added `merchant.period-reopen-approvals`; `role-navigation.yaml` +
`inventory.yaml` regenerated; 88 §27.1 screen specs generated. Local frontend gates:
**Vitest 222 passed / 54 files** (≈25 new component specs proving capability-gated controls
present/absent by role, whole-group-only validation, BM-cannot-approve, reissue gating,
unsupported-export-types absent, MA-approve-only), **ESLint 0 errors**, **vue-tsc typecheck
clean**, **`npm run build` OK (349 modules)**. Playwright specs
`payment-validation-receipt` / `refund-dispute` / `cash-up-period-lock` / `finance-export`
(360/768/1280 + light/dark + keyboard + axe serious/critical = 0) — result recorded under
§Quality gates (Linux CI is the authoritative browser gate).

### Slice 12 — Full quality gates
Status: _recorded under §Quality gates as each gate executes._

---

## Defect log (Bug Fix Protocol)

_For every defect: Observed problem · Evidence · Affected files · Root cause · Why
this is the root cause · Correct fix · Files changed · Tests added/updated · Test
command · Test result · Proof of resolution · Remaining risk._

### DEF-18B-001 — development database reset during migration rollback proof

- **Observed problem:** the normal development database was reset during migration
  rollback proof and demo seed data was removed.
- **Evidence:** during Slice 2, `php artisan migrate:rollback` was run against the
  running dev stack's database to prove the Phase 18B batch was reversible; because
  the dev DB's applied migrations were all in a single batch, the rollback removed
  the full schema, and the subsequent `php artisan migrate` re-created the schema
  from scratch (`create_cache_table`/`create_users_table` reappeared in the output),
  dropping the previously seeded demo tenants.
- **Affected files/tables:** none in source — this is a runtime/operational defect
  (the dev database contents), not a code defect. No migration, model, or test was
  wrong.
- **Root cause:** the migration rollback was executed against the development
  database rather than a disposable PostgreSQL database or a controlled test
  database.
- **Why this is the root cause:** `migrate:rollback` operates on whatever database
  the default connection points at; the default connection in the dev container is
  the development database, so running rollback there necessarily mutated dev data.
  The reversibility proof itself was valid; the *target* database was wrong.
- **Corrective action / correct fix:** use a disposable PostgreSQL database (or the
  controlled `RefreshDatabase` test database) for **all** remaining rollback,
  fresh-migration and migration-path proof. Do **not** run destructive migration
  proof against useful development data again. Restore demo seed data through the
  established safe seeding command (`make fresh` / `php artisan migrate:fresh --seed`)
  when a populated dev environment is next needed.
- **Files changed:** none (operational correction + this record).
- **Tests added/updated:** none required; the schema reversibility is re-proven going
  forward only against the test/disposable database. The RefreshDatabase-backed
  coverage suites (`TenantColumnCoverageTest`, `ModelTenancyTraitCoverageTest`,
  `MigrationManifestTest`, `DataDictionaryCoverageTest`) already exercise the full
  fresh-migration path on an isolated database and passed.
- **Test command:** `docker compose exec app php artisan test --filter="TenantColumnCoverageTest|ModelTenancyTraitCoverageTest|MigrationManifestTest|DataDictionaryCoverageTest"`
- **Test result:** green (isolated test database).
- **Proof of resolution:** all later migration-path/rollback verification in this
  phase is performed on the test/disposable database only; the dev database is not
  targeted by destructive proof again.
- **Remaining risk:** the current dev database has schema but no demo seed data until
  `make fresh` is run; this does not affect the test suite (isolated DB) or CI. No
  production data was involved.

### DEF-18B-002 — TenantAwareJob readonly promoted props blocked a subclass constructor

- **Observed problem:** constructing `GenerateReceiptPdf` (a `TenantAwareJob` subclass
  with its own `receiptId` constructor argument) threw `Cannot initialize readonly
  property App\Domain\Tenancy\Jobs\TenantAwareJob::$tenantMerchantId from scope
  App\Domain\Receipts\Jobs\GenerateReceiptPdf`, so every successful validation (which
  dispatches the receipt-PDF outbox job) failed.
- **Evidence:** `PaymentGroupValidationTest` — the 5 tests reaching a successful
  validation failed at dispatch; the 403/404/423/maker-checker tests (no dispatch)
  passed.
- **Affected files:** `app/Domain/Tenancy/Jobs/TenantAwareJob.php`,
  `app/Domain/Receipts/Jobs/GenerateReceiptPdf.php`.
- **Root cause:** `TenantAwareJob` promoted `public readonly ?int $tenantMerchantId`
  / `$tenantBranchId`. PHP forbids a subclass constructor from initializing a parent's
  readonly promoted property (even via `parent::__construct()`). `TenantAwareJob` had
  never been subclassed with additional constructor arguments before Phase 18B, so the
  limitation was latent.
- **Why this is the root cause:** removing the extra constructor argument (or the
  readonly modifier) makes construction succeed; the error names the readonly parent
  property and the child scope.
- **Correct fix:** made the two ids non-`readonly` (still write-once — set only in the
  base constructor, never reassigned; documented inline). Smallest change that unblocks
  tenant-aware jobs carrying their own constructor state.
- **Files changed:** `TenantAwareJob.php` (props non-readonly), `GenerateReceiptPdf.php`
  (plain `public int $receiptId` + `parent::__construct(...)` first).
- **Tests added/updated:** `ReceiptIssuanceTest` (constructs + runs the job),
  `PaymentGroupValidationTest` (dispatch via afterCommit, `Queue::assertPushed`).
- **Test command:** `docker compose exec app php artisan test --filter="ReceiptIssuanceTest|PaymentGroupValidationTest"`
- **Test result:** green.
- **Proof of resolution:** the job constructs, dispatches afterCommit, and generates
  the receipt PDF; the receipt flips pending → ready.
- **Remaining risk:** the write-once discipline is now by convention + docblock rather
  than enforced by `readonly`; no other subclass reassigns these ids.

### DEF-18B-003 — branch cash-up caused page-level horizontal overflow at 360px

- **Observed problem:** the Playwright test
  `cash-up-period-lock.spec.ts › branch cash-up has no horizontal overflow at 360px`
  failed: `document.documentElement.scrollWidth` (530) exceeded `clientWidth`
  (360). The 768px and 1280px variants passed.
- **Evidence:** an isolated diagnostic run (temporary spec, since removed) that
  stubbed `/me` + the branch-day cash-up and walked the DOM at a 360×800
  viewport reported the offending chain: `MAIN.flex-1` = 530px, down to
  `div.overflow-x-auto` = 416px and `table.min-w-[26rem]` = 416px. Every other
  offender (`h1`, the date `input.w-full`, the card `section`) was merely
  stretched by the oversized `MAIN`, not an independent cause.
- **Affected files:** `resources/spa/src/pages/branch/CashUp.vue`.
- **Root cause:** the reconciliation `<table>` carried `min-w-[26rem]` (416px)
  inside an `overflow-x-auto` wrapper, but an ancestor flex item — the app-shell
  `MAIN` (`flex-1`, default `min-width:auto`) — refuses to shrink below its
  content's min-content size. The 416px table therefore set a 416px+padding
  floor on `MAIN`, so `overflow-x-auto` never got the chance to clip and the
  whole page widened to 530px. Adding `min-w-0` to the shared app-shell `MAIN`
  would have been a global layout change outside this phase's scope.
- **Why this is the root cause:** removing the wide table from the mobile render
  (so no 416px min-content floor reaches the flex ancestor) makes
  `scrollWidth === clientWidth` at 360px; the diagnostic re-run reported exactly
  `DOCW 360 / SCROLLW 360` with zero offenders.
- **Correct fix:** the project's established responsive pattern — a stacked
  method-card list at ≤767px (`md:hidden`) and the full table at ≥768px
  (`hidden md:block`). Each mobile card shows the server-derived **Expected**
  read-only, keeps **Counted** operable, and shows **Variance**, plus a totals
  card; the ≥768px table is unchanged. The `counted-<method>` `data-testid`
  stays on the desktop table only (all interacting tests run at the 1280px
  Desktop-Chrome default) so Playwright strict-mode never matches two elements;
  the mobile input uses a distinct `counted-m-<method>` id with its own
  `<label>` + `aria-label`. No product logic changed — both layouts read the
  same `expectedFor` / `varianceFor` / `counts` state.
- **Files changed:** `resources/spa/src/pages/branch/CashUp.vue`.
- **Tests added/updated:** none added — the existing
  `cash-up-period-lock.spec.ts` 360/768/1280 overflow tests and the
  `CashUp.spec.ts` component test are the regression guard.
- **Test command:** `npx playwright test tests/e2e/cash-up-period-lock.spec.ts --workers=1`
- **Test result:** 9 passed (was 8 passed / 1 failed).
- **Proof of resolution:** the four-spec Phase 18B run is 29 passed / 0 failed;
  the full `npm run e2e` suite is 227 passed. The mobile layout keeps Expected
  read-only, Counted operable, and Variance visible at 360px with no page-level
  horizontal overflow.
- **Remaining risk:** the mobile card layout duplicates the presentation (not
  the logic) of the four columns; a future column change must be mirrored in
  both blocks. The `MAIN.flex-1` shell still lacks `min-w-0`, so any *new* wide
  fixed-min-width element added at mobile would resurface the same class of
  overflow — mitigated by the per-screen responsive-overflow E2E tests.

### DEF-18B-004 — cash-up component test coupled to the real system clock

- **Observed problem:** `resources/spa/src/pages/branch/CashUp.spec.ts › lets a
  Branch Manager enter counts and submit` failed after the system date rolled
  from 2026-07-03 to 2026-07-04 mid-session: the PUT assertion expected
  `/branches/b1/cash-ups/2026-07-03` but received `…/2026-07-04`. The counts
  payload (`[{ method: 'cash', counted_minor: 300000 }]`) was correct.
- **Evidence:** the vitest diff showed only the date segment differing
  (`- 2026-07-03` / `+ 2026-07-04`); the same suite had passed earlier the same
  session (222 passed) while the clock still read 2026-07-03.
- **Affected files:** `resources/spa/src/pages/branch/CashUp.spec.ts`.
- **Root cause:** the component derives the business date from the real clock
  (`new Date().toISOString().slice(0, 10)`), but the test hard-coded
  `2026-07-03` for both the mock `business_date` and the asserted PUT URL, so it
  would fail on every day except the authoring day.
- **Why this is the root cause:** the failing value is exactly today's date and
  the only differing token is the date; deriving the expected date the same way
  the component does removes the failure without touching any assertion of
  behavior.
- **Correct fix:** compute `const today = new Date().toISOString().slice(0, 10)`
  once in the spec and use it for the mock `business_date` and the PUT URL
  assertion — mirroring the component and making the test date-independent
  rather than weakening it. (This is a genuine defect fix, not a
  rewrite-to-pass: the assertion still proves the exact same
  URL/payload contract.)
- **Files changed:** `resources/spa/src/pages/branch/CashUp.spec.ts`.
- **Tests added/updated:** the one spec updated as above.
- **Test command:** `npm run test` (vitest).
- **Test result:** 54 files / 222 passed (green at 2026-07-04).
- **Proof of resolution:** the full vitest suite is green on a day other than
  the original authoring day; no other Phase 18B spec was clock-coupled (the
  other `2026-07-03` occurrences are display-only mock data, confirmed by the
  227-green E2E run at the new date).
- **Remaining risk:** none material; the component test now tracks the
  component's own clock derivation.

## Explicit deferrals (owning phase)

commission rules + `commission_ledger` → 20F/20G; actual earned/reversal ledger
writes → 20G; commission payable/earnings → 20G/20H; M-Pesa/Daraja provider payments
→ 20D; notifications/inbox platform → 21N; daily day-close + cash-up PDFs/email →
21N; full audit flagged-event workflow + complete permission-matrix closure
(REM-PERM-001) → 19; platform fee ledger → 20E; subscription billing → 20A/20B;
payouts/earnings → 20F–20H; reports catalogue / materialized views → 21N; search →
22; release-wide security/accessibility audit → 23; performance → 24; deployment →
25.

## Quality gates

_Actual results, recorded after each gate executed on this tree. Local Windows run;
Linux CI remains the authoritative browser/Docker/gitleaks gate at merge._

### Browser / Playwright verification history (chronological, nothing erased)

1. **Initial local Playwright attempt** — the config `webServer`
   (`npm run build && npm run preview`, 120s) timed out before any test
   executed. **Not a result** — no product pass or fail.
2. **First preview-backed run** (manually started preview) — **16 passed / 13
   failed**.
3. **Root cause of the payment-validation failures:** the
   `payment-validation-receipt` E2E navigated to the wrong detail URL.
   **Correction:** pointed the spec at the actual finance payment-record detail
   route.
4. **Next run — 25 passed / 4 failed**, all remaining failures in
   `tests/e2e/cash-up-period-lock.spec.ts`.
5. **Re-verification this session (against the actual tree, `--trace=on`,
   `--workers=1`):** the isolated `cash-up-period-lock.spec.ts` ran **8 passed /
   1 failed** — the *only* real remaining failure was
   `branch cash-up has no horizontal overflow at 360px`. The earlier-reported
   "cash-up-submit / cash-up-approve controls not found" failures did **not**
   reproduce: both the Branch-Manager submit test and the Finance approve test
   passed, i.e. the controls were already present and correctly gated
   (`CashUp.vue`, `CashUpDetail.vue`). ("Service was busy" / rate-limiting
   messages from the earlier interrupted session were correctly **not** recorded
   as product results.)
6. **360px overflow — root cause + fix:** DEF-18B-003 above (flex-ancestor
   min-content floor from the `min-w-[26rem]` table → stacked method cards at
   ≤767px, table at ≥768px). Diagnostic re-run: `DOCW 360 / SCROLLW 360`, zero
   offenders.
7. **Final cash-up-spec rerun** (`--trace=retain-on-failure`, `--workers=1`):
   **9 passed / 0 failed.**
8. **Final four-spec rerun** (`payment-validation-receipt`, `refund-dispute`,
   `cash-up-period-lock`, `finance-export`, `--workers=1`): **29 passed / 0
   failed.**
9. **Full `npm run e2e`:** **227 passed / 0 failed** (3.3m) — the four Phase 18B
   specs plus every pre-existing role/responsive/keyboard/session spec.

Responsive / theme / keyboard / a11y proof is carried by the four Phase 18B
specs collectively: 360 / 768 / 1280 horizontal-overflow checks; light + dark
`emulateMedia` axe scans asserting **serious/critical = 0**; keyboard focus
visibility; accessible dialogs (SvModal), the accessible reconciliation
table / mobile card alternative, and accessible error summaries (`role="alert"`).

### Final gate results (this tree, PostgreSQL 16)

- **OpenAPI:** `composer api:openapi` → **152 production routes**; `npm run
  api:types` regenerated; `npm run api:contract:check` **OK — 130 paths / 152
  operations** (deterministic).
- **Backend serial** (`php artisan test`): **1065 passed / 7 skipped / 0 failed,
  5175 assertions** (578.77s).
- **Backend parallel** (`php artisan test --parallel`, 4 processes): **1065
  passed / 7 skipped / 0 failed, 5175 assertions** (360.37s).
- **Larastan level 8** (`composer stan`): **No errors** (667 files).
- **Pint** (`composer pint -- --test`): **PASS — 877 files**, no style drift.
- **composer validate --strict:** `./composer.json is valid`.
- **Audit chain** (`php artisan audit:verify-chain`): `No audit chains to
  verify` — the dev DB carries no audit rows after DEF-18B-001's controlled
  reset; the append-only + hash-chain invariants are proven by the green feature
  suite (`PaymentValidationSchemaTest`, `AuditEventCoverageTest`, audit-log
  no-UPDATE/DELETE tests).
- **Frontend:** ESLint **0 errors** (138 pre-existing style warnings, none in
  Phase 18B files); `vue-tsc` typecheck **clean**; Vitest **54 files / 222
  passed**; `npm run build` **OK**.
- **composer audit --locked:** *No security vulnerability advisories found.*
- **npm audit --audit-level=high:** **exit 0** — the configured high gate
  passes. Two **moderate** advisories remain (transitive `js-yaml`
  GHSA-h67p-54hq-rp68 via `@redocly/openapi-core`, a dev-only OpenAPI tool, not
  shipped in the SPA bundle). The audit is **not** advisory-free at moderate;
  the high gate is what is enforced.
- **gitleaks** (`detect --source . --no-git --redact`): **no leaks found**
  (~11.81 MB scanned).
- **Docker images:** `docker build -f docker/php.Dockerfile --target dev .` →
  built (`sha256:7d82b999…`); `docker build -f docker/nginx.Dockerfile --target
  prod .` → built (`sha256:0c42c1a1…`). Built one at a time, not concurrently
  with Playwright.

### Lifecycle at local completion

All local acceptance criteria pass on this tree. **Phase 18B → `local_complete`**;
**REM-PAY-001 → `local_complete` pending PR CI / review / merge**. Not
`ci_passed`, `merged`, or `verified_complete` — those follow the Phase 18B PR
merging with green CI and truthful governance evidence. REM-PERM-001 stays open
(Phase 19); REM-ENT-001 / REM-SM-001 / REM-DDL-001 stay open where future
entities / state machines / domain DDL remain.

## Solo-Maintainer Review Exception - PR #31

- PR: #31
- Phase 18B implementation commit: ed07c8b090f74e9bb89457a7a00e99e939d72448
- initial failed CI run: 28694148176
- CI-correction commit and corrected initial-CI head: a0d4dede7ce62e5dbcb7a27467b15ba592ccf6d3
- corrected-head successful CI run: 28695121157
- CI/Backend: passed on corrected head
- CI/Frontend: passed on corrected head
- CI/Docker: passed on corrected head
- CI/Security: passed on corrected head
- CI/E2E - Playwright: passed on corrected head
- GitHub reviewDecision: intentionally blank
- governance record: docs/governance/solo-maintainer-review-exception-pr-31.md

The initial CI failure is retained as part of the Phase 18B evidence trail. The
corrective commit restored required receipt source files and the required CI
suite subsequently passed against the corrected PR head.

This PR-specific governance exception is not independent reviewer approval.
