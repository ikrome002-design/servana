# Phase R1 — Dependency & Runtime Security (Proof)

**Objective (Plan §79 R1):** remove and **formally close** the previously
unsupported/vulnerable framework state; close REM-DEP-001.

**Branch:** `phase-r1-dependency-runtime-security` · **Base:** merged `main`
`c58b64a` (PR #12, Phase V) · **Date:** 2026-06-21 · **Runtime:** all PHP/Composer
/artisan commands ran inside `servana-app` (PHP 8.3.31 / Laravel 12.62.0) against
PostgreSQL 16.14 + Redis 7.4.9.

## 1. Prove the problem
Phase V found the Laravel 12.62.0 upgrade already merged (PR #11), advisory
suppression removed, and both security regression tests present — but R1 left
**incomplete** because ADR-001, formal upgrade notes, the R1 proof, and the
REM-DEP-001 completion evidence were missing. R1 re-verifies the upgrade and
produces those artifacts. **No framework re-upgrade was performed.**

## 2. Installed runtime / package versions (re-verified)
| Item | Evidence | Result |
|---|---|---|
| Laravel | `php artisan --version` (container) + `composer.lock` | **12.62.0** (≥ 12.60 ✓) |
| PHP (app) | `php -v` | **8.3.31** |
| PHP (worker) | `docker compose exec worker php -v` | **8.3.31** |
| PHP (scheduler) | `docker compose exec scheduler php -v` | **8.3.31** |
| PHP (CI) | `.github/workflows/ci.yml` `php-version: '8.3'` | pinned ✓ |
| PHP (prod) | `docker-compose.prod.yml` app/worker/scheduler ← `docker/php.Dockerfile target prod` | 8.3 base ✓ |
| Composer platform | `composer.json` `config.platform.php` | 8.3.31 |
| Sanctum | `composer.lock` | 4.3.2 |
| guzzle / psr7 | `composer.lock` | 7.12.1 / 2.12.1 (PR #11 retained) |

## 3. Advisory state
- `composer validate --strict` → `./composer.json is valid`.
- `composer audit --locked` → **No security vulnerability advisories found.**
- No `audit.ignore` / `audit` key in `composer.json`; no CVE/GHSA reference in
  source (confirmed Phase V). **Zero suppressions.**

## 4. PHP 8.3 parity evidence
app, worker, scheduler all run the single `servana-app` image built from
`docker/php.Dockerfile` (`php:8.3-fpm-alpine`) via the `&app-build` compose
anchor → all 8.3.31. CI sets up PHP 8.3; prod compose builds the same Dockerfile
`target prod`. Parity holds across local/CI/worker/scheduler/production.

## 5. Compatibility review
- **Direct deps** all L12-compatible at installed versions (larastan ^3, pest
  ^3.5, sanctum ^4.3, paratest ^7.4, sentry 4.26.0); `composer.json`/`composer.lock`
  needed **no R1 change**.
- **Only app change** for L12 (PR #11): `LogUnauthorizedAttempt.php` dropped a
  now-always-true `instanceof Route` guard + unused import — behavior unchanged.
- **DB driver / Redis / cache / session / queue / scheduler:** all verified §6.
- **Schema:** no change required by the upgrade.
- No broad refactoring performed.

## 6. DB & cache compatibility (clean / disposable env)
- Disposable DB `servana_r1`: `migrate:fresh --seed` → 26 migrations +
  `PermissionSeeder` apply clean (dev volume untouched).
- `redis-cli ping` → PONG; `Cache::put/get` round-trip → `ok` (driver redis);
  `session.driver=database`, `cache.default=redis`, `queue.default=redis` load.
- `php artisan cache:clear` → success. Worker/scheduler boot on the 8.3 image.

## 7. Targeted security regression results
- `EmailHeaderInjectionTest` → **4 passed (36 assertions)** — embedded CR/LF
  (header injection, LF/CR after address, CRLF in local part) all rejected with
  422; no Magic Link sent.
- `SignedUrlIntegrityTest` → **4 passed (5 assertions)** — valid signed URL
  accepted; query-tamper rejected; path-confusion rejected; expired rejected.

## 8. Full suite (clean containers)
| Command | Result |
|---|---|
| `composer pint -- --test` | PASS (254 files) |
| `composer stan` (Larastan L8) | No errors (182) |
| `php artisan test` (serial) | **238 passed / 4 skipped** (1067 assertions), 78.19s |
| `php artisan test --parallel` (4) | **238 passed / 4 skipped** (1067 assertions) |
| `composer validate --strict` | valid |
| `composer audit --locked` | 0 advisories |
| `npm run lint` | 0 errors, 28 pre-existing warnings |
| `npm run typecheck` | 0 errors |
| `npm run test` (vitest) | **72 passed** (17 files) |
| `npm run build` | built |
| `npm run e2e` (playwright + axe) | **see §9** |
| `npm audit --audit-level=high` | 0 vulnerabilities |
| `gitleaks detect --no-git --redact` | no leaks |

The 4 skipped backend tests are the permanent phase-gated isolation placeholders
(Phases 16/17/18/19), unchanged from Phase V.

## 9. e2e flake (recorded honestly)
- First R1 run: **26 passed / 1 failed**. Two subsequent reruns: **27 passed / 0
  failed**. Local `retries: 0`, `workers: 1`, so the first failure had no retry.
- The Playwright output dir is cleared at the start of each run, so the failing
  spec's artifact was not retained; the pattern (intermittent, green on rerun)
  matches the previously-documented `auth-magic-link` "check email" e2e flake
  (PROGRESS Phase 8 note). **Not an R1 regression** — R1 changes no application
  or frontend code. CI uses `retries: 1`. A passing rerun does not erase the
  initial failure; it is logged here as a known flaky test to stabilize later
  (UI/e2e hardening, Phase 23), not a blocker for R1.

## 10. Docker images
- `docker build -f docker/php.Dockerfile --target dev .` → built.
- `docker build -f docker/nginx.Dockerfile --target prod .` → built.

## 11. Files created / changed in R1
Created: `docs/architecture/adr/0001-framework-upgrade.md`,
`docs/operations/laravel-12-upgrade.md`, `docs/proof/phase-r1.md`.
Modified: `docs/remediation/register.yaml`,
`docs/traceability/servana-requirements.csv`, `docs/PROGRESS.md`,
`docs/CHANGELOG.md`. **No application/source/test/`composer.*`/Docker/CI code
changed** — R1 is verification + governance only.

## 12. Work skipped → owning phase
- Readiness/liveness split, CI cache-prefix isolation, full env parity, ADR-009
  brand contrast → **R7** (REM-OPS-001).
- Audit completeness (R2), MFA (R3), idempotency (R4), tenant-schema (R5),
  session revocation (R6) — out of R1 scope.
- e2e flake stabilization → UI/e2e hardening (Phase 23).

## 13. Rollback / forward-repair
Image rollback within schema compatibility only (no schema change in this
upgrade); rollback reintroduces the five advisories, so it is emergency-only.
Forward-repair (new patched image) is the standard remediation; no destructive
`down()` rollback. (ADR-001; Plan A-08.)

## 14. Remaining risks
- Laravel 12 is not LTS — track point releases; re-run `composer audit`.
- Host vs container PHP divergence — always operate in the container.
- `servana-vendor` named volume hides `composer.lock` changes until in-container
  `composer install` (see upgrade notes).
- One intermittent e2e test (see §9).

## 15. REM-DEP-001 status
**`local_complete`** on this branch: every R1 local acceptance criterion is met
(version ≥ 12.60 proven, PHP 8.3 parity, zero advisories/suppressions, security
regressions pass, DB/cache verified, full baselines green, both images build,
ADR-001 + upgrade notes + this proof exist). **Not** `verified_complete` until the
PR is merged, CI is green, and the Plan-required **second reviewer** (security-
sensitive PR) signs off.

## 16. Lifecycle status
`local_complete` — pending push, CI, and second-reviewer sign-off.
