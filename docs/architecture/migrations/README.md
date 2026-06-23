# Migration Manifest & Governance

Authoritative inventory and governance for every database migration in Servana.
Governed by [ADR-004](../adr/0004-migration-strategy.md) (expand-and-contract,
forward-repair, image rollback only within the schema compatibility window, PITR
restoration, data-dictionary-before-migration). Plan refs: §8, §13.1–§13.3, §80.

## Files

- [`manifest.yaml`](manifest.yaml) — the inventory: framework/package migrations vs
  Servana business migrations, with per-migration governance metadata.
- `MigrationManifestTest` (`tests/Feature/Infrastructure/MigrationManifestTest.php`)
  — the lint that keeps the manifest honest against `database/migrations/`.

## Change-type vocabulary

| Type | Meaning |
|---|---|
| `framework` | Laravel/package-provided migration (users/cache/jobs, Sanctum tokens). Not a Servana business table. |
| `expand` | Additive: create a table, add a nullable column/index. Backward-compatible. |
| `backfill` | Populate new columns/rows in bounded, restartable, idempotent batches. |
| `constrain` | Tighten after backfill: NOT NULL, FK, UNIQUE, CHECK. |
| `contract` | Remove structure no deployed code references (a later release). Destructive. |

R5's `add_merchant_id_*` migrations perform expand→backfill→constrain **inline**
within a single forward-only migration (recorded as `expand` with a backfill/
constrain note) — valid because no production deployment exists and `migrate:fresh`
rebuilds cleanly; future production-time tenant columns must split the steps.

## Each Servana entry records

`file` · `table(s)` · `domain` · `owner_phase` · `change_type` ·
`data_dictionary` (reference) · `depends_on` (ordering) · `production_compatible` ·
`destructive` · `forward_repair` · `verification` (test/proof) · optional `notes`.

## Lint rules (`MigrationManifestTest`)

The test fails when:

1. a Servana migration on disk is missing from the manifest;
2. a manifest entry references a migration file that does not exist;
3. a business migration lacks a data-dictionary reference (or it points to a
   non-existent file);
4. a destructive change lacks a forward-repair/contract plan;
5. a `depends_on` entry references a migration not in the manifest;
6. duplicate entries (same file listed twice).

## Data-dictionary gap (tracked)

`audit_logs`, `permissions`, `roles`, `role_permission_assignments` predate the
data-dictionary files (`docs/architecture/data-dictionary/`). They carry a domain
reference (`core-identity-and-tenancy.md`) and a `notes` gap marker; their full
per-table dictionary entries are owed to Phase 19 (audit/governance). No new
migration may be authored against a missing dictionary entry (ADR-004 §6).
