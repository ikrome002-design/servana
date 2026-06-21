# ADR-001 — Framework Upgrade Target (Laravel 12 on PHP 8.3)

- **Status:** Accepted (R1, 2026-06-21). Records and formalizes the upgrade
  delivered by PR #11 (`cbcf50c`); closes the governance gap that left
  REM-DEP-001 open after Phase V.
- **Deciders:** Servana engineering (senior eng / QA / DevOps); product owner
  approved the upgrade.
- **Required by:** Plan §79 Phase R1; Plan §8 ADR-001; REM-DEP-001 (§5.3).
- **Related:** [Laravel 12 upgrade notes](../../operations/laravel-12-upgrade.md);
  `docs/proof/phase-r1.md`; `docs/verification/as-built-discrepancies.md` (claim 1).

## Context and security problem

The original stack pinned **Laravel 11** (11.54) and carried a documented
`composer audit` suppression for **CVE-2026-48019 / GHSA-5vg9-5847-vvmq** (the
default email rule accepted CR/LF, enabling mail-header injection). Phase V
classified this as a **C0** item: an unsupported/vulnerable framework line with a
suppressed advisory cannot precede any feature work (Plan §5.4 pre-feature gate).
In total five published advisories affected the dependency tree:

- `guzzlehttp/guzzle` — CVE-2026-55767, CVE-2026-55568 (< 7.12.1)
- `guzzlehttp/psr7` — CVE-2026-55766 (< 2.12.1)
- `laravel/framework` — signed-URL path confusion GHSA-crmm-hgp2-wgrp (< 12.61.1)
- `laravel/framework` — CR/LF email rule GHSA-5vg9-5847-vvmq (< 12.60.0)

## Decision

1. **Upgrade to Laravel 12.60+.** `composer.json` requires
   `laravel/framework: ^12.61.1`; the **installed** version is **v12.62.0**
   (verified from `composer.lock` and the running app container). Laravel 12 is
   **not** an LTS release; we track the exact patched version, not a marketing
   label (Plan A-09).
2. **PHP 8.3 is the canonical runtime everywhere.** `php:8.3-fpm-alpine` base
   image (`docker/php.Dockerfile`), `php-version: '8.3'` in CI
   (`.github/workflows/ci.yml`), and `config.platform.php = 8.3.31` in
   `composer.json` so Composer resolves against 8.3 regardless of host PHP.
3. **Docker PHP 8.3 is the canonical local runtime.** The Windows developer host
   may run a different PHP (observed 8.5.6); that host PHP is **not** used for
   building, testing, or running Servana. All `php`/`composer`/`artisan` commands
   run inside the `servana-app` container (`docker compose exec app …`). The
   committed `config.platform.php` pin is the durable version lock; no additional
   host-level pin is introduced because it would not be authoritative.
4. **Remove the advisory suppression.** The `audit.ignore` entry for
   CVE-2026-48019 is deleted; the underlying defect is actually fixed by the
   upgrade. `composer audit --locked` exits 0 with **zero suppressions**.
5. **Retain the transitive security bumps** from PR #11: guzzle 7.12.1, psr7
   2.12.1 (plus carbon 3.13.0, ramsey/uuid 4.9.3, uri-template 1.0.7,
   webmozart/assert 2.4.1, symfony/polyfill-php84 — transitive only; no unrelated
   direct upgrades).

## Dependency / security rationale

The upgrade is the minimal change that clears all five advisories: the two
framework advisories require Laravel ≥ 12.61.1, and the guzzle/psr7 advisories
require their patched lines, which Laravel 12's constraints pull in. Direct
dev/runtime dependencies are L12-compatible at their installed versions
(larastan ^3, pest ^3.5, sanctum ^4.3, paratest ^7.4). `composer validate
--strict` passes; `composer.json`/`composer.lock` required **no** R1 changes.

## Runtime parity (local / CI / worker / scheduler / production)

| Surface | PHP 8.3 source | Verified |
|---|---|---|
| Local app | `servana-app:dev` ← `docker/php.Dockerfile` (`php:8.3-fpm-alpine`) | `php -v` → 8.3.31 |
| Worker | same `servana-app:dev` image (`&app-build` anchor) | `php -v` → 8.3.31 |
| Scheduler | same `servana-app:dev` image | `php -v` → 8.3.31 |
| CI | `shivammathur/setup-php@v2` `php-version: '8.3'` | `.github/workflows/ci.yml` |
| Production | `servana-app:prod` ← `docker/php.Dockerfile` `target: prod` (same 8.3 base) | `docker-compose.prod.yml` (app/worker/scheduler) |
| Composer resolution | `config.platform.php = 8.3.31` | `composer.json` |

## Application compatibility change

One L12 source adjustment was required (PR #11):
`app/Domain/Tenancy/Services/LogUnauthorizedAttempt.php` dropped a now-always-true
`instanceof Route` guard (in Laravel 12 `Request::route()` returns a non-null
`Route` in the relevant context) and its unused `Illuminate\Routing\Route`
import. **Behavior is unchanged** (the audited route name is still recorded).
No other application, config, provider, Sanctum, queue, or scheduler change was
needed; the full suite + Larastan level 8 pass unmodified.

## Schema compatibility

The upgrade requires **no schema change**. A clean `migrate:fresh --seed` on a
disposable PostgreSQL 16 database applies all 26 migrations and the
`PermissionSeeder` successfully under PHP 8.3 / Laravel 12.62.0.

## Rollout strategy

Expand-and-contract / image-based rollout (Plan A-08, ADR-004): build the PHP 8.3
/ Laravel 12 image, deploy app → worker → scheduler from the same image, run the
readiness probe before cutover. No data migration is coupled to this upgrade, so
rollout is a pure image swap.

## Rollback limitations and forward-repair

Rollback is **image rollback within schema compatibility only**: because no
schema change is introduced, reverting to the prior image is safe. However,
reverting reintroduces the five advisories, so rollback is an emergency measure,
not a routine option. Any future framework-level issue is handled by
**forward-repair** (a new patched image), never by destructive `down()`
migrations (Plan A-08).

## Consequences

- **Positive:** zero outstanding advisories; supported framework line; CR/LF and
  signed-URL classes of attack closed and regression-guarded.
- **Negative / cost:** Laravel 12 is not LTS, so the team must track point
  releases. Host/container PHP divergence must be respected by always working in
  the container.
- **Neutral:** the `config.platform.php` pin must be bumped deliberately if PHP
  is ever upgraded.

## Verification evidence

- Versions: `docs/verification/evidence/versions.txt`; container `php -v` →
  8.3.31; `php artisan --version` → Laravel Framework 12.62.0.
- `composer validate --strict` → valid; `composer audit --locked` → 0 advisories,
  0 suppressions.
- Regression tests: `tests/Feature/Security/EmailHeaderInjectionTest.php` (4
  pass) and `SignedUrlIntegrityTest.php` (4 pass).
- Full proof: `docs/proof/phase-r1.md`.

## Superseded posture

This ADR supersedes the prior "Laravel 11 pinned (Plan §2 AS-1)" stack statement.
The in-repo `CLAUDE.md` and Plan were realigned to Laravel 12 in Phase V/PR #12;
this ADR is the authoritative record of the decision and its rationale.
