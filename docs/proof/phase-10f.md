# Phase 10F — File & Media Foundation Proof (REM-FILE-001)

**Branch:** `phase-10f-file-media-foundation` · **Base:** `4f761ff` (merged Phase 10, PR #21).
**Status:** `local_complete` — pending PR/CI/merge. REM-FILE-001 stays `local_complete`
until its PR merges with green CI.

## Objective

Establish one secure, private, reusable file domain before any feature may store,
generate, export or download business files (Plan §65, §73; REM-FILE-001).

## Phase 10 lifecycle correction (recorded)

Phase 10 (PR #21, merge commit `4f761ff`) is `verified_complete`: CI Backend /
Frontend / Docker / Security / **E2E — Playwright** all SUCCESS; reviewDecision
blank under the solo-maintainer governance exception
(`docs/governance/solo-maintainer-review-exception-pr-21.md`) — not an independent
approval. History preserved: the first Backend CI run failed on nondeterministic
OpenAPI generation (dedoc/scramble introspecting an un-migrated parallel-worker
schema); fixed in `a6b3e4c` without weakening stale-contract enforcement; the
subsequent complete five-job run passed. REM-ROUTE-001 and REM-MIG-001 →
`verified_complete`. Stale `local_complete`/`pending PR #21` wording removed from
PROGRESS/CHANGELOG/phase-10.md/register/traceability.

## Before-state baseline

- No `uploaded_files`/`file_scan_events` tables; no file routes/policies/audit events.
- Disks: `local` (private), `public`, `s3` (MinIO dev; bounded HTTP timeouts). No
  quarantine/final prefixes or file-domain service.
- ClamAV present as `--profile clamav` (port 3310), not wired to the app.
- Worker ran `--queue=mail,default` only (no `file-scanning`); scheduler had no tasks.
- Only `logo_path`/`profile_photo_path` string seams existed; no direct
  private-business-file `Storage::put`/`temporaryUrl` outside the new file domain
  (`FileStorageBoundaryTest` now enforces this).

## Schema (exact, forward-only)

`uploaded_files` and `file_scan_events` per Plan §13.13 — every field, the
11-value `purpose` CHECK, `scan_status`/`lifecycle_status` CHECKs, indexes
`(merchant_id,purpose,lifecycle_status)`,`(branch_id,purpose)`,`sha256`,
`(scan_status,created_at)`, the `available ⇒ clean + final_path` consistency CHECK,
`file_scan_events.result` CHECK, FK `uploaded_file_id` RESTRICT. **No
`download_count`** (belongs to `finance_exports`, Phase 18B/23). **No global
SHA-256 uniqueness** (avoids a cross-tenant existence side channel, §73). Both
applied cleanly on PostgreSQL; classified in `TenantOwnership` as cross-cutting
nullable-scope / inherited-scope; added to the migration manifest. Data dictionary:
`docs/architecture/data-dictionary/files-and-media.md` (authored before migrations).

## Purpose registry

`FilePurpose` (11) + `FilePurposeRegistry`/`FilePurposeDefinition` + `config/files.php`.
Active uploadable: `merchant_logo` (`merchant.profile.manage`), `profile_photo`
(`staff.edit`) — image-only, sanitised. Generated-only (client upload prohibited):
`finance_export`, `invoice_pdf`, `receipt_pdf`, `billing_invoice_pdf`,
`earnings_statement` (own-scope), `day_close_report`, `cash_up_report`;
`dispute_evidence`/`audit_evidence` enum-supported, no upload exposure. **Only
existing permission keys** are referenced (asserted against `PermissionRegistry`).

## Upload / scan / finalize

- `FileUploadPipeline`: authorise purpose+target → reject dangerous/spoofed BEFORE
  storage (zero-byte, oversize, unsupported MIME, extension↔MIME mismatch, MIME
  spoof, double-extension, executable) → stream to private quarantine → streaming
  SHA-256 + true byte count → server magic-byte MIME → pending/quarantined row →
  dispatch scan → 202 safe resource. Rejected uploads are never stored.
- `ClamAvScanner` (INSTREAM, bounded connect/read timeouts, version capture, safe
  result mapping, no raw payload) behind a `FileScanner` contract; `FakeFileScanner`
  for unit tests.
- `ScanUploadedFile` (file-scanning queue; idempotent; one `file_scan_events` per
  scan; clean/infected transitions; transient error → retry, then `scan_failed`
  in `failed()`; clean → dispatch finalize).
- `FinalizeCleanFile`: image purposes re-encoded via GD (metadata stripped,
  dimension/pixel limits, malformed rejected) → promote to private final prefix →
  verify existence+size → delete quarantine → `available` (only after verified
  final storage); retry-safe.

## Real ClamAV EICAR proof

`ClamAvEicarIntegrationTest` runs the REAL `ClamAvScanner` against the running clamd
(EICAR string built at runtime — no malware file in the repo): **3 passed** —
infected verdict with an `eicar` signature, clean verdict for benign bytes, safe
version capture.

```
docker compose --profile clamav up -d clamav   # healthy; PING→PONG from app container
php artisan test --filter=ClamAvEicarIntegrationTest → 3 passed
```

## Routes, download authorization, jobs

Routes (classified, ULID-bound): `POST /files` (tenant_mutation, throttle:file-upload,
StoreFileRequest), `GET /files/{uploadedFile}`, `POST /files/{uploadedFile}/download-link`
(tenant_mutation), `GET /files/{uploadedFile}/download` (`signed` + auth). Thin
`FileController`; `FileResource` (no paths/hash; `can.download`). `FileAccessService`
re-checks at BOTH link issuance and download: tenant (foreign → 404), owner own-scope
(foreign owner → 404), branch (out-of-branch → 403), purpose permission (→ 403),
`available`+`clean`+object exists. Download streams from private storage with
`X-Content-Type-Options: nosniff`, safe Content-Disposition, `Cache-Control: no-store`.
Jobs: `ScanUploadedFile`, `FinalizeCleanFile`, `ExpireSignedExport`,
`DeleteExpiredQuarantineFile`, `VerifyOrphanedFileRecords` (report-only). Scheduler
(hourly expiry/quarantine cleanup, daily orphan verify) + dedicated `file-scanning`
worker in dev and prod compose.

## Audit, redaction, storage boundary

Canonical `AuditEvent` cases added (upload accepted/rejected, scan clean/infected/
failed, available, downloaded, access denied, expired/deleted) with central
severities; recorded via `AuditRecorder`. `Redactor` extended with `signature`,
`sha256`, `quarantine_path`, `final_path`, `storage_disk`, `original_filename`,
`malware_payload`, `scanner_response`. `FileStorageBoundaryTest` source-scans `app/`
and fails on any Storage write/promote/sign outside `app/Domain/Files`.

### Deliberate storage-boundary violation demonstration

A temporary `app/Http/Controllers/BoundaryViolationDemo.php` with
`Storage::disk('s3')->put(...)` was added → `FileStorageBoundaryTest` **FAILED**
(`Http/Controllers/BoundaryViolationDemo.php` flagged). The file was removed →
the test **PASSED** again. The violation is not present in the repository.

## Billing read-only seam

`FileGenerationPolicy` (no billing tables): new billing-gated generation denied when
billing access is read-only; an already-available authorized file remains
downloadable (download path never consults the policy). Proven by
`FileBillingReadOnlySeamTest`.

## Frontend

`resources/spa/src/components/files/SvFileUpload.vue` — states
selecting/uploading/scanning/available/rejected/error; labelled input + size/type
guidance; `aria-live` status; 44px targets; light/dark; typed transport injected;
nothing persisted to localStorage. `useFileDownload` issues the signed link then
navigates (no storage of the signed URL). Vitest: **6 passed**.

## Contracts

`composer api:openapi` (run twice — byte-identical, deterministic): 47 production
routes incl. the 4 file routes. `npm run api:types` regenerated; `npm run
api:contract:check` → OK (41 paths / 47 operations). No test-only file route in the
production OpenAPI.

## Test & quality results

```
docker compose exec app php artisan test tests/Feature/Files (+ Isolation/Security file tests)
  → 52 passed (153 assertions)  [+ ClamAvEicarIntegrationTest 3 passed]
  FileSchema, FilePurposeRegistry, FileUploadValidation, FileScanPipeline,
  ClamAvEicarIntegration (REAL), FileDownloadAuthorization, FileSignedUrlExpiry,
  FileJobIdempotency, FileRetentionCleanup, FileStorageBoundary, FileTenantIsolation,
  FileLogRedaction, FileRouteSecurityContract, FileMigrationManifest, FileBillingReadOnlySeam
composer pint ........ clean (12 auto-fixed)
composer stan ........ <recorded at phase close>
npm vitest (files) ... 2 files / 6 passed (single-worker, isolated)
api:openapi x2 ....... deterministic; api:contract:check OK
```

### Consolidated gate run (phase close)

```
PASSED:
  composer validate --strict ........................ ./composer.json is valid
  composer pint -- --test ........................... clean (auto-fixed style; helpers moved to Pest.php)
  composer stan (Larastan level 8) .................. No errors
    (fixed: fread int<1,max>; fopen|false guard; migration raw-SQL concat → single literal)
  php artisan test (serial) ......................... 543 passed, 4 skipped (2306 assertions)
  php artisan test --parallel ....................... 543 passed, 4 skipped, 4 processes
  php artisan audit:verify-chain .................... OK (no chains on dev DB)
  composer api:openapi (x2) ......................... deterministic; 47 production routes (+4 file routes)
  npm run api:types / api:contract:check ............ OK — 41 paths / 47 operations
  npm run lint ...................................... 0 errors (35 pre-existing warnings)
  npm run typecheck (vue-tsc) ....................... clean
  npm run test (vitest, single-worker) .............. 19 files, 85 passed (+ SvFileUpload, useFileDownload)
  npm run build ..................................... built in 29.77s
  composer audit --locked ........................... No advisories
  npm audit --audit-level=high ...................... exit 0
  gitleaks detect --no-git --redact ................. no leaks (also clean as pre-commit hook)
  docker build php.Dockerfile --target dev .......... DONE
  docker build nginx.Dockerfile --target prod ....... DONE (incl. SPA build)
  ClamAvEicarIntegrationTest (REAL clamd) ........... 3 passed

FIXED then green (recorded, not erased):
  - Parallel suite: 8 file tests failed under --parallel because shared helpers
    (availableFile/quarantinedImage/pngBytes) lived in one test file used by others
    → undefined function in a worker that didn't load the defining file. Moved them
    to tests/Pest.php (always autoloaded) — same parallel-safety lesson as Phase 10's
    OpenAPI helpers. Rerun: 543 passed in parallel.
  - TenantBackfillMigrationTest (R5): failed because its fixed `migrate:rollback
    --step 3` no longer reached the R5 migrations once 10F appended two file
    migrations. Fixed to compute the rollback step dynamically (targets the R5
    migrations regardless of later additions); no assertion weakened. Rerun green.

ENVIRONMENT (not a code defect; authoritative gate is CI):
  npm run e2e (playwright) .......................... local Windows webServer startup
    timed out (120s) — the documented Windows Playwright flake (R5/R6/R7/Phase 10).
    10F adds NO e2e flow and NO routed UI; the SPA build passed. The authoritative
    Linux CI `E2E — Playwright` job runs on the PR (Phase 10 precedent, commit 46de7b3).
    No local e2e pass is claimed.
  (Docker Desktop's engine also crashed mid-run and was restarted; the image builds
   above completed after recovery.)
```

## Work skipped (exact owning phases)

```
role nav / landing                         -> Phase 11
service/client/personnel workflows         -> 15A/15B
appointments/queues/sessions               -> 16A-16C
invoice/receipt generation                 -> 17-18
finance_exports table + workflow           -> 18B/23
full file/export audit dashboard + flags   -> 19
billing state machine                      -> 20A/20B
M-Pesa files                               -> 20D
earnings/report generation                 -> 20H/21N
security-operations notifications          -> 21N/25
production infrastructure provisioning     -> 25
```

## Residual risks

- ClamAV must be reachable for the EICAR integration test; CI must run with the
  clamav service. The mocked `FakeFileScanner` covers pipeline logic but does not
  replace the real EICAR proof.
- The billing-read-only state is a seam (boolean) until Phases 20A/20B supply the
  real billing state machine and attach it.
- Image sanitisation uses GD; exotic formats beyond png/jpeg/webp are rejected by
  the MIME allowlist (intentional for the foundation).

## REM-FILE-001 status

`local_complete` — evidence above. Not `verified_complete` until the Phase 10F PR
merges with green CI and truthful governance evidence.

## Pull Request #22 Evidence

- Pull request: #22
- Backend CI: SUCCESS
- Frontend CI: SUCCESS
- Docker CI: SUCCESS
- Security CI: SUCCESS
- E2E — Playwright CI: SUCCESS
- Genuine ClamAV EICAR CI test: required and passed without skipping
- reviewDecision: intentionally blank
- Governance exception:
  docs/governance/solo-maintainer-review-exception-pr-22.md
- Status at this documentation commit: CI passed; pending merge
