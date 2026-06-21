# Laravel 12 Upgrade — Operations Notes

Operational companion to [ADR-001](../architecture/adr/0001-framework-upgrade.md)
(decision + rationale) and `docs/proof/phase-r1.md` (verification evidence). This
document covers *how to install, rebuild, deploy, and roll back* the upgrade.

## Versions

| | Before | After |
|---|---|---|
| Laravel framework | 11.54 (`^11.31`) | **12.62.0** (`^12.61.1`) |
| PHP (canonical) | 8.3 | 8.3 (unchanged; pinned) |
| guzzlehttp/guzzle | < 7.12.1 | 7.12.1 |
| guzzlehttp/psr7 | < 2.12.1 | 2.12.1 |
| composer audit | 1 suppressed advisory | 0 advisories, 0 suppressions |

- **Delivered by:** PR #11, merge commit `cbcf50c`.
- **Formalized by:** R1 (this phase) — ADR-001, these notes, `docs/proof/phase-r1.md`.

## Packages changed and why

- `laravel/framework ^11.31 → ^12.61.1` (installed 12.62.0): clears framework
  advisories GHSA-crmm-hgp2-wgrp (signed-URL path confusion, < 12.61.1) and
  GHSA-5vg9-5847-vvmq / CVE-2026-48019 (CR/LF email rule, < 12.60.0).
- **Transitive only** (pulled by the framework constraints, no direct edits):
  guzzle 7.12.1, psr7 2.12.1, uri-template 1.0.7, carbon 3.13.0,
  ramsey/uuid 4.9.3, webmozart/assert 2.4.1, +symfony/polyfill-php84.
- Removed the obsolete `audit.ignore` for CVE-2026-48019 (now actually fixed).
- **No unrelated dependency upgrades.**

## Application compatibility change

`app/Domain/Tenancy/Services/LogUnauthorizedAttempt.php`: removed a
now-always-true `instanceof Route` check (Laravel 12 `Request::route()` returns a
non-null `Route`) and the unused `Illuminate\Routing\Route` import. **Behavior
unchanged.** No other app/config/provider/Sanctum/queue/scheduler change required.

## Security tests added (PR #11, retained — do not weaken)

- `tests/Feature/Security/EmailHeaderInjectionTest.php` — embedded CR/LF email
  inputs rejected with the structured 422 envelope; no Magic Link sent.
- `tests/Feature/Security/SignedUrlIntegrityTest.php` — valid signed URL accepted;
  query-tamper, path-confusion, and expiry all rejected (403).

## Installation / rebuild procedure (local, Docker-canonical)

All commands run **inside the container** — the Windows host PHP (may be 8.5) is
not used.

```powershell
# Rebuild the PHP 8.3 / Laravel 12 image after pulling the upgrade
docker compose build app
docker compose up -d app worker scheduler

# IMPORTANT: refresh vendor inside the container after composer.lock changes.
# The `servana-vendor` named volume shadows the image's /vendor, so a rebuilt
# image alone does NOT update installed packages — you must reinstall in-volume:
docker compose exec app composer install

# Verify
docker compose exec app php -v                  # PHP 8.3.31
docker compose exec app php artisan --version    # Laravel Framework 12.62.0
docker compose exec app composer audit --locked  # 0 advisories
```

> **servana-vendor named-volume warning:** `composer.lock` changes are invisible
> until you run `composer install` *inside* the running container. Skipping this
> leaves the old framework loaded even though the image was rebuilt. (See the
> repository note on the vendor named volume.)

## Database and cache compatibility (verified)

- `php artisan migrate:fresh --seed` on a disposable PostgreSQL 16 DB → 26
  migrations + `PermissionSeeder` apply clean. **No schema change** from the
  upgrade.
- Redis: `redis-cli ping` → PONG; `Cache::put/get` round-trip OK (cache driver
  redis); `session.driver=database`, `queue.default=redis` load correctly.
- Worker and scheduler containers boot on the PHP 8.3 image.

## Deployment / image rollout sequence

1. Build `servana-app:prod` (`docker/php.Dockerfile target prod`) and
   `servana-nginx:prod`.
2. Apply migrations (none required for this upgrade) — migrate-before-switch.
3. Roll out app → worker → scheduler from the same `servana-app:prod` image
   (all three reference `${APP_IMAGE}` in `docker-compose.prod.yml`).
4. Gate cutover on the readiness probe (readiness hardening itself is Phase R7).

## Rollback (within schema compatibility)

Because the upgrade introduces no schema change, rollback is a safe **image
rollback** to the previous tag. Caveat: rollback reintroduces all five
advisories, so treat it as an emergency-only step. Preferred remediation for any
future framework issue is **forward-repair** (a new patched image), never a
destructive `down()` migration.

## Known residual risks

- Laravel 12 is **not** LTS — track point releases and re-run `composer audit`
  regularly.
- Host vs container PHP divergence — always operate inside the container.
- Readiness/liveness split and full environment parity polish are **Phase R7**,
  not R1.
