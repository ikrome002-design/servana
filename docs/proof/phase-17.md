# Phase 17 — Invoicing — Proof

**Branch:** `phase-17-invoicing` · **Base commit:** `ffe37cc` (verified Phase 16C
merge, PR #28). **Status:** `local_complete` (in progress) — this file records the
controlling decisions, the six specification-gate resolutions, and the gate evidence
as each slice is verified. This is **not** `ci_passed`, `merged`, or
`verified_complete`: CI is authoritative for the Linux browser/Docker/gitleaks gates;
local Windows Playwright is not claimed as a pass (Phase 15B/16A/16B/16C precedent).
Tests run against PostgreSQL 16 (never SQLite).

Money is integer minor units (`Money` value object) — never float. Times are branch
business time in `Africa/Nairobi`; timestamps UTC. Frontend visibility is UX only —
the API (policies + `EnsureBranchScope` + `EnsurePermission` + the invoice state
machine + billing/period-lock/idempotency gates) is the security boundary.

---

## Git integrity & environment (verified at start)

- `git fetch --prune`; branch `main` at `ffe37cc`, `origin/main` at `ffe37cc`,
  `0 0` divergence, working tree clean, `git fsck --full` clean. Damaged repo
  `…/Servana-damaged-git-2026-06-30` exists but is **not** used (working repo is
  `…/Servana`).
- New branch `phase-17-invoicing` created off `ffe37cc`; merge-base `ffe37cc`.
- Runtime: Docker 29.5.3 up; PHP 8.3.31; Laravel 12.62.0; PostgreSQL 16; Redis 7;
  migrations through Phase 16C applied.

## Phase 16C lifecycle reconciliation (done first)

Verified via GitHub: PR **#28** MERGED, mergeCommit `ffe37cc`, reviewDecision blank
(solo-maintainer governance exception). Final CI run `28449140384` (head `79746bb`)
SUCCESS; corrected CI run `28448569188` (head `ac5751a`) SUCCESS. Reconciled
`docs/PROGRESS.md`, `docs/CHANGELOG.md`, `docs/proof/phase-16c.md`,
`docs/traceability/servana-requirements.csv` (`SRV-SESSION-001` → `verified_complete`)
and `docs/remediation/register.yaml` `last_updated`. Both failed E2E runs
(`28445709595`, `28446579933`), both root causes (ambiguous `My sessions` text
locators; Personnel read-only assertion counting every page button), and both
remediation commits (`81506da`, `ac5751a`) are preserved. No new remediation item was
created (ordinary feature-phase Playwright locator corrections, no backend/
business-rule/accessibility-gate relaxation). `REM-PERM-001` stays open (Phase 19).

---

## Specification-gate resolutions (controlling sources)

Full reasoning + DDL in
[docs/architecture/data-dictionary/invoicing.md](../architecture/data-dictionary/invoicing.md)
and the machine in
[docs/architecture/state-machines/invoice.md](../architecture/state-machines/invoice.md).

- **Gate A — completed-session source → RESOLVED.** `invoice_items.service_session_id`
  NOT NULL, composite FK `(service_session_id, merchant_id) → service_sessions(id,
  merchant_id)` (the target the 16C migration prepared). Only `completed` sessions are
  invoiceable; provenance derived under lock, never from the browser. One invoice may
  contain multiple completed sessions (one item each; same merchant/branch/client/
  currency). `UNIQUE (service_session_id)` prevents duplicate invoicing; re-invoicing a
  voided session is a documented correction workflow, deferred (no destructive rewrite).
- **Gate B — void/adjust representation → RESOLVED.** No new table: additive
  `invoices` columns (`previous_status`, `voided_at/by`, `void_reason`,
  `adjusted_at/by`, `adjustment_reason`, `adjustment_of_invoice_id` self-FK). Original
  snapshots + number never mutated; no row deleted; before/after recorded in audit.
  `paid → refund_pending|adjustment_required` defined + unit-tested but Phase-18B-driven
  (no `paid` state reachable in Phase 17).
- **Gate C — period-lock seam → RESOLVED.** New `FinancialPeriodGuard` +
  `PeriodLockRepository` contract; Phase 17 binds `UnlockedPeriodLockRepository`
  (no `financial_period_locks` table yet) but wires the guard into every financial
  mutation; tests prove `423 financial_period_locked` when a repository reports a lock.
  Phase 18B swaps the persistence with no action change. No lock table/endpoint/UI here.
- **Gate D — preferred-personnel fee → RESOLVED.** `PreferredPersonnelFeeResolver`:
  honoured ⇒ legacy `services.preferred_personnel_fee_minor`; not honoured/null ⇒ no
  fee; snapshot immutable; resolver replaceable by Phase 20A. Never derived from the
  commission preview; no rules table created.
- **Gate E — percentage-fee snapshot → RESOLVED.** `percentage_fee_config_snapshot`
  jsonb = `null` (typed "not configured") until Phase 20E. No mode/rate/tier/ledger.
- **Gate F — tax/discount → RESOLVED.** `tax_minor`/`discount_minor` retained, integer,
  default 0, non-negative CHECK, no unauthorized editable control; deferred.

---

## Implementation evidence (accumulated per slice)

> Filled as each slice is verified on PostgreSQL 16. Sections: Schema · State machine ·
> Draft · Finalization · Numbering/concurrency · Idempotency · Preferred fee · Totals ·
> Void/adjust · Authorization/isolation · Billing/period-lock · Audit · Frontend ·
> Contracts & security · Quality gates · Environmental limitations.

### Schema (PostgreSQL 16) — VERIFIED

`php artisan migrate:fresh` and `migrate:fresh --seed` apply all three Phase 17
migrations cleanly on PostgreSQL 16 (`2026_06_30_000002/3/4`). Coverage suite green
(`TenantColumnCoverageTest`, `ModelTenancyTraitCoverageTest`, `MigrationManifestTest`,
`DataDictionaryCoverageTest`-via-manifest, `TenancyStaticAnalysisTest`):
**20 passed (185 assertions)**. Registrations: `invoices`/`invoice_items` →
BRANCH_OWNED + COMPOSITE_CONSISTENCY + MODELS(`branch`); `invoice_number_sequences` →
TENANT_OWNED + MODELS(`tenant`); all three in `manifest.yaml` with data-dictionary
references. No undocumented table; composite-merchant FKs enforced; `UNIQUE
(id,merchant_id)` present on `invoices` (FK target + self-FK); raw-SQL guard + no
unscoped `Model::find` confirmed by the static-analysis test.

### Backend tests (PostgreSQL 16) — VERIFIED (domain core + finalization)

- `tests/Unit/Invoicing/InvoiceStateMachineTest` + `InvoiceTotalsCalculatorTest`:
  **40 passed (73 assertions)** — every canonical valid transition, every invalid
  pair (→ `invalid_state_transition`), terminal states, the nine-state DB-CHECK
  mirror, integer-minor-unit totals, preferred-fee null-vs-zero distinction, total
  arithmetic coherence.
- `tests/Feature/Invoicing/FinalizeInvoiceTest`: **9 passed (25 assertions)** —
  draft has no number; finalization allocates `KIL-INV-000001` and transitions
  draft → issued with snapshots; a later service-price change does NOT recalculate
  the issued invoice (immutable snapshot); legacy preferred fee snapshotted only when
  honoured (else none); gap-free sequential numbers across finalizations
  (`…000001`, `…000002`); non-completed source rejected; duplicate session invoicing
  blocked; double finalization rejected (`invalid_state_transition`); a rolled-back
  finalization consumes no number (next finalization still gets `…000001`).
- Pint clean and Larastan **level 8 — No errors** on `app/Domain/Invoicing` +
  `app/Domain/FinanceOps`.

### HTTP layer & permissions — VERIFIED

- Routes (`route:list`): `GET/POST /api/v1/invoices`, `GET/PATCH /api/v1/invoices/
  {invoice}`, `POST .../finalize` (financial_mutation + idempotency), `POST .../void`,
  `.../void/execute`, `.../void/reject`, `.../adjust`. No DELETE / status / mark-paid /
  payment / receipt route exists (asserted in `InvoiceApiTest`).
- `InvoicePolicy` (view/create/finalize/void/adjust) + Form Requests (allowlisted
  bodies; no authoritative fields accepted) + thin controllers + masked
  `InvoiceResource`/`InvoiceItemResource` (ULIDs only; money as {amount,currency,
  formatted}; state-aware `can` map).
- `RouteSecurityContractTest` + `FinancialRouteIdempotencyCoverageTest`: **13 passed**
  (finalize carries idempotency; bodiless mutations recorded in VALIDATION_EXEMPT).
- **Permission reconciliation:** `PermissionMatrixTest` **3 passed** after replacing the
  legacy placeholder `invoices.*` keys (which mis-granted invoice creation to Branch
  Manager + Merchant Admin) with the canonical `invoice.view`/`invoice.create`/
  `invoice.void.request_or_execute_as_policy`/`invoice.adjustment.manage`. `phase8-matrix
  .txt` regenerated. `FilePurposeRegistry` invoice_pdf permission → `invoice.view`.
- `InvoiceApiTest`: **15 passed** — FO create draft (masked client); finalize requires
  `Idempotency-Key` (422 without) and replays safely (no second number/item; sequence
  next_value advances once); key-reuse rejected (409); role denials (Branch Manager,
  Merchant Admin, HR, Personnel, Audit → 403); FO denied void/adjust; Finance void
  request→execute additive/non-destructive (number retained, total unchanged, nothing
  deleted); reason required; `423 financial_period_locked` under a locked-period
  repository; foreign-tenant ULID → 404; no destructive/payment routes.

### Frontend — VERIFIED (Linux CI authoritative for browser)

- Screens `pages/invoicing/InvoiceList.vue`, `InvoiceCreate.vue`, `InvoiceDetail.vue`
  (shared by Front Office + Finance; capability-map-gated finalize/void/adjust);
  `invoiceStore` (idempotent finalize via client-generated `Idempotency-Key`); Front
  Office + Finance routes; navigation `front-office.invoices`/`finance.invoices`
  activated (live); get-started `Create an invoice` deep-linked to
  `front-office.invoices.create`.
- `vue-tsc --noEmit` clean; ESLint **0 errors**; Vitest **191 passed** (+ invoice util
  2, InvoiceDetail 6). Screen inventory + role-navigation YAML regenerated (snapshot
  tests green); 5 new inventory entries + 3 planned→implemented reconciled.
- Playwright `tests/e2e/invoice.spec.ts` (FO create→finalize number-timing, issued
  read-only, no payment/receipt control, Finance void/adjust controls + irreversible
  warning, 360/768/1280 no-overflow, 200% zoom, light+dark axe serious/critical=0) —
  Linux CI authoritative; local Windows Playwright not claimed.

### Contracts & security — VERIFIED

- OpenAPI regenerated (`servana:openapi`, **105 production routes**) and byte-identical
  on a second run (deterministic). TypeScript contract regenerated (`npm run api:types`);
  `OpenApiContractTest` + `OpenApiTypeParityTest` **14 passed**.

### Full suite + gates — VERIFIED

- **Full backend suite (non-parallel, PostgreSQL 16): 892 passed / 7 skipped / 0
  failed.** The 7 skips are the ClamAV-profile file-scanner tests (clamd not started
  locally). Pint clean (684 files); Larastan level 8 **No errors** (501 files);
  `composer validate --strict` OK; `npm audit --audit-level=high` 0 high/critical (2
  moderate); migrate:fresh --seed green.
- **Two obsolete forward-looking lifecycle assertions updated (not weakened):**
  `ServiceSessionCouplingTest` and `QueueApiTest` asserted `Schema::hasTable('invoices')
  ->toBeFalse()` ("Phase 17 hasn't created the table yet"). Phase 17 now owns the table,
  so the assertion was replaced with the ROW-level invariant `DB::table('invoices')
  ->count() === 0` — completing a queue entry / service session still creates NO invoice
  (invoicing is a separate Front Office action). This strengthens the invariant (asserts
  no auto-created row, the real business rule) rather than concealing a defect; it mirrors
  the 16B→16C precedent where the 16B lifecycle test was updated for the 16C coupling.
- **Docker images, gitleaks, and the browser (Playwright) gate remain Linux-CI
  authoritative** — local Windows Playwright is not claimed as a pass (Phase
  15B/16A/16B/16C precedent).

### Lifecycle
`Phase 17: local_complete` · `Phase 18A: Not started`. This is **not** `ci_passed`,
`merged`, or `verified_complete` — no PR/CI has run; CI is authoritative for the Linux
browser/Docker/gitleaks gates.

### Local environmental limitations
Docker Desktop available; full stack healthy. Linux CI remains authoritative for
browser/Docker/gitleaks gates; local Windows Playwright is not claimed as a pass.

## Solo-Maintainer Review Exception - PR #29

An independent reviewer was unavailable because the repository currently has
one eligible maintainer. The product owner authorized a PR-specific governance
exception instead of fabricating approval.

Evidence:

- PR: #29
- verified implementation head: c0fdd83ea539f1ccdaf9232ef9a1b8b5a027d45e
- initial successful CI run: 28516753439
- CI/Backend: passed
- CI/Frontend: passed
- CI/Docker: passed
- CI/Security: passed
- CI/E2E - Playwright: passed
- GitHub reviewDecision: intentionally blank
- governance record:
  docs/governance/solo-maintainer-review-exception-pr-29.md

Financial-integrity boundaries remain unchanged:

- no payment recording or validation subsystem
- no receipt or refund subsystem
- no commission ledger
- no fabricated percentage platform-fee configuration
- no destructive rewriting of finalized invoice snapshots
- no invoice-number reuse

This exception applies only to PR #29 and is not independent reviewer approval.
