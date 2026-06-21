# ADR-008 — Audit Immutability and Hash Chain

- **Status:** Accepted (R2, 2026-06-21). Extends the Phase 8 substrate; closes the
  core of REM-AUD-001.
- **Required by:** Plan §70 (Audit Logging and Chain Verification), §8 ADR-008,
  §79 Phase R2; Scope §4.8 (Audit account).
- **Related:** `docs/proof/phase-r2.md`; `docs/remediation/register.yaml` (REM-AUD-001);
  Phase 19 completes full event coverage + flagged-event workflow.

## Context

`audit_logs` shipped in Phase 8 with hash columns and an append-only trigger, but
event coverage was partial (auth still went to a log-only `AuthEventLogger`),
there was no chain verifier, no masked read API, and no per-merchant chain
separation. R2 completes the **core** audit controls for the domains already
implemented (auth, invitations, membership/staff lifecycle, branch lifecycle,
branch assignment, permission overrides, unauthorized access). Financial,
billing, M-Pesa, compensation, SMS, file, and export events — and the
flagged-event review workflow — are explicitly Phase 18/19/20/21S/10F.

## Decision

1. **Append-only at the database.** A PostgreSQL trigger
   (`audit_logs_block_mutation`) raises on every UPDATE and DELETE. Rows are
   written only by `DatabaseAuditRecorder`. (Unchanged from Phase 8; re-asserted
   by `AuditImmutabilityTest`.)

2. **Per-merchant + platform hash chains.** Each row links to the previous row's
   hash **within the same chain**: one chain per `merchant_id`, plus a platform
   chain for `merchant_id IS NULL`. This makes chains independent — one tenant's
   volume or tampering cannot affect another tenant's verification. (Phase 8
   linked a single global chain; R2 corrects this to the Plan's per-merchant
   model. No production data existed, so no historical rows were affected.)

3. **Canonical serialization.** `AuditChainHasher` is the single source of truth
   for the hash: SHA-256 over `{previous_hash, ulid, merchant_id, branch_id,
   actor_id, action, severity, auditable_type, auditable_id, context,
   created_at}`. Read-time-mutable fields (`actor_label`, `ip_address`,
   `correlation_id`) are **excluded** so masking them at read never invalidates
   the chain. The recorder and the verifier both use this one class.

4. **Concurrency.** Appends serialize per chain with a transaction-scoped Postgres
   advisory lock keyed on the merchant id (0 = platform), then a `lockForUpdate`
   on the chain tail. The advisory lock also closes the first-row race a row lock
   cannot cover. Different merchants never contend (better throughput than a
   global lock).

5. **Verifier command.** `php artisan audit:verify-chain` walks each chain in
   insertion order, recomputes every hash, and checks both the stored hash and
   the `previous_hash` linkage. It mutates nothing, exits 0 when all chains
   verify and non-zero otherwise, and prints only safe chain ids + the failing
   record ULID. `--merchant=` / `--platform` bound the scope. Scheduling + alerts
   on failure are Phase 25 (Section 71).

6. **Read-time masking.** `AuditValueMasker` recursively masks `context` and the
   actor email server-side in `AuditLogResource`. Secrets are never stored in the
   first place (auth records a masked email only, with a null actor, for
   enumeration resistance); the masker is defense-in-depth for accurate-but-
   sensitive values (emails, phones, references). No endpoint can request
   unmasked data; exceptional, reason-gated unmasking is Phase 19.

7. **Authorization (tenant / branch / platform).** `AuditLogPolicy` is read-only
   (create/update/delete always false). Merchant rows are visible to
   `audit.view_full` holders in the same merchant; a branch-scoped viewer (the
   Audit role) sees only its assigned branch(es). Platform rows are visible only
   to platform staff with `platform.audit.view`. The merchant endpoint sits
   behind `EnsureMerchantActive` + `audit.view_full`; the platform endpoint is
   separate and platform-only. Foreign-tenant ULIDs 404 (no existence leak).

## Failure behavior

A verification failure is surfaced by a non-zero exit and a safe message naming
the chain and the first failing record ULID; the command stops walking a chain at
its first break (everything past a break is untrustworthy). It never repairs or
mutates audit data — remediation of a detected tamper is an incident-response
action (Section 78), never an ad-hoc UPDATE.

## Retention implications

Append-only + hash chaining means audit rows are never edited or pruned in place;
retention/archival (if ever required) must be an append-only, chain-preserving
export, not a delete. Out of scope for R2.

## Rollout and forward repair

`branch_id` was added by a forward-only expand migration
(`2026_06_21_000001_add_branch_id_to_audit_logs`); it is nullable with
`nullOnDelete` and participates in the hash for rows written after it. No
destructive `down()` is used as a production rollback (Plan A-08); a schema
correction is forward-repaired. Image rollback is safe within schema
compatibility.

## Limitations / follow-up

- Full event coverage across financial/billing/compensation/M-Pesa/SMS/file/
  export domains, the flagged-event review workflow (`audit_flagged_events`), and
  exceptional reason-gated unmasking are **Phase 19**.
- Scheduled chain verification + alerting are **Phase 25** (Section 71).
- Audit exports / signed downloads are **Phase 19/23**.
- A frontend audit dashboard is **Phase 11/19**.

## Consequences

- **Positive:** every implemented mutating transition now emits a typed,
  severity-tagged, tamper-evident audit row; chains are independently verifiable;
  reads are masked and correctly scoped; the Audit role is provably read-only.
- **Cost:** an advisory lock per append serializes same-merchant writers (cheap;
  cross-merchant writers are unaffected).
- **Neutral:** adding a new audited event = add an `AuditEvent` case (+severity)
  and record it in the owning transition; no recorder/verifier change needed.
