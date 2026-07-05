# ADR-010 — Audit Export Persistence (`audit_exports`)

- **Status:** Accepted (Phase 19, 2026-07-04). Product-owner decision resolving
  `REM-AUDEXP-001`.
- **Required by:** Plan export-controls invariant (§ "Export controls: finance/**audit**
  exports are async, reason-gated, permission-masked, signed, expiring, download-counted,
  and audited"); Plan §19.2/§19.3 `audit.export` (branch scope, SU Y, high); §27.1 masked
  Audit-export screen; §13.5 (`audit_exports` DDL); §80 Phase 19 DB scope.
- **Related:** `docs/proof/phase-19.md` (Increment 4 capability matrix + decision);
  `docs/remediation/register.yaml` (REM-AUDEXP-001);
  `docs/architecture/data-dictionary/audit-files-notifications.md`; ADR-008 (audit
  immutability); the Finance-export lifecycle (`finance_exports`, Phase 18B) as the
  naming/behaviour precedent.

## Context

Phase 19 must deliver the Audit-export capability. A field-by-field capability matrix
(recorded in `docs/proof/phase-19.md`) proved that no existing persistence supports the
Plan-required export-REQUEST lifecycle:

- **`uploaded_files` (Phase 10F)** stores a file's scope/purpose/scan/lifecycle/retention,
  but has no `reason`, `scope_json`, `row_count`, `download_count`, `first/last_downloaded_at`,
  or a queued/processing/**failed** generation status. It is the file, not the request.
- **`finance_exports` (Phase 18B)** models exactly this request lifecycle, but it is
  Finance-owned end-to-end (its `export_type` CHECK excludes `audit`, plus a Finance CSV
  builder and `finance_export.*` events); reusing it would conflate domains.
- Deriving live download counts / request state from the immutable audit chain is not the
  canonical pattern (finance uses live columns) and is not Plan-authorized.

Adding columns to `uploaded_files`, generalising `finance_exports` into a shared table
(a contract migration on shipped Phase 18B behaviour), and deferring the build to Phase 23
(which would leave the Phase 19 `audit.export` permission and screen incomplete) were all
rejected as either domain-conflating or materially higher risk.

## Decision

1. **Add a dedicated, branch-scoped `audit_exports` table** (Plan §13.5 DDL) mirroring the
   `finance_exports` request lifecycle but Audit-owned: branch-owned (`branch_id NOT NULL`),
   `requested_by_user_id`, `reason`, `scope_json`, a backed `status` enum
   (`queued|processing|ready|failed|expired|revoked`) + DB CHECK, `file_id`→`uploaded_files`
   RESTRICT, `row_count`, `download_count`, `first/last_downloaded_at`, the lifecycle
   timestamps, and redacted failure fields. No soft/destructive delete.
2. **Do not** generalise `finance_exports`, add export columns to `uploaded_files`, or
   event-derive live state.
3. **Reuse the generic file/export machinery:** `FilePurpose::AuditExport`,
   `GeneratedFileWriter`, `FileAccessService`, `TenantAwareJob`, the `reports-exports` queue.
4. **Download accounting is on the authorized file STREAM, not link issuance** (this diverges
   deliberately from `finance_exports`, which counts at issuance): the signed stream route
   atomically increments `download_count`, sets `first_downloaded_at` once, updates
   `last_downloaded_at`, and emits `audit_export.downloaded`.
5. **Typed lifecycle events** follow the Finance naming convention:
   `audit_export.requested|generated|failed|downloaded|expired|revoked` (the earlier unused
   `audit.exported` catalogue entry is retired to avoid two events with duplicate meaning).
6. **Merchant-level (`branch_id` null) audit rows are never exported** (Phase 19 Q2).
7. **Phase 23** remains final release-wide export **hardening**, not the initial Audit-export
   build.

## Consequences

- `REM-AUDEXP-001`: `blocked` → `in_progress` (→ `local_complete` when Increment 4 is green).
- A new forward-only migration + expand of the `uploaded_files` purpose CHECK to add
  `audit_export` (expand/contract; no shipped migration edited).
- `audit.export` becomes an active canonical permission (Audit default; branch scope; SU Y;
  high; non-operational — Audit never mutates a source record).
- The Audit-export frontend screen (Increment 8) is unblocked.
