# Phase 2 — Docker & Environment Setup · Proof of Resolution

**Branch:** `phase-2-docker-environment` (stacked on `phase-1-initialization`)
**Date:** 2026-06-13
**Plan reference:** §27 Phase 2, §26.1 (Docker), §26.2 (pipeline), §28.
**Guardrails:** CLAUDE.md §6.

---

## 1. Prove the Problem

| What must be built | Requirement | Failure if omitted | Verification |
|---|---|---|---|
| Dev Docker stack matching prod topology | Plan §26.1 | No reproducible env; "works on my machine" | `make up` → all services healthy |
| PHP-FPM 8.3 image, non-root, required extensions | Plan §26.1, CLAUDE.md §6 | Wrong runtime; root containers | `id` → uid 1000; ext present |
| Postgres 16 + migrations | Plan AS-1, §27 | No database parity | `make fresh` migrates |
| Redis / Meilisearch / MinIO / Mailpit | Plan §4.1, §26.1 | Missing infra deps | reachability proofs below |
| One-command onboarding | §27 acceptance | Slow onboarding | `make up && make fresh && make test` |
| Documented, secret-free `.env.example` | §27, CLAUDE.md §6.4 | Secret leakage | placeholders only; gitleaks clean |
| CI stays aligned, builds images | Plan §26.2 | Drift; broken Dockerfiles | CI `docker` job builds both images |

---

## 2. Acceptance criteria — evidence

### `make up` → full stack healthy (clean run after `docker compose down -v`)

```text
SERVICE       STATUS
app           Up (healthy)
mailpit       Up (healthy)
meilisearch   Up (healthy)
minio         Up (healthy)
minio-init    Exited (0)          # bucket created, then exits
nginx         Up (healthy)
postgres      Up (healthy)
redis         Up (healthy)
scheduler     Up                  # placeholder (Horizon → Phase 21)
worker        Up                  # placeholder (Horizon → Phase 21)
```

### `make fresh` → migrations on PostgreSQL 16

```text
INFO  Running migrations.
  0001_01_01_000000_create_users_table .... DONE
  0001_01_01_000001_create_cache_table .... DONE
  0001_01_01_000002_create_jobs_table  .... DONE
INFO  Seeding database.
```

### `make test` → canonical quality gate inside the container

```text
$ docker compose exec app sh -lc "composer pint -- --test && composer stan && php artisan test --parallel"
Pint .... PASS ... 28 files
Larastan (level 8) .... [OK] No errors
Pest .... Tests: 2 passed (3 assertions)   Parallel: 4 processes
```

### Service reachability

```text
GET http://localhost:8080/health        -> HTTP 200 {"status":"ok","service":"servana",...}
app container id                          -> uid=1000(servana) gid=1000(servana)
redis-cli ping                            -> PONG
meilisearch /health                       -> {"status":"available"}
MinIO (Laravel s3 disk round-trip)        -> exists=1 contents=servana phase2
MinIO bucket init                         -> "Bucket created successfully `local/servana`"
Mailpit (Mail::raw + /api/v1/messages)    -> {"total":1,...,"subject":"Phase 2"}
```

### Security / hygiene

```text
git check-ignore .env                     -> .env (ignored)
.env.example APP_KEY                       -> APP_KEY=   (empty placeholder)
gitleaks protect --staged                 -> no leaks found
non-root                                  -> app uid 1000; nginx via nginx-unprivileged
```

---

## 3. Defects found & fixed during verification (Bug Fix Protocol)

**Defect A — every HTTP request returned 500 ("Session store not set on request").**
- Evidence: `curl /health` and `/` → HTTP 500; log `RuntimeException: Session store not set`.
- Root cause: `/health` lived in the `web` middleware group; `StartSession` with
  `SESSION_DRIVER=database` requires a `sessions` table that does not exist
  before `make fresh`. Stripping only `StartSession` then broke `ValidateCsrfToken`,
  which also reads the session.
- Fix: register `/health` outside the web group via the `withRouting(then: …)`
  closure in `bootstrap/app.php` (global middleware only — no session/CSRF/DB).
  Removed the route from `routes/web.php`.
- Result: `/health` → 200 with no DB dependency, before and after migration.

**Defect B — nginx and meilisearch containers reported `unhealthy` though working.**
- Evidence: healthcheck log `wget: can't connect ... [::1]:8080: Connection refused`,
  yet `curl` from host returned 200 / `available`.
- Root cause: the healthcheck URL used `localhost`, which resolves to IPv6 `::1`;
  nginx and Meilisearch listen on IPv4 only.
- Fix: healthchecks use `http://127.0.0.1:…`.
- Result: both services report `healthy`.

**Defect C — Laravel `s3` disk threw `PortableVisibilityConverter not found`.**
- Evidence: `Storage::disk('s3')->put(...)` fatal error.
- Root cause: `league/flysystem-aws-s3-v3` (the S3 adapter) was not installed.
- Fix: added `league/flysystem-aws-s3-v3` to `composer.json` require.
- Result: full put/exists/get round-trip against MinIO succeeds.

**Polish — `git dubious ownership` warning during Pint/Larastan in-container.**
- Root cause: bind-mounted `.git` owned by the host uid, container runs as uid 1000.
- Fix: `git config --system --add safe.directory /var/www/html` in the image.
- Result: clean `make test` output.

---

## 4. Work skipped / deferred (see docs/PROGRESS.md for the structured list)

- **Horizon** dashboard/config → Phase 21 (worker runs `queue:work` placeholder now).
- **ClamAV** upload scanning → Phase 23 (daemon behind opt-in `clamav` profile).
- **`/health/deep`** readiness probe → Phase 3.
- **opcache preload + production deploy/secrets/registry push** → Phase 24 / 25
  (prod Dockerfile + `docker-compose.prod.yml` exist, not deployed).

---

## 5. Residual risks

1. Local PHP 8.5 vs pinned 8.3 — CI/Docker enforce 8.3 (unchanged from Phase 1).
2. CVE-2026-48019 still ignored-with-rationale (no Laravel 11 fix).
3. CI not yet executed on this branch; "green on first PR" pending push.
4. Healthchecks for Mailpit have no in-container probe tool, so they rely on the
   send/receive proof + `depends_on`; documented, low risk.
