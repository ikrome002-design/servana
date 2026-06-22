# Phase R4 — Idempotency & Replay Protection — Proof of Resolution

**Requirement:** REM-IDEMP-001 (C0, PRE_FEATURE) · Plan §4 finding 9, §8 ADR-003,
§9.4, §13.5, §24.1–§24.4, §79 R4.
**Branch:** `phase-r4-idempotency-replay-protection` · **Base:** merged `main`
`c0402b2` (PR #15, R3). **Date:** 2026-06-22.

No raw keys, stored encrypted payloads, tokens, cookies, or secrets appear in this
document; the implementation never stores them and the tests assert their absence.

---

## 1. Prove the problem (before-state)

Captured on this branch before any code change:

```
idempotency_keys table .................... (absent)  \dt -> "Did not find any relation"
Idempotency-Key middleware ................ (none)    grep app,routes -> 0
canonical request hasher .................. (none)
encrypted response replay ................. (none)
financial-route coverage test ............ (none)
expired-lock recovery ..................... (none)
provider dedupe seam ...................... (none)
```

Plan §4 finding 9 / §4.1 row 9: the prior `idempotency_keys` definition (no
`key_hash`, unique on `(merchant_id, key_hash)`) is **contradicted — table absent
entirely**. So a clean, corrected forward-only create (Correction 3.2).

---

## 2. Schema, index & constraint evidence (`\d` from PostgreSQL 16)

```
                      Table "public.idempotency_keys"
        Column          |   Type     | Nullable | Notes
------------------------+------------+----------+------------------------------
 id                     | bigint     | not null | identity PK
 ulid                   | char(26)   | not null | unique
 idempotency_scope      | varchar(191)| not null | part of unique key
 key_hash               | char(64)   | not null | SHA-256(raw key) — never raw
 actor_user_id          | bigint     |          | FK users ON DELETE SET NULL
 merchant_id            | bigint     |          | FK merchants ON DELETE RESTRICT
 branch_id              | bigint     |          | FK merchant_branches ON DELETE RESTRICT
 route_name             | varchar(191)| not null |
 http_method            | varchar(10)| not null |
 request_content_type   | varchar(100)|          |
 request_hash           | char(64)   | not null | canonical request hash
 state                  | varchar(20)| not null | CHECK in processing/completed/failed
 response_status        | smallint   |          |
 response_headers       | jsonb      |          | allowlisted headers only
 response_body_encrypted| text       |          | encrypted at rest
 locked_at              | timestamp  | not null |
 lock_expires_at        | timestamp  | not null | active while > now()
 completed_at           | timestamp  |          |
 failed_at              | timestamp  |          |
 last_error_code        | varchar(100)|          | redacted code only
 expires_at             | timestamp  | not null | prune horizon
 created_at/updated_at  | timestamp  |          |
Indexes:
  idempotency_keys_pkey PRIMARY KEY (id)
  idempotency_keys_ulid_unique UNIQUE (ulid)
  idempotency_keys_idempotency_scope_key_hash_unique UNIQUE (idempotency_scope, key_hash)
  idempotency_keys_state_lock_expires_at_index (state, lock_expires_at)
  idempotency_keys_expires_at_index (expires_at)
Check constraints:
  idempotency_keys_state_check CHECK (state IN ('processing','completed','failed'))
Foreign-key constraints:
  actor_user_id -> users(id) ON DELETE SET NULL
  branch_id     -> merchant_branches(id) ON DELETE RESTRICT
  merchant_id   -> merchants(id) ON DELETE RESTRICT
```

Matches Plan §13.5 exactly. Forward-only migration `2026_06_22_000003`; no shipped
migration edited; no backfill. Data-dictionary entry authored before the migration
(Plan §13.2): `docs/architecture/data-dictionary/core-identity-and-tenancy.md`.
Asserted by `IdempotencySchemaTest` (columns, unique key, indexes, state CHECK,
duplicate rejection).

---

## 3. Hash & encryption-at-rest evidence

- **Raw key never stored; key is SHA-256** — `ReplayResponseSecurityTest::it
  stores only the SHA-256 of the key, never the raw key`: `key_hash ===
  hash('sha256', $rawKey)` and a full-table JSON dump does not contain the raw key.
- **Response body encrypted at rest** — `...it encrypts the response body at rest`:
  the raw `response_body_encrypted` column does not contain the plaintext payload
  (`count`), while the model's `encrypted:array` cast decrypts to `['count' => 1]`.

---

## 4. Scope examples (deterministic, collision-free)

`IdempotencyScopeResolver`:

```
merchant:{merchant-ulid}:user:{user-ulid}   (merchant-scoped request)
platform:user:{user-ulid}                    (platform staff)
webhook:{provider}:{environment}             (provider callback seam)
```

`IdempotentReplayTest::it does not collide across different actors or merchants`:
the same raw key used by two different members yields two scopes and two
independent effects (no replay). The scope is part of the unique key.

---

## 5. Canonical request hashing

`CanonicalRequestHashTest` (8 cases): equivalent JSON with different key ordering
hashes identically; different method / route / path param / body / content type
hash differently; content-type parameters (`charset`) are ignored; list ordering
is significant.

---

## 6. Same-request replay & different-request conflict

- **Replay (one effect)** — `IdempotentReplayTest::it executes once and replays the
  stored response on a duplicate`: first POST → `count: 1`, no replay header;
  duplicate POST (same key+request) → `count: 1` again with `Idempotent-Replay:
  true`; `Cache` effect == 1; exactly one `idempotency_keys` row.
- **Stable 4xx replay** — `...it replays a stable 4xx deterministically`: a 422 is
  stored `completed` and replayed verbatim with `Idempotent-Replay: true`.
- **Conflict** — `IdempotencyConflictTest`: same key + different body → `409
  idempotency_key_reused_with_different_request`; same key + different route → 409;
  the original effect is never re-run (`Cache` effect stays 1).
- **Missing/malformed key** — `IdempotentReplayTest`: no header → `422
  idempotency_key_required`; too short → `422 invalid_idempotency_key`.

---

## 7. Concurrency & crash recovery (PostgreSQL is the boundary)

`IdempotencyConcurrencyTest`:
- two same-key claims → exactly one `Claimed`, the other `InProgress`
  (`Retry-After > 0`), exactly one row;
- two `INSERT ... ON CONFLICT DO NOTHING` of the same `(scope, key_hash)` → exactly
  one wins (`1 + 0`), proving the unique index is the boundary;
- after `complete()`, a same-request claim returns `Replay` (no re-execution);
- same key + different request hash → `ConflictDifferent`.

`ExpiredLockRecoveryTest`:
- an expired processing lock is reclaimed (`Claimed`, new future lock); a second
  immediate claim then sees an active lock (`InProgress`) — only one recoverer wins
  (reclaim runs under `SELECT … FOR UPDATE`);
- an active lock is never stolen (`InProgress`);
- a `failed` (server-error) row is reclaimed and retried (`Claimed`, code cleared).

> Limitation (ADR-003): a crash after the effect but before completion re-executes
> on recovery; exactly-once across a crash additionally relies on the owning
> phase's ledger-level unique constraints. True OS-parallel contention is enforced
> by the unique constraint + `FOR UPDATE`; the harness exercises them
> deterministically without forking processes.

---

## 8. Failure / retry rules

- 2xx and deterministic 4xx → `completed`, replayed verbatim.
- 5xx / transient 4xx (408/425/429) → `failed` with a redacted code, retryable.
- `ReplayResponseSecurityTest::it stores no sensitive detail on a server failure`:
  the `boom` route throws; the row is `failed`, `last_error_code = 'server_error'`,
  `response_body_encrypted` is null, and neither the DB dump nor the 500 response
  contains the exception's detail message.

---

## 9. Unsafe-header / redaction proof

`ReplayResponseSanitizer` allowlists only `content-type`. `ReplayResponseSecurityTest::
it never stores or replays unsafe headers`: the route emits `Set-Cookie`,
`Authorization`, `X-XSRF-TOKEN`, `Server`; none of the forbidden header names appear
in the stored `response_headers`, and the replayed response contains none of the
secret values (`secretcookievalue` / `secret-token` / `csrf-secret`). The replay is
tagged `Idempotent-Replay: true` and exposes no key hash or row id. (The framework's
own session `Set-Cookie` on a stateful response is unrelated to the stored row.)

---

## 10. Prune result

`IdempotencyPruneTest`: prunes expired completed rows, keeps unexpired ones; never
prunes an active processing lock even past `expires_at`; prunes an expired/abandoned
processing row; respects the `--batch` bound. Manual transcript:

```
$ artisan idempotency:prune   (after seeding one expired completed row)
Pruned 1 expired idempotency record(s).
```

Scheduled daily (`routes/console.php`, `Schedule::command('idempotency:prune')
->daily()->withoutOverlapping()`). Config in `.env.example`.

---

## 11. Provider dedupe seam result

`ProviderCallbackDedupeSeamTest` (generic; no M-Pesa): first callback → `First`;
replay (same provider/env/correlation/payload) → `Duplicate`; different
provider/environment → no collision (all `First`); same correlation + different
payload → `PayloadMismatch`. `ProviderReplayGuard` reuses the store with a
`webhook:{provider}:{environment}` scope. Phase 20D attaches it to real provider
correlation ids + the callback inbox.

---

## 12. Coverage-test result

`FinancialRouteIdempotencyCoverageTest`:
- every registered `financial_mutation` route carries the idempotency middleware
  (the testing harness `testing/idempotency/*` routes are classified
  `financial_mutation` and protected);
- a synthetic unprotected financial route is **detected** (returned in the missing
  list), and a corrected one **passes** — proving the guard works both ways.

No production financial routes exist yet (truthfully empty); the harness is
`testing`-environment only, so nothing ships.

---

## 13. Existing-route review (§10)

No existing route was given financial idempotency. The active Plan classifies none
of the current routes as `financial_mutation`:

| Route | Class | Idempotency? |
|---|---|---|
| `auth/magic-link*`, `auth/logout` | public/auth | no (flow-specific limiter) |
| `auth/mfa/*` | authenticated self-service | no (MFA is not a financial effect) |
| `merchant-registration/*`, `first-time-setup` | public / tenant mutation | no |
| `branches*`, `staff*`, `staff/{}/permissions` | tenant/branch mutation | no |
| `merchant/dashboard`, `audit-logs*` | reads | no |

Financial routes arrive in Phases 17–18 / 20*, which attach the middleware via the
`financial_mutation` classification.

---

## 14. Full quality results

```
docker compose exec app php artisan migrate:fresh --seed ........... DONE
php artisan test (full backend) .................. 351 passed, 4 skipped
  └ Idempotency + coverage (10 suites) ................. 41 tests passed
php artisan test --parallel ...................... pass (4 processes)
php artisan audit:verify-chain ................... OK (no chains on fresh DB)
composer validate --strict ....................... valid
composer pint -- --test .......................... clean (auto-fixed once)
composer stan (Larastan L8) ...................... no errors
composer audit --locked .......................... 0 advisories
npm run lint ...................... 0 errors (28 pre-existing warnings)
npm run typecheck ................................ clean
npm run test (vitest) ............................ 77 passed
npm run build .................................... built
npm run e2e (playwright) ......................... 30 passed (after 1 flake rerun)
npm audit --audit-level=high ..................... 0 vulnerabilities
gitleaks detect --no-git --redact ................ no leaks
docker build php.Dockerfile  --target dev ........ exit 0
docker build nginx.Dockerfile --target prod ...... exit 0
```

### Initial failures (recorded, not erased)
- **Mixed test-isolation strategy** — `IdempotencyConcurrencyTest` initially used
  `DatabaseTruncation` (committed rows for a second connection); its committed rows
  leaked into later `RefreshDatabase` tests (prune counts off). Fixed by converting
  the concurrency test to `RefreshDatabase` and proving the unique-constraint
  boundary with two `insertOrIgnore` calls on the wrapped connection; rerun green.
- **Carbon 3 `diffInSeconds` returns float** — `retryAfter()` declared `int` →
  `TypeError`. Fixed with `(int) ceil(...)`.
- **Test-assertion bugs** (not impl): asserted replay had no `Set-Cookie` (the
  framework adds a session cookie) and that a DB dump omitted "boom" (the route
  name legitimately contains it). Reworded to assert the secret VALUES / the
  exception detail message are absent. Impl was correct.
- **Pint** auto-fixed import ordering/strict-types; **Larastan** flagged a nullable
  claim row, a raw-SQL concat in the migration, and untyped array params — all fixed.
- **gitleaks** flagged two high-entropy test idempotency keys; renamed + annotated
  `gitleaks:allow` (they are test keys, not secrets); rerun clean.
- **e2e** one `auth-magic-link` check-email flake (documented; R4 changed no
  frontend); rerun 30/30.

---

## 15. Files changed

New: migration `2026_06_22_000003_create_idempotency_keys_table.php`;
`app/Domain/Idempotency/{ClaimResult,ClaimOutcome,IdempotencyStore,ProviderClaimResult,
ProviderReplayGuard}.php`, `.../Enums/IdempotencyState.php`, `.../Models/
IdempotencyKey.php`, `.../Support/{CanonicalRequestHasher,IdempotencyScopeResolver,
ReplayResponseSanitizer}.php`, `.../Exceptions/{IdempotencyException,
IdempotencyKeyRequiredException,InvalidIdempotencyKeyException,
IdempotencyKeyReusedException,RequestInProgressException}.php`;
`app/Http/Middleware/EnsureIdempotentRequest.php`;
`app/Http/Routing/{RouteClass,RouteClassification}.php`;
`app/Console/Commands/PruneIdempotencyKeys.php`;
`database/factories/IdempotencyKeyFactory.php`; ADR-003; 10 test files;
`docs/proof/phase-r4.md`.

Changed: `bootstrap/app.php` (priority), `routes/api.php` (harness + classification),
`routes/console.php` (schedule), `config/servana.php`, `.env.example`,
`docs/architecture/data-dictionary/{README,core-identity-and-tenancy}.md`,
register/traceability/PROGRESS/CHANGELOG.

---

## 16. Work skipped & owning phases

- Full route-classification / OpenAPI contract → **Phase 10** (reuses `route_class`).
- Real invoice/payment/refund route attachment → **Phases 17–18**.
- M-Pesa callback/inbox/receipt dedupe attachment → **Phase 20D** (ADR-006).
- Billing/payout/compensation route attachment → **owning Phase 20 subphases**.
- Tenant-schema remediation → **R5**; session/authorization revocation → **R6**;
  production readiness → **R7**.

---

## 17. Remaining risks

- Crash-after-effect re-execution (see §7 limitation) — closed by ledger-level
  uniqueness in the owning financial phases.
- Lock TTL (30s default) too short for a very slow provider call would surface as a
  spurious `request_in_progress`; configurable via `IDEMPOTENCY_LOCK_TTL_SECONDS`.
- Timestamps use `timestamp(0)` (no tz), consistent with sibling as-built tables;
  project-wide tz reconciliation is not owned by R4.

---

## 18. REM-IDEMP-001 status

`local_complete`. Promotion to `verified_complete` requires the R4 PR merged, CI
green, and required review or a truthful PR-specific governance exception — not
asserted here.

## Solo-Maintainer Review Exception

An independent second reviewer was unavailable because the repository currently
has one eligible maintainer. The product owner authorized a PR-specific
governance exception rather than fabricating approval.

Evidence:

- PR: #16
- CI/Backend: passed
- CI/Frontend: passed
- CI/Security: passed
- CI/Docker: passed
- GitHub reviewDecision: intentionally blank
- Exception record:
  docs/governance/solo-maintainer-review-exception-pr-16.md

This exception applies only to PR #16.
