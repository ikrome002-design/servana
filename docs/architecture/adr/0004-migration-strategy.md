# ADR-004 — Migration Strategy (Expand-and-Contract, Manifest, Forward-Repair)

- **Status:** Accepted (Phase 10 — API Foundation; REM-MIG-001).
- **Plan refs:** §8 (ADR-004), §11 (backend), §13 (database architecture, §13.1–§13.3),
  §75–§76 (testing/CI), §80 (Phase 10).
- **Date:** 2026-06-23.
- **Supersedes/relates:** ADR-002 (tenancy), ADR-003 (idempotency), ADR-008 (audit
  immutability) — each shipped forward-only migrations governed by this strategy.

## Context

Servana is a multi-tenant financial-grade SaaS. Schema changes must never lose
data, never require destructive production rollback, and must stay safe under
zero-downtime rolling deploys where the running application image and the database
schema overlap during a release. Phase 10 establishes the governing convention
that every later feature phase inherits; it introduces no new business table
(there is nothing to migrate here — only the rules and the manifest of what
already exists).

## Decision

### 1. Expand-and-contract (forward-only)

Every schema change is decomposed into ordered, individually-deployable steps:

1. **Expand** — add the new structure (table, nullable column, new index) without
   removing or tightening anything the current application relies on.
2. **Backfill** — populate new columns/rows in bounded, restartable batches; the
   backfill is idempotent and safe to re-run.
3. **Constrain** — only after backfill is verified, add NOT NULL / FK / UNIQUE /
   CHECK constraints.
4. **Contract** — in a *later* release, once no deployed code references the old
   structure, drop it.

A single release never both adds a column and makes it NOT NULL in a way that
breaks the previously-deployed image. **Shipped migrations are never edited**
(Guardrail 12); a correction is always a new forward migration.

### 2. Migration-before-application rollout order

The migration runs **before** the new application image receives traffic. Each
expand/backfill/constrain step is backward-compatible with the *currently
deployed* image, so at every instant the running code and the live schema are
compatible (the **schema compatibility window**). The new image is only the
exclusive reader/writer of new structure after the constrain step.

### 3. Schema compatibility window & image rollback

Because each step is backward-compatible, the previous application image can keep
running against the migrated schema. **Image rollback is therefore permitted only
while the schema is still compatible with the target image** (i.e. before a
contract step removes structure that image needs). A rollback that would cross a
contract boundary is not allowed — forward-repair is used instead.

### 4. Forward-repair instead of destructive rollback

Production schema problems are fixed by a **new forward migration** (repair), not
by reversing a shipped migration and not by ad-hoc SQL. `down()` methods exist for
local/CI convenience only and are never the production recovery path. Financial
and audit data are append-only and never destructively rolled back (Guardrails 5,
14; ADR-008).

### 5. Restoration boundary (PITR only)

The only sanctioned way to recover lost/corrupted data is a tested
point-in-time-recovery (PITR) restore of PostgreSQL to a pre-incident timestamp,
performed under the incident runbook (Plan §78) with before/after evidence. No
data repair through unreviewed SQL.

### 6. Data-dictionary-before-migration

Per §13.2, the complete, reviewed data-dictionary entry for a business table MUST
exist before its migration is authored. The migration manifest
(`docs/architecture/migrations/manifest.yaml`) records the data-dictionary
reference for every Servana migration; `MigrationManifestTest` fails when a
business migration has no reference. (Four pre-Phase-10 tables — `audit_logs`,
`permissions`, `roles`, `role_permission_assignments` — predate the dictionary
files and carry a domain reference plus a tracked gap note for their full per-table
entries; see the manifest. No new migration may rely on a missing entry.)

### 7. PostgreSQL-only verification

Migrations and migration tests run on **PostgreSQL 16** (service container), never
SQLite (§13.3): partial indexes, exclusion constraints, JSONB, advisory locks and
triggers are PostgreSQL-specific. The expand→backfill→constrain sequence and the
zero-null backfill result are proven on PostgreSQL in each owning phase's proof.

## The migration manifest

`docs/architecture/migrations/manifest.yaml` is the version-controlled inventory of
**every** migration, separating framework/package migrations from Servana business
migrations. Each Servana entry records: filename, phase/domain owner, change type
(`expand` | `backfill` | `constrain` | `contract` | `framework`), data-dictionary
reference, dependencies/order, production compatibility, forward-repair path,
whether it performs a destructive operation, and its verification test/proof.
`MigrationManifestTest` lints the manifest against the migrations on disk.

## Consequences

- **Positive:** zero-downtime deploys; no destructive production rollback;
  auditable change inventory; every migration traceable to a dictionary entry,
  owner phase and verification.
- **Negative:** multi-step changes span releases (more migrations, more
  discipline); contract steps must wait for old images to drain.
- **Enforced by:** `MigrationManifestTest` (manifest lint), `TenantColumnCoverageTest`
  (§13.3 ownership), and the PostgreSQL-only test database.
