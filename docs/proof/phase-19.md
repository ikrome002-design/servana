# Phase 19 — Audit Logging Completion & Flagged Events — Proof

**Branch:** `phase-19-audit-flagged-events` · **Base commit:** `64bd0a1`
(`64bd0a117dcdc819a8baf4b9bec3c3eb09635edc`, verified PR #31 squash merge). **Status:**
✅ **`verified_complete`** — PR **#32** `Phase 19: Complete audit logging and flagged events`
MERGED into `main`; merge commit `7ef259e28f51fc9bba24a16ef3945ff61ddef4ce`; merged at
`2026-07-05T11:48:45Z`; head branch `phase-19-audit-flagged-events`; base `main`. Commit
lineage: implementation `e8c70d8` → parallel-worker DB-state isolation fix `bfba53d` → Pint
import-order fix `46087fe` → governance / final PR head `d6455f3`. Final CI run `28736716360`:
five required checks (Backend — Pint/Larastan/Pest, Frontend — ESLint/vue-tsc/Vitest/build,
Docker — build images, Security — gitleaks, E2E — Playwright) all **SUCCESS**. `reviewDecision`
blank under the documented PR-specific solo-maintainer governance exception
(`docs/governance/solo-maintainer-review-exception-pr-32.md`) — **not** independent reviewer
approval. Local and remote Phase 19 branches deleted after merge. **REM-PERM-001** and
**REM-AUDEXP-001** promoted to `verified_complete` on this merge.
The narrative below preserves the historical in-progress/local-complete evidence as written
during the phase (increment statuses, blockers, and defect records are unedited history).
Tests run on PostgreSQL 16 (never SQLite).

Controlling sources: Plan §0–§2.1, §5.3–§5.4a, §8, §9, §10, §13.1–§13.5, §14–§15,
§19.1–§19.5, §22.2, §24, §25, §27.1, §27.3, §28–§30, §65, §67, §70, §71, §74–§76, §80,
§§81–82, §85; ADR-008.

---

## Git integrity & Phase 18B reconciliation (done first)

- Branch-safety block ran clean: `git fetch --prune` ok; `git fsck --full` ok (only
  dangling blobs); PR **#31** verified MERGED, title `Phase 18B: Implement validation
  receipts and finance controls`, merge commit
  `64bd0a117dcdc819a8baf4b9bec3c3eb09635edc`, `reviewDecision` blank; `main` = `origin/main`
  = the merge commit, working tree clean. New branch `phase-19-audit-flagged-events`
  created off the merge. Damaged repo not used.
- **Phase 18B → `verified_complete`** reconciled in `docs/PROGRESS.md`,
  `docs/CHANGELOG.md`, `docs/proof/phase-18b.md`, `docs/remediation/register.yaml`
  (REM-PAY-001 → `verified_complete`), `docs/traceability/servana-requirements.csv`
  (SRV-PAYMENT-002 → `verified_complete`). Recorded truthfully: implementation `ed07c8b`,
  CI-correction `a0d4dede7ce62e5dbcb7a27467b15ba592ccf6d3`, governance
  `a8f988b68872eb3e352bc7f70dbb362bfb320cf3`; **initial failed CI run `28694148176`
  preserved**, corrected-head run `28695121157` SUCCESS, final governance-head run
  `28695314469` SUCCESS; `reviewDecision` blank under the documented solo-maintainer
  exception (`docs/governance/solo-maintainer-review-exception-pr-31.md`) — **not**
  independent reviewer approval. No initial-failure evidence erased.

---

## Increment 1 — Specification-first gate + `audit_flagged_events` schema — **COMPLETE (green)**

Specification (authored before the migration, ADR-004 §6):

- **Data dictionary:** created `docs/architecture/data-dictionary/audit-files-notifications.md`
  (previously absent) with the full `audit_flagged_events` entry — columns, CHECKs,
  composite tenant FK, indexes, masking, retention, locking, transitions, factory/test
  requirements, migration order/forward-repair — plus a summary of the existing
  `audit_logs` core and an explicit "notifications → Phase 21N" boundary.
- **State machine:** created `docs/architecture/state-machines/audit-flagged-event.md`
  with the Plan §25 per-transition contract (actor/permission/tenant-branch/validation/
  txn-lock/writes/audit-event/failure) for the coherent lifecycle
  `open → under_review → resolved|dismissed → reopened → under_review`.

Schema + wiring:

- **Migration** `2026_07_04_000001_create_audit_flagged_events_table.php` (forward-only,
  create). Branch-owned; `ulid` public id + route key; `audit_log_id` FK **ON DELETE
  RESTRICT** to the append-only `audit_logs` (source never mutated / deleted); status
  CHECK `open,under_review,resolved,dismissed,reopened`; resolution CHECK
  `((resolved,dismissed) = resolved_by AND review_notes present)`; assignment CHECK
  `(under_review ⇒ assigned_to present)`; composite FK `(branch_id,merchant_id) →
  merchant_branches`; indexes `(merchant_id,branch_id)`, `(branch_id,status)`,
  `(audit_log_id)`; `UNIQUE(id,merchant_id)`; **no** `deleted_at` / soft-delete.
- **Enum** `AuditFlaggedEventStatus` (mirrors the CHECK + `allowedTransitions()`); **model**
  `AuditFlaggedEvent` (BelongsToMerchant + BelongsToBranch, ULID route key); **factories**
  `AuditFlaggedEventFactory` (+ `underReview`/`resolved` states) and a **test-only**
  `AuditLogFactory` (fabricates a branch-scoped source row for flag/read tests; production
  audit rows are still written only by `DatabaseAuditRecorder`).
- Registered in `TenantOwnership` (BRANCH_OWNED + MODELS `'branch'` + COMPOSITE_CONSISTENCY)
  and the migration manifest (data-dictionary reference + dependencies).
- **AuditEvent** gained the flagged-event workflow cases (`AuditEventFlagged`,
  `AuditFlaggedReviewStarted`, `AuditFlaggedResolved`, `AuditFlaggedDismissed`,
  `AuditFlaggedReopened`) + `AuditExported`, each severity-mapped (created/reopened/export
  → warning; review-started/resolved/dismissed → notice). No emitter yet — the actions
  that record them arrive in Increment 2 (added as unused catalogue entries only, which
  no coverage test forbids).

**Tests (green on PostgreSQL 16):** `AuditFlaggedEventSchemaTest` (8 — columns/no-soft-delete,
status CHECK, resolution CHECK reject + accept, assignment CHECK, RESTRICT on the source
row, composite-FK branch/merchant consistency, unique ULID route key),
`AuditFlaggedEventStateMachineTest` (4 — allowed graph, rejected skips/self-loops,
terminal-but-reopenable, DB-CHECK value parity). Regression-safe: `AuditEventCoverageTest`
(5), `MigrationManifestTest` (6), `TenantColumnCoverageTest`, `ModelTenancyTraitCoverageTest`
all still green. **Pint** clean on changed files; **Larastan L8** No errors on
`app/Domain/Audit` + factories + `TenantOwnership`.

**Increment 1 re-verification (resume session):** `AuditFlaggedEventSchemaTest` (8),
`AuditFlaggedEventStateMachineTest` (4), `AuditEventCoverageTest` (5),
`MigrationManifestTest` (6), `TenantColumnCoverageTest` (5), `ModelTenancyTraitCoverageTest`
(4) — **34 passed / 346 assertions** on PostgreSQL 16. Docker stack healthy. No regression.

### DEF-19-001 — Postgres transaction abort (25P02) in a schema test

- **Observed problem:** `AuditFlaggedEventSchemaTest` "requires resolver + notes" failed
  with `SQLSTATE[25P02]: current transaction is aborted` on the second (valid) UPDATE.
- **Evidence:** the trace showed the valid accept-case UPDATE failing immediately after a
  deliberately-failing CHECK-violation UPDATE in the same test.
- **Root cause:** under `RefreshDatabase` each test runs in one Postgres transaction; a
  CHECK violation aborts the **whole** transaction, so any later statement in the same
  test errors until rollback.
- **Correct fix:** split the reject case and the accept case into two `it()` blocks, so
  each gets its own transaction (one deliberately-failing write per test).
- **Files changed:** `tests/Feature/Audit/AuditFlaggedEventSchemaTest.php`.
- **Test result:** green (8 passed).
- **Remaining risk:** none; the pattern (one expected-failure write per test) now holds.

---

## Increment 2 — Flagged-event backend vertical slice — **COMPLETE (green)**

State-machine-controlled review workflow over the immutable audit source:

- **Domain:** `AuditFlaggedEventStateMachine` + `AuditFlaggedEventException`
  (`invalid_state_transition` / `flagged_event_note_required` / `audit_event_not_flaggable`);
  actions `FlagAuditEvent`, `StartFlaggedEventReview`, `ResolveFlaggedEvent`,
  `DismissFlaggedEvent`, `ReopenFlaggedEvent` — each `DB::transaction` + `lockForUpdate`,
  validate the transition, mutate **review metadata only**, emit a typed `AuditEvent`, and
  never touch `audit_logs`/the audited source. Reopen clears assignee/resolver/notes (the
  who/when is preserved in the immutable audit trail) to keep the resolution invariant.
- **HTTP:** `AuditFlaggedEventPolicy` (`viewAny`/`view`/`create`/`updateStatus`/`resolveMetadata`),
  `FlagAuditEventRequest` + `FlaggedEventResolutionRequest`, masked
  `AuditFlaggedEventResource` (ULID + status + review metadata + a MASKED linked-audit
  summary via `AuditValueMasker`; no internal id/hash/path), thin
  `AuditFlaggedEventController` (index/store/show/start-review/resolve/dismiss/reopen).
  Routes under the merchant group: reads `audit.branch_events.view`; writes the canonical
  `audit.flagged_event.create`/`update_status`/`resolve_metadata`; branch mutations;
  bodiless start-review/reopen added to `VALIDATION_EXEMPT`. Policy registered in
  `AppServiceProvider`.
- **Permissions reconciled:** legacy single `audit.flag` → canonical
  `audit.flagged_event.create` + `audit.flagged_event.update_status` +
  `audit.flagged_event.resolve_metadata` (Audit's in-domain write set — review metadata
  only) + `audit.branch_events.view` (read); catalogue + Audit default grants +
  `PermissionMatrixTest` + `AuthorityBoundariesTest` updated. OpenAPI regenerated to **159
  operations / 136 paths** + TS synchronized + contract check OK.
- **Tests green (PG16):** `AuditFlaggedEventWorkflowTest` (9 — full lifecycle,
  invalid-transition 422, locked duplicate-transition guard, note-required, source
  immutability across the workflow, masked linked row, typed masked events per transition,
  assigned-branch-only list), `AuditFlaggedEventIsolationTest` (5 — foreign-tenant 404,
  unassigned-branch not surfaced + denied, non-Audit role denied, cross-branch flag denied,
  foreign audit-row flag 404), `AuditSourceMutationDenialTest` (9 — Audit denied on
  branch/service/invoice/payment/period-lock/finance-export/permission-override mutations;
  audit_logs GET-only, PUT/DELETE 405, hash unchanged). Regression-safe: **audit group 75
  passed**, **auth suite 142 passed**, RouteSecurityContract/OpenApi/RouteBindingTenantSafety
  (26) green; Pint clean (899 files); Larastan L8 No errors on the new code.

## Increment 3 — Masked, domain-segmented audit reads + `audit.view_full` retirement — **COMPLETE (green)**

Canonical §19.2/§19.3 audit-read closure (human-authorised decision, resume session):
Q1 = **full canonical conformance**; Q2 = **platform-governance-only for merchant-level
rows**. Recorded here as a **source-of-truth correction** (the Plan controls).

### Source-of-truth correction (Plan §19.1 reconciliation)

- **As-built conflict:** the legacy registry + tests granted the catch-all `audit.view_full`
  to **Merchant Administrator, Branch Manager, HR, Finance, and Audit**, and R2 tests
  asserted the Merchant Administrator could read the **whole** merchant trail including
  merchant-level (`branch_id` null) rows.
- **Conflict with canonical Plan §19.2/§19.3:** the canonical matrix grants audit-read keys
  **only** to Audit (`audit.branch_events.view` / `audit.finance.view` / `audit.compensation.view`),
  Finance (`finance.audit.view`), and Super Admin (`platform.audit.view`); all merchant audit
  keys are **branch-scoped**. Merchant Admin / Branch Manager / HR hold **no** audit key.
- **Controlling source:** the **Development Plan** (source-of-truth #1). The as-built grants
  and the R2 test premise are the drift; the Plan wins.
- **Correction applied:**
  1. `audit.view_full` **retired entirely** — removed from `PermissionRegistry` (catalogue +
     every default grant), the DB projection (new `PermissionSeeder::prunePermissions()` deletes
     any key not in the registry), `AuditLogPolicy`, `routes/api.php`, `FilePurposeRegistry`
     (`AuditEvidence` → `audit.branch_events.view`), `PermissionMatrixTest`, and the e2e
     admin-permission fixture. No alias / compatibility / temporary / hidden fallback remains.
  2. Canonical keys **added**: `audit.finance.view` + `audit.compensation.view` (Audit defaults),
     `finance.audit.view` (Finance default). `audit.branch_events.view` already existed (Increment 2).
  3. Obsolete tests **rewritten, not deleted** — their premise replaced with canonical
     regression assertions (below).

### Design — domain-segmented, branch-scoped, masked reads

- **`AuditDomain` enum** (`General|Finance|Compensation`) + **`AuditEvent::domain()`** classify
  every typed event; **`AuditEvent::actionsIn()`** drives server-side domain filtering (never a
  client predicate). Finance = invoice/payment/receipt/refund/dispute/cash-up/period-lock/
  finance-export events; Compensation = **empty until Phases 20F–20H**; everything else General.
- **Endpoints** (merchant group, all GET, field-masked via `AuditValueMasker`, ULID-only,
  allowlisted filters/sorts, bounded pagination, deterministic `orderBy … , id desc`):
  `GET /audit-logs` (General, `audit.branch_events.view`), `GET /audit-logs/finance`
  (Finance domain, `finance.audit.view` **OR** `audit.finance.view`), `GET /audit-logs/compensation`
  (`audit.compensation.view`), `GET /audit-logs/{auditLog}` (branch-event detail).
  `EnsurePermission` extended to an **OR of keys** (variadic) so the finance surface serves
  the Finance and Audit roles under their distinct canonical keys with no aliasing. Literal
  segment routes precede the ULID `{auditLog}` route.
- **Scope:** `AuditLogController::scopedBranchQuery()` = `merchant_id = caller` **AND**
  `branch_id NOT NULL` **AND** `branch_id ∈ assigned branches`. **Merchant-level (`branch_id`
  null) rows are excluded from every merchant-tier surface** (Q2) and denied in `AuditLogPolicy::view()`.
  Empty branch assignment ⇒ empty result (established denial posture). Foreign-tenant ULID ⇒ 404.

### Merchant-level (`branch_id` null) visibility — deliberate limitation

Per Q2, merchant-level structural rows (e.g. `permission.override.*`, `branch.created`,
`membership.suspended` recorded without a branch) are **not** exposed to any merchant-tier
audit reader, **not** surfaced to branch-scoped Audit, and **do not** reintroduce MA/BM/HR
access. `platform.audit.view` was **not broadened** in this correction (it retains its existing
platform-chain scope). **Recorded limitation:** there is currently **no** merchant-tier
user-facing surface for merchant-level raw audit rows; Merchant-Administrator oversight of
those structural events remains via reports/dashboards/summaries (not the raw trail). The rows
stay in the immutable, hash-chained `audit_logs` for governance/chain verification. No
permission or route was invented to fill the gap.

### Tests (green on PostgreSQL 16)

`AuditReadSegmentationTest` (6 — branch/finance segmentation for Audit; empty-but-authorised
compensation; Finance reads finance only (denied branch-events + compensation); MA/BM/HR/Front-Office
denied all three surfaces; finance surface confined to assigned branch, other-branch hidden),
`AuditRedactionTest` (2 — token/session `[redacted]`, `gross_pay` `[restricted]`, reference/phone/
email partial masks incl. nested, non-sensitive pass-through; no internal id/hash/prev_hash/ip/
actor_id/auditable_id in the payload, ULID ≠ sequential id), rewritten `AuditMaskedReadTest`
(5 — Audit-role masked pagination/actor-mask/allowlist-filter/no-key-403/foreign-404) and
`AuditBranchScopeTest` (4 — assigned-branch-only list, other-branch 403, merchant-level excluded
+ 403, **Merchant Admin denied every direct raw surface**), `PermissionMatrixTest` (3 — zero-mismatch
seeded matrix, DB==registry parity, matrix artifact). Regression-safe: **audit+auth+permissions
groups = 224 passed / 844 assertions**; RouteSecurityContract (7)+FileRouteSecurityContract (3)+
OpenApiContract (2)+OpenApiTypeParity (5)+RouteBindingTenantSafety (2)+FilePurposeRegistry (6)
green; flagged-event workflow/isolation/source-mutation-denial still green after the
`EnsurePermission` OR change. **Pint** clean (902 files); **Larastan L8** No errors.
**OpenAPI** regenerated → **138 paths / 161 operations**; **TS** synchronised; contract check OK.

### Increment 3 residual note

`finance.audit.view` carries `MFA Y` in the §19.3 matrix, but (consistent with the existing
Finance reads, e.g. `customer_payment.view`, which are not MFA-gated at the route today) the
audit read routes carry no MFA middleware. Per-key MFA enforcement is deferred to the
permission-matrix closure / MFA-enforcement layer (Increment 6 / Phase R3 seam); audit READ
access-event logging ("audit of audit") is **not** currently emitted and is recorded here as a
known limitation (reads do not mutate the chain), not fabricated.

## Increment 4 — Audit export — **RESOLVED + IMPLEMENTED (green)** (was Outcome C blocker; product-owner authorized `audit_exports`, ADR-010)

**Product-owner decision (2026-07-04, resolving REM-AUDEXP-001):** authorize a dedicated,
branch-scoped `audit_exports` table for Phase 19 — because the Plan requires Audit exports to be
async/reason-gated/masked/signed/expiring/download-counted/audited, `uploaded_files` cannot persist
that request lifecycle, `finance_exports` is Finance-owned (its `export_type` CHECK excludes audit),
deferring to Phase 23 would leave the Phase-19 `audit.export` permission + screen incomplete, and
generalizing `finance_exports` would rewrite shipped Phase-18B behaviour (higher risk). `finance_exports`
NOT generalized; NO export columns added to `uploaded_files`; NO event-derived live state.

**Plan correction (narrow):** `audit_exports` added to §13 launch-table inventory + a §13.5 DDL
definition + the §80 Phase-19 DB scope; Phase 23 preserved as final release-wide export **hardening**,
not the initial build. **ADR-010** authored. Data-dictionary entry added to
`audit-files-notifications.md`. REM-AUDEXP-001 `blocked` → `in_progress`.

**Schema (`2026_07_04_000002` + purpose-expand `..._000003`):** branch-owned (`branch_id` NOT NULL),
ULID route key; `requested_by_user_id`; `reason` (non-empty CHECK); `scope_json` jsonb (object CHECK);
backed `status` enum `queued|processing|ready|failed|expired|revoked` + CHECK; `file_id`→`uploaded_files`
RESTRICT; `row_count`/`download_count`/`first+last_downloaded_at` with coherence CHECKs (first null iff
count=0; last≥first; count≥0); lifecycle timestamps; `ready`⇒file+generated+expires+row_count,
`failed`⇒failed_at+failure_code, `revoked`⇒revoked_at, `expired`⇒expired_at; composite
`(branch_id,merchant_id)` FK; `UNIQUE(id,merchant_id)`; no soft/destructive delete. `FilePurpose::AuditExport`
added (expand/contract on the `uploaded_files` purpose CHECK). Registered in `TenantOwnership`
(BRANCH_OWNED + MODELS `branch` + COMPOSITE_CONSISTENCY) + the migration manifest.

**Domain/HTTP:** `AuditExportStatus`, `AuditExport` (BelongsToMerchant+BelongsToBranch), `AuditExportFactory`,
`AuditExportStateMachine` + `AuditExportException`; actions `RequestAuditExport`/`RevokeAuditExport`/
`ExpireAuditExport`/`RecordAuditExportDownload`; `GenerateAuditExport` (TenantAwareJob, reports-exports,
idempotent — skips non-queued) + `AuditExportCsvBuilder` (masked via `AuditValueMasker`, scope IN the
query, `branch_id NOT NULL`, bounded `chunkById`, **never merchant-level rows**); `AuditExportPolicy`
(audit.export + same-merchant + assigned-branch); `RequestAuditExportRequest` (non-empty reason, one
assigned branch, allowlisted date/domain/severity snapshot) + `AuditExportIndexRequest`; masked
`AuditExportResource` (ULID + status + reason + SAFE scope summary + accounting — never `file_id`/path/
signature/internal id); thin `AuditExportController`; **6 routes** — `GET/POST /audit-exports`, `GET
/audit-exports/{auditExport}`, `POST .../download-link`, `GET .../download` (signed stream — the
accounting point), `POST .../revoke`. Store carries `audit.export` + fresh step-up (`StepUpAction::AuditExportCreate`)
+ `EnsureBranchScope` + `BranchMutation`. Policy registered in `AppServiceProvider`.

**Download accounting (divergence from finance, per mandate):** counted on the authorized **stream**
(`audit-exports.download` GET), not link issuance — `RecordAuditExportDownload` row-locks and atomically
increments `download_count`, sets `first_downloaded_at` once, updates `last_downloaded_at`, emits
`audit_export.downloaded`. Authorization re-checked at both issuance and stream (`FileAccessService`).

**Event reconciliation:** the earlier unused `audit.exported` catalogue entry is **retired**; the
Finance-convention lifecycle events `audit_export.requested|generated|failed|downloaded|expired|revoked`
are added (severity-mapped; General domain). No two events with duplicate meaning.

**Tests (green PG16):** `AuditExportSchemaTest` (8), `AuditExportWorkflowTest` (4),
`AuditExportAuthorizationTest` (3), `AuditExportStepUpTest` (2), `AuditExportIsolationTest` (3),
`AuditExportMaskingTest` (3), `AuditExportJobIdempotencyTest` (2), `AuditExportAuditTest` (3),
`AuditExportExpiryDownloadCountTest` (3) — **audit-exports group = 31 passed / 122 assertions**. Proven:
stable ULID before generation, reason-required 422, unassigned-branch denied, foreign-tenant 404,
same-tenant wrong-branch denied, branch-null never exported, queued→processing→ready/failed transitions,
idempotent generation, redacted failure (no SQLSTATE/path), permission-masked CSV, private file,
link-issuance-no-increment, stream-increments-once, first set once + last updates, revoked/expired
non-downloadable (409), no path/signature/internal-id leak, typed lifecycle events. Regression: mutation-
coverage guard updated (audit-exports.store→`audit_export.requested`, .revoke→`audit_export.revoked`,
.download-link EXEMPT); `RouteSecurityContractTest`/`AuditMutationCoverageTest`/`AuditSeverityCoverageTest`/
`PermissionMatrixTest`/`AuthorityBoundariesTest`/`FilePurposeRegistryTest`/`MigrationManifestTest` green.
**Pint** clean (932 files); **Larastan L8** No errors. **OpenAPI 143 paths / 167 operations** + TS synced +
contract check OK.

---

### Historical blocker record (superseded by the resolution above)

The following capability matrix is the field-by-field evidence that justified the product-owner decision
(an earlier provisional "Outcome A" was **withdrawn** — it wrongly leaned on `audit.exported`/`file.downloaded`
events as a substitute for required live export state).

### Capability matrix (actual repo + Plan)

| Required capability | Authoritative req. | Existing table/column/class | Supported? | Proof | Gap |
|---|---|---|:--:|---|---|
| Stable request ULID returned immediately | async export (Plan line 350) | `uploaded_files.ulid` | PARTIAL | ulid exists but the row is created by the generation pipeline, not at request time | no pre-bytes request handle |
| Requesting actor | authz/audit | `uploaded_files.uploaded_by`/`owner_user_id` | YES | migration `2026_06_24_000001` | — |
| Required reason | reason-gated (line 350) | — (`finance_exports.reason`) | NO | uploaded_files migration has no `reason` | `reason` |
| Merchant scope snapshot | tenant isolation | `uploaded_files.merchant_id` | YES | migration | — |
| Assigned-branch scope snapshot | branch isolation | `uploaded_files.branch_id` | YES | migration | — |
| Validated filter/date snapshot | deterministic gen | — (`finance_exports.scope_json`) | NO | no `scope_json` col | `scope_json` |
| Queued status | async lifecycle | `lifecycle_status`(quarantined)/`scan_status`(pending) | NO | file/scan states, not generation-request states | queued state |
| Processing status | async lifecycle | — | NO | none | processing state |
| Ready status + file association | async lifecycle | `lifecycle_status=available`+`final_path` | YES | migration CHECK | — |
| Failed status + redacted code | retry/support | — (`finance_exports.failure_code`/`failure_message_redacted`) | NO | `scan_failed` ≠ generation failure; no `failure_code` | generation-failure state + code |
| Expiry | signed/expiring (line 350; §19.3 1112) | `retention_until` + `ExpireSignedExport` | YES | model + job | — |
| Revocation | access withdrawal | `lifecycle_status=revoked`+`revoked_at` | YES | migration | — |
| Download authorization recheck | private-file boundary (§9/§73) | `FileAccessService::authorizeDownload` | YES | re-checks tenant/owner/branch/permission at issue AND download | — |
| Download count | **download-counted (line 350)** | — (`finance_exports.download_count`) | NO | uploaded_files has no `download_count`; Files domain has no counter | `download_count` (Plan uses a live counter; event-derivation not authorized) |
| First download timestamp | accounting/evidence | — | NO | no col | `first_downloaded_at` |
| Last download timestamp | accounting/evidence | — | NO | no col | `last_downloaded_at` |
| Row count | export scope | — (`finance_exports.row_count`) | NO | no col | `row_count` |
| Typed request/generate/fail/download/expire/revoke events | audit completeness | `AuditExported` + `file.available/downloaded/expired_or_deleted` (`FileController@download` emits `file.downloaded`) | PARTIAL | enum + controller | no typed `audit.export` requested/generating/failed events |
| Idempotent tenant-aware generation job | queue safety | `GeneratedFileWriter` + finance-only `GenerateFinanceExport` | PARTIAL | writer exists; no generic/audit export job | generic idempotent audit export job |
| Poll/list/detail API (no path exposure) | frontend workflow (§27.1 1416) | finance-only `FinanceExportController`; `FileResource` hides paths | NO | no audit export request resource to poll | request-resource list/detail |

Inspected: `uploaded_files` migration `2026_06_24_000001` + model; `FilePurpose` (has
`finance_export`,`audit_evidence`; **no `audit_export`**); `FilePurposeRegistry`; `GeneratedFileWriter`;
`FileAccessService` (download recheck; **no counter**); `ExpireSignedExport`; `FileController`
(`downloadLink`/`download` — emits `file.downloaded`, **increments nothing**); `finance_exports`
(comparison only — `export_type` CHECK excludes `audit`); Plan lines 350, 461, 552, 630, 768,
889, 932, 970, 1084–1085, 1112, 1416, 1776.

### Why blocked (Outcome C)

- **Plan requires the lifecycle:** line 350 — audit exports are async, **reason-gated**, masked,
  signed, expiring, **download-counted**, audited; §19.3 line 1112 `audit.export` SU Y; §27.1 line
  1416 a Phase-19 masked-export Audit screen.
- **No persistence provides it:** `uploaded_files` lacks `reason`/`scope_json`/`row_count`/
  `download_count`/`first/last_downloaded_at`/failed-generation status (matrix). **Constraints
  forbid** adding columns to `uploaded_files`, reusing `finance_exports` (finance-typed;
  `export_type` CHECK has no `audit`), inventing an `audit_exports` table (Plan §13/§80 Phase-19 DB
  = `audit_flagged_events` **only**), and substituting immutable events for live export state
  (finance uses a live `download_count` column — event-sourced reconstruction is not the canonical
  pattern and is not Plan-authorized here).
- Therefore the required audit-export persistence is **undefined and unbuildable** under the current
  Plan without an amendment.

### Minimal product-owner / Plan decision required (I will NOT choose or build)

1. **(Recommended)** Authorize an `audit_exports` table mirroring the `finance_exports` lifecycle
   (add to the §13 launch inventory + a reviewed data-dictionary entry) — smallest change satisfying
   line 350 without touching shipped 18B finance code; or
2. Generalize `finance_exports` → a shared `exports` table (contract migration on shipped finance
   export — higher risk); or
3. Explicitly defer the audit-export **build** to Phase 23 (export/release hardening) and drop the
   §27.1 "permissioned masked export" Audit screen from Phase-19 scope.

**Blocked-scope discipline:** no migration/model/route/job/store/screen/status-workflow written for
audit export; `audit.export` (and `platform.audit.export`) permission keys **not** added yet; the
Audit-export frontend screen is omitted from Increment 8 (no dead/simulated screen). Tracked as
**REM-AUDEXP-001** (open, blocked). **Only Increment 4 is blocked; Phase 19 continues.**

## Increment 5 — Implemented-mutation audit coverage guard — **COMPLETE (green)**

Enforced registry + failing CI guard so no audited transition can ship silently unaudited.

- **`app/Domain/Audit/Support/AuditMutationCoverage.php`** (first-class artifact, like
  `PermissionRegistry`): `AUDITED` maps **every** implemented mutating (non-GET) `/api/v1` route to
  the typed `AuditEvent` action string(s) its handler emits (100 routes), and `EXEMPT` maps the 3
  mutations that deliberately emit no dedicated typed event, each with a reason
  (`files.download-link` — link issuance, download audited on the GET stream;
  `merchant-registration.first-time-setup.store` — onboarding completion, founding membership
  already audited; `service-sessions.notes` — sanitised note edit, no typed event). Built from the
  **actual emission sites** inventoried across `app/Domain/**` + controllers (not guessed).
- **`AuditMutationCoverageTest`** (5): every non-GET api route is AUDITED or EXEMPT (**fails on any
  unmapped mutation** — the core guard), no stale entries (every classified route still live), no
  AUDITED/EXEMPT overlap, every AUDITED action is a real `AuditEvent` case, every EXEMPT carries a
  reason. The completeness assertion iterating the live route table passed with **504 assertions**,
  proving all 103 non-GET routes are classified.
- **`AuditSeverityCoverageTest`** (5): every `AuditEvent` case resolves to a valid `AuditSeverity`
  and an `AuditDomain` (the matches are exhaustive by construction — a new unmapped case is a
  compile error); every severity tier (info/notice/warning/high) is represented; every
  route-referenced event resolves to a severity (registry↔enum); finance-domain samples classify to
  `AuditDomain::Finance` (consistency with Increment 3 segmentation).
- Positive per-transition emission + redaction remain proven by the domain suites
  (`AuditEventCoverageTest`, `AuditRedactionTest`, each domain's API tests) — not re-driven here.
  **Deferred domains fabricate nothing:** billing/compensation/notifications/SMS own no implemented
  mutating route yet, so they are absent from the registry.
- **Tests green PG16:** 10 passed / 504 assertions. **Pint** clean (905 files); **Larastan L8** No errors.

## Increment 6 — Canonical permission matrix + four-way parity (REM-PERM-001) — **COMPLETE (green)**

Closed the deferred permission-matrix contract: authored the source-controlled canonical
security contract and a CI-enforced four-way parity harness, and proved the deferred
per-key MFA / fresh-step-up enforcement at the backend boundary.

- **`docs/auth/permission-matrix.yaml`** (new) — the source-of-truth contract. It carries a
  row for **every** canonical Plan §19.2 key (**151**: 70 active-canonical + 81 planned) **plus**
  the **17** runtime keys still under their pre-canonical name (**168 rows**, **87 active**).
  Every row has all §19.3 attributes (scope, override_policy, billing/period-lock behaviour,
  entitlement, mfa_required, step_up_required, audit_event, audit_severity,
  maker_checker_incompatibilities, backend/frontend usage, positive/negative test names) plus
  `implementation_status` (`active|planned`); the 17 legacy rows additionally carry
  `canonical_successor` + `owning_phase` (reconciliation happens in the owning phases per §19.1
  — **no prior-phase key is renamed here**, per the Manifesto smallest-correct-change rule).
- **`app/Domain/Auth/Services/PermissionMatrix.php`** (new) — a dependency-free loader (bespoke
  reader for the fixed 2-space YAML subset; `symfony/yaml` is not installed and was not added).
  It powers every parity test and the TS generator so the four projections cannot silently drift.
- **`app/Console/Commands/GeneratePermissionTypesCommand.php`** (`servana:permission-types`,
  `+ --check`; composer `permission:types[:check]`) — deterministically emits
  **`resources/spa/src/types/generated/permissions.ts`** (the **87 active** keys only; planned
  keys never reach the frontend). `--check` is the CI drift guard.
- **Four-way parity GREEN (zero mismatches):** YAML-active (87) == PHP `PermissionRegistry` (87)
  == DB `permissions` projection (87) == TypeScript metadata (87). The retired `audit.view_full`
  and legacy `audit.flag` are absent from YAML, PHP, DB and TS; no planned key projects to DB/TS.
- **MFA closure (Increment 3 deferral):** `finance.audit.view` (MFA Y, SU –) is enforced by the
  route group's `EnsurePrivilegedMfa` — a Finance principal with **no** MFA assertion is denied at
  the MFA gate (`403 mfa_enrollment_required|mfa_challenge_required`) **before** the permission
  check, and the read route carries **no** `RequireFreshMfa`. `audit.export` (SU Y) is guarded by
  `RequireFreshMfa` on `audit-exports.store`; the audit read/list routes are not.
  `platform.audit.export` stays **metadata-only** (planned; no runtime route registered).
- **Tests (green on PostgreSQL 16):** `PermissionMatrixSchemaTest`,
  `PermissionMatrixCatalogueCompletenessTest` (parses §19.2 independently → 151),
  `PermissionMatrixParityTest`, `PermissionTypeScriptParityTest`, `PermissionDatabaseProjectionTest`,
  `PermissionPerKeyAllowTest`, `PermissionPerKeyDenyTest` (resolver-level allow+deny for every
  active key, incl. grantable-via-override + suspended/deactivated → empty), `PermissionOverrideTest`
  (grant/revoke/deny-beats-grant/single-row-constraint/non-grantable no-op), `PermissionNonOverridableTest`
  (audit never gains a mutating key; no contact-export key anywhere; super_admin platform-only),
  `PermissionMakerCheckerTest` (MC declared + no single role holds both sides of a SoD pair by
  default, the deliberate per-transaction Finance pair excluded), `PermissionRoleBoundaryTest`
  (§5.6 named boundaries + guessed Personnel contact-export → 404), `PermissionMfaCoverageTest`,
  `PermissionStepUpCoverageTest`. **Full `tests/Feature/Auth` suite: 184 passed / 1360 assertions.**
  **Pint** clean (947 files); **Larastan L8** No errors.
### Increment 6 — §2 closure: full Plan-to-YAML metadata verification (placeholder removal)

A follow-up correction closed the residual placeholder gap so REM-PERM-001 is proven complete,
not merely active-key-parity green:

- **`PermissionMatrixPlanMetadataParityTest`** — parses Plan §19.3 **independently** (a second code
  path from the generator) and asserts the YAML matches on **every Plan-encoded field for all 151
  canonical keys**: scope, entitlement_key, billing_read_only_behavior, period_lock_behavior,
  mfa_required, step_up_required, audit_severity, maker_checker_incompatibilities, plus default_roles
  (group header) and override_policy (non_overridable group hints). All 151 pass — the canonical
  attributes are Plan-accurate, not best-effort.
- **`audit_event` de-placeholdered** — it is now **derived from the live route table +
  `AuditMutationCoverage`** for active keys (e.g. `audit.export` → `audit_export.requested;
  audit_export.revoked`; `customer_payment.validate` → `customer_payment.validated; receipt.issued`),
  `none` for active reads, and honest `pending` for planned keys (the emitting handler is owned by a
  future phase). The parity test recomputes this independently and asserts equality.
- **`owning_phase` assigned** for all planned + legacy keys per the §80 roadmap (compensation→20F/20G,
  payout/earnings→20H, subscription→20B, plan/price/billing/settings→20A, promotions→20C, M-Pesa→20D,
  reports/notifications→21N, SMS→21S, platform audit export→23).
- **`PermissionLegacyKeyReconciliationTest`** — the 17 legacy-active keys each reconcile to a **planned**
  canonical successor (or `null` where §19.2 has no equivalent: `platform_fees.view/.dispute`,
  `exports.staff_roster`), never to an already-active key, with no duplicate successor and a valid
  owning phase; active canonical keys carry no successor/owning_phase.
- **`PermissionPlannedKeyIsolationTest`** — all 81 planned keys are absent from the PHP registry, DB
  projection, generated TypeScript, every route's `EnsurePermission` middleware, and every role's
  default/grantable set (metadata-only, never a runtime grant).
- Planned descriptions humanised (no `Canonical permission X.` fallback remains).
- **Reruns:** full `tests/Feature/Auth` **192 passed / 1670 assertions**; Pint clean; Larastan L8 no
  errors; `servana:permission-types --check` up to date. **REM-PERM-001 → `local_complete`** (pending
  Phase 19 PR CI/review/merge) — no residual placeholder metadata remains.

- **Residual (owning phases only):** the 81 planned keys' routes/policies/UI still arrive with their
  owning phases (20A–25); the matrix documents them as `planned` + `pending` audit_event + `owning_phase`
  and the isolation test guarantees they never leak into runtime before then.

## Increment 7 — Scheduled chain verification + bounded failure signal — **COMPLETE (green)**

Turned the existing `audit:verify-chain` verifier into a scheduled integrity check with a bounded,
redacted failure signal (Plan §67 scheduler, §70 verification, §71 signal).

- **Schedule (`routes/console.php`):** `Schedule::command('audit:verify-chain')->daily()
  ->withoutOverlapping()->onOneServer()`. The Plan (§1610) lists audit-chain verification among the
  singleton scheduler tasks but pins no sub-daily cadence, so the established **daily** integrity
  cadence is used (matching `idempotency:prune`); documented inline. Centralized transport/paging/
  runbooks remain Phase 25.
- **Bounded failure signal:** new `app/Domain/Audit/Events/AuditChainVerificationFailed` — a readonly
  event carrying ONLY `severity` (fixed `critical`), `category` (`broken_link`|`hash_mismatch` from a
  fixed allowlist), `chain_identifier` (`platform`|`merchant:{internalId}`), `correlation_id` (per-run
  ULID), `failed_chain_count`, `occurred_at` (ISO-8601 UTC). `VerifyAuditChain` generates the
  correlation id, and on any failure emits the event **exactly once per run** plus a matching
  redacted `Log::critical('audit_chain.verification_failed', …)`. NO audit payload, before/after/
  context, full hashes, PII, SQLSTATE, or stack trace is ever included.
- **Command behaviour unchanged where correct:** intact chain → exit 0, safe summary, no signal;
  corruption → non-zero exit + one bounded signal; per-chain independence preserved; read-only.
- **Tests (green on PostgreSQL 16):** new `AuditChainScheduleTest` (registered · daily `0 0 * * *` ·
  withoutOverlapping · onOneServer) and `AuditChainFailureSignalTest` (no signal on valid chains; one
  bounded signal on a tampered/hash-mismatch chain; broken-link categorisation on a forged insert;
  full redaction — exact 6-field set, allowlisted identifiers, no payload/64-hex-hash/SQLSTATE/record
  ulid; verify never mutates a row). The pre-existing `AuditChainVerificationTest` (valid · tamper ·
  broken link · exit · read-only) is retained. 11 passed / 27 assertions; Pint + Larastan L8 clean.
- **Not built here (Phase 25):** centralized alert transport, paging, dashboards, runbooks, escalation.

## Increment 8 — Audit frontend — **COMPLETE (green)**

Pre-Increment-8 verification rerun (Increments 1–7, PostgreSQL 16): targeted filters
`AuditFlaggedEvent|AuditRead|AuditRedaction|AuditExport|AuditMutationCoverage|AuditSeverityCoverage|AuditChain`
+ all `Permission*` = **96 passed / 874 assertions / 0 failed**; `servana:permission-types --check` up to
date; Pint + Larastan L8 clean (Increment 7 reruns). No regression before frontend work.

Building on the existing Audit shell (`AuditLayout`→`RoleShell`), router (`router/routes/audit.ts`),
Pinia conventions, generated `api.ts`/`permissions.ts`, `roleNavigation.ts` registry, the `Sv*` design
system, `permissionStore` capability map, and the `screenInventory` spec framework — no parallel
architecture.

Pre-Increment-8 verification (2nd rerun, this session): backend targeted
`Audit*|Permission*` = **114 passed / 1001 assertions**; `servana:permission-types --check` up to date.

### Increment 8 — Audit-role frontend implementation (COMPLETE, green)

- **Stores (3, + specs):** `auditEventStore` (domain-segmented reads general/finance/compensation,
  allowlisted filters, page meta, detail), `flaggedEventStore` (queue + flag[`note`] + start-review/
  resolve/dismiss/reopen), `auditExportStore` (list/request[no fabricated idempotency]/download-link
  [returned, never stored]/revoke + `isTerminal` polling helper). Store specs assert correct
  endpoints, non-empty-filter-only params (no merchant-level bypass), transition routing, signed-URL
  non-persistence.
- **Screens (8 pages + 1 shared component):** `AuditEventList` (branch-scoped, masked, filters/sort/
  pagination), `AuditEventDetail` (immutable, masked context, no mutation controls), `FlaggedEventQueue`
  (status-filtered), `FlaggedEventDetail` (start-review/resolve/dismiss/reopen gated by server `can`
  map + state; required notes; terminal-transition confirmation; invalid_state_transition surfaced;
  source card read-only), `FinanceAudit` + `CompensationAudit` (shared `AuditDomainEvents`; honest
  compensation empty state — no fabricated events/routes/data), `AuditExportList` (list + reason-gated
  branch-scoped request modal; step-up server-enforced; no-branch state; never exposes file_id/paths/
  signatures), `AuditExportDetail` (queued/processing/ready/failed/expired/revoked polling; on-demand
  private signed download refreshing the download count; authorized revoke with confirmation).
- **Routing:** 8 new child routes under `/audit` (list + detail per surface). **Navigation:** the 5
  Audit nav items flipped `planned`→`live` with real parameter-free `routeName`s (no dead links); the
  `roleNavigation.spec` YAML fixture regenerated + parity green. **Screen inventory:** 8 `implemented`
  Phase-19 entries added (superseding the single planned `audit-branch-log` placeholder); §27.1 specs
  regenerated (`node scripts/generate-screen-specs.mjs`, 96 files) + `inventory.yaml` snapshot updated;
  `screenInventory.spec` (route-coverage + spec-exists) green.
- **Frontend tests:** 5 new specs (3 store + 2 component) = **22 tests**, covering domain routing,
  filter passing, transition routing, download-link non-persistence, polling classification,
  permission-denied control absence (no `audit.export` → no request control; empty `can` → no review
  actions), no-branch state, capability+state gating, source read-only.
- **Gates:** `vue-tsc` typecheck clean (fixed one transition-payload union type); **full Vitest 59
  files / 244 tests pass**; ESLint **0 errors**; `vite build` succeeds. A tree-wide `lint:fix`
  accidentally reformatted 9 unrelated pre-existing files (dashboards/CashUp/SvFileUpload — cosmetic
  `singleline-html-element-content-newline`); these were surgically restored to HEAD so the working
  tree carries only Phase-19 changes.
### Increment 8 — Finance-role Finance Audit surface (COMPLETE, green)

The Audit-role finance view (`audit.finance.view`) is not a substitute for the Finance role's own
surface. Added the Finance-role screen without duplicating the backend endpoint or transport contract:
- `pages/finance/FinanceAuditView.vue` reuses the shared `AuditDomainEvents` panel (parameterised with
  `detailRouteName?`/`mfaNote?`) at domain `finance`, list-only, with an MFA-required note. It hits the
  same `GET /audit-logs/finance` endpoint, which the backend authorises for **either** `finance.audit
  .view` (Finance) **or** `audit.finance.view` (Audit); Finance MFA is enforced by the tenant group's
  `EnsurePrivilegedMfa` (authoritative). Route `finance.audit` (`/finance/audit`); the `finance.audit`
  nav item flipped `planned`→`live` (registry + YAML fixture); a `finance-audit` §27.1 inventory entry
  + regenerated spec. `FinanceAuditView.spec.ts` (4): reads the finance segment + shows the MFA note +
  masks values; no operational/flagged-review controls; error boundary when the backend denies (missing
  MFA); honest empty state. Audit keeps `audit.finance.view`; Finance keeps `finance.audit.view` — no
  cross-grant.

### Increment 8 — Playwright E2E + accessibility (COMPLETE, green)

`tests/e2e/audit.spec.ts` (25 tests, `stubMe`/route-mock convention — the preview has no backend, so
the REAL frontend runs against stubs while genuine enforcement is proven by the Feature suite):
- **Reads:** branch-scoped masked events; no `previous_hash`/`/storage/`/64-hex-hash in the DOM;
  immutable detail with no mutation controls; graceful not-found for a foreign/unknown ulid (404).
- **Flagged workflow:** start-review gated by capability+state; resolve enforces ≥3-char notes +
  confirmation; reopen; `invalid_state_transition` (422) surfaced; source shown read-only.
- **Finance audit:** Audit role reads the finance segment; Finance role reads its own surface with the
  MFA note; a Finance user denied (403 `mfa_challenge_required`) sees the error boundary, not data.
- **Compensation:** real route + honest empty state (no fabricated data).
- **Export:** reason (≥3) + assigned-branch required before request; step-up denial (403) surfaced; no
  request without an assigned branch; ready → on-demand signed link (signature never rendered) →
  `download_count` refreshes after the stream; revoked → no download; failed → redacted only (no
  `file_id`/`/storage/`).
- **Responsive/dark/keyboard/axe:** each of the 7 Audit surfaces — **axe serious/critical = 0** (light
  + dark) and **no page-level horizontal overflow at 360/768/1280**; keyboard focus + dialog reachability.

**DEF-19-002 — link-text colour contrast (axe serious).** First `audit.spec.ts` run: 7 axe failures —
`color-contrast` (WCAG 1.4.3) on `.text-primary` links (Savannah-Orange text on surface fails AA).
*Root cause:* the Audit pages used `text-primary` for text links; the accessible convention (ADR-009)
is `text-heading` + `underline` (orange is a CTA-background/focus-ring colour, not body-text). *Fix:*
replaced `text-primary hover:underline` with `text-heading underline hover:no-underline` on all 7 audit
link sites (list/detail/back links + the shared panel). *Rerun:* 7/7 axe pass; full `audit.spec.ts`
25/25; **full `npm run e2e` 252 passed** (25 audit + 227 existing, no regression).

## Increment 9 — Full final gates — **COMPLETE (green)**

Contracts regenerated + checked: `composer api:openapi` → **167 production routes**; `npm run api:types`
synced; `servana:permission-types` + `--check` up to date; `api:contract:check` OK (143 paths / 167
operations). `composer validate --strict` valid; **Pint clean (953 files)**; **Larastan L8 no errors**.
`audit:verify-chain` OK. Targeted guards (RouteSecurityContract/TenantColumnCoverage/ModelTenancyTrait
Coverage/MigrationManifest/RouteBindingTenantSafety/OpenApiContract/OpenApiTypeParity) **44 passed**.
Frontend: `typecheck` clean, **Vitest 60 files / 248 tests**, ESLint **0 errors** (131 pre-existing
baseline warnings), `build` OK. Security: `composer audit --locked` no advisories; `npm audit
--audit-level=high` passes (2 moderate `@redocly/openapi-core`, below the gate); `gitleaks` no leaks.
Docker: `php.Dockerfile --target dev` + `nginx.Dockerfile --target prod` both build.

### §9 backend-gate defects (first full serial/parallel run surfaced 4 failures — Bug Fix Protocol)

The Increment-1–7 targeted filters never ran `Files`/`Onboarding` or the full parallel suite, so four
latent failures surfaced on the first Phase-19 full-suite run. All fixed at root cause, none by
weakening an assertion:

1. **`FileStorageBoundaryTest` (serial + parallel).** *Observed:* `AuditExportController.php` flagged
   as signing a private file outside the file domain. *Root cause:* `downloadLink` called
   `URL::temporarySignedRoute('audit-exports.download', …)` directly, violating the §65 storage-boundary
   guard (all private-file signing must be in `app/Domain/Files`); the finance precedent signs via
   `FileAccessService`. *Fix:* added `FileAccessService::signDownloadRoute($routeName, $params)` (keeps
   `temporarySignedRoute` in the file domain while preserving the ADR-010 custom stream route + stream-
   time accounting) and delegated to it; removed the direct call + the now-unused `URL` import.
   *Rerun:* boundary test green; `AuditExport` 31/31 (stream accounting intact).
2. **`FileMigrationManifestTest` (serial + parallel).** *Observed:* asserted exactly 2 Files-domain
   migrations; found 3. *Root cause:* Phase 19's `..._000003_add_audit_export_to_uploaded_files_purpose
   _check` is a legitimate later Files-domain ALTER (owner 19, non-destructive, ADR-010) that the
   Phase-10F test never anticipated. *Fix:* updated the test to recognise all 3 (each domain Files +
   files-and-media dd + non-destructive; owner ∈ {10F, 19}) plus a focused assertion that the two
   `expand` table-creations stay owned by 10F. *Rerun:* 3/3 green.
3. **`AuditExport*` (parallel only — 14 errors).** *Observed:* `Call to undefined function
   auditExportScenario()` across 5 of the 6 audit-export files under parallel workers. *Root cause:*
   the shared helpers (`auditExportScenario`/`requestAuditExport`/`runAuditExportJob`/`streamAuditExport`)
   were defined file-locally in `AuditExportWorkflowTest.php` — invisible to workers running the other
   files (the documented Phase-16B `createWalkIn` pattern). *Fix:* moved all four to `tests/Pest.php`
   (globally visible); removed the duplicates + unused imports from the Workflow file. *Rerun:* parallel
   `--group=audit` 117/117; serial `AuditExport` 31/31 (no redeclare).
4. **`MerchantSelfRegistrationTest > does not create a second merchant` (serial only).** *Observed:*
   `Merchant::count()` = 2, expected 1 — while the owner-user count was correctly 1 (dedup worked).
   *Root cause:* a globally-fragile assertion: an earlier serial test leaves a committed merchant row
   (a pre-existing test-isolation quirk exposed by Phase-19's changed test ordering); the product dedup
   is correct (proven by the isolated + parallel runs). *Fix:* asserted the DELTA (no *new* merchant on
   the duplicate registration) instead of a global count — a more precise test of the actual behaviour,
   order-independent, not a weakening. *Rerun:* isolated 6/6; **full serial 1062 passed / 7 skipped / 0
   failed**; **full parallel 1062 passed / 7 skipped / 0 failed**.

## Phase 19 increment roster (all local increments complete)

3. ✅ **DONE (Increment 3 above)** — Audit read APIs + masking + `audit.view_full` retirement.
4. ✅ **DONE (Increment 4 above)** — Audit export on the authorized `audit_exports` table (ADR-010);
   async/reason-gated/branch-scoped/masked/signed/expiring/download-counted (on the stream)/audited;
   31 tests green. REM-AUDEXP-001 → `in_progress` (→ `local_complete` with the phase).
5. ✅ **DONE (Increment 5 above)** — mutation→audit coverage registry + failing guard.
6. ✅ **DONE (Increment 6 above)** — `docs/auth/permission-matrix.yaml` (151 canonical + 17 legacy;
   87 active) + dependency-free loader + `servana:permission-types` TS generator; four-way parity
   (YAML↔PHP↔DB↔TS) + schema + completeness + per-key allow/deny + override/non-overridable +
   maker/checker + role-boundary + MFA/step-up coverage; `finance.audit.view` MFA + `audit.export`
   fresh step-up proven at the backend boundary. REM-PERM-001 → `local_complete` (pending PR).
7. ✅ **DONE (Increment 7 above)** — `audit:verify-chain` scheduled daily (withoutOverlapping +
   onOneServer) + bounded redacted `AuditChainVerificationFailed` signal (one per failing run);
   `AuditChainScheduleTest` + `AuditChainFailureSignalTest` + retained `AuditChainVerificationTest`.
8. ✅ **DONE (Increment 8 above)** — Audit-role frontend (event list/detail, flagged queue/review,
   finance/compensation reads, export list/detail) + Finance-role finance-audit screen + 3 stores +
   `AuditDomainEvents` + nav + §27.1 specs; Vitest 248; Playwright `audit.spec.ts` 25 + full e2e 252;
   axe serious/critical = 0 at 360/768/1280 (light + dark) + keyboard. DEF-19-002 (link contrast) fixed.
9. ✅ **DONE (Increment 9 above)** — full gate run: serial 1062 + parallel 1062 (0 fail; 4 §9 defects
   fixed at root cause), OpenAPI 167 ops + TS + permission-types + contract, Pint/Larastan clean,
   security (composer/npm/gitleaks) clean, Docker dev+prod build. **Phase 19 = `local_complete`.**

### Phase 19 exclusions (owner phases — no runtime events fabricated here)

future billing audit emissions → 20A/20B/20C/20D/20E; compensation audit emissions →
20F/20G/20H; notification/report audit emissions → 21N; SMS audit emissions → 21S; final
finance/audit export release hardening → 23; centralized alert transport/runbooks → 25;
search → 22; release-wide security/a11y audit → 23; performance → 24; deployment → 25.

### Residual risk / boundary note

`audit_flagged_events` is branch-owned (branch_id NOT NULL), so only branch-scoped audit
rows are flaggable — coherent with the branch-scoped Audit role (§10, §16 "Audit sees only
assigned-branch events"). Merchant-level / platform audit rows are intentionally outside
the Phase 19 flag workflow; documented in the data dictionary.

## Solo-Maintainer Review Exception - PR #32

- PR: #32
- verified implementation head: 46087feef55f42b55cc4b17a6e8e0c18b14db237
- initial successful CI run: 28736609390
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E - Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record: docs/governance/solo-maintainer-review-exception-pr-32.md

The exception applies only to Phase 19 and is not independent reviewer
approval. Future-domain audit emissions remain owned by their documented
future phases.

## Final merge record (verified 2026-07-07)

- PR #32 state: **MERGED**; merge commit `7ef259e28f51fc9bba24a16ef3945ff61ddef4ce`;
  merged at `2026-07-05T11:48:45Z`; head `phase-19-audit-flagged-events` → base `main`.
- Final PR head: `d6455f3ee85f2c8a2c541cc7f0d219eb81426f1a` (governance record commit).
- Final-head CI run `28736716360`: Backend / Frontend / Docker / Security / E2E — Playwright
  all COMPLETED + SUCCESS.
- `reviewDecision`: blank (solo-maintainer exception above; **not** independent approval).
- Local and remote `phase-19-audit-flagged-events` branches: deleted.
- Phase 19, REM-PERM-001, and REM-AUDEXP-001: **`verified_complete`**.
