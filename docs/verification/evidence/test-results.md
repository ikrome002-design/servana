# Phase V — Full Quality Suite Results

Captured 2026-06-21 on branch `phase-v-as-built-verification` @ `e8681f6`.
Backend/PHP suites ran **inside the `servana-app-1` container** (PHP 8.3.31,
Laravel 12.62.0) against the running **PostgreSQL 16.14** + **Redis 7.4.9**
service containers. Frontend suites ran on the host (Node v24.15.0). Counts are
re-run results, not copied from `PROGRESS.md` (Correction 25.3).

| # | Command | Environment | Exit | Result |
|---|---|---|---|---|
| 1 | `php artisan migrate:fresh` (disposable DB `servana_asbuilt`) | container → PG16 | 0 | 26 migrations applied clean |
| 2 | `php artisan test` (serial) | container → PG16/Redis7 | 0 | **238 passed, 4 skipped (1067 assertions)** in 226.68s |
| 3 | `php artisan test --parallel` (4 processes) | container → PG16/Redis7 | 0 | **238 passed, 4 skipped (1067 assertions)** in 264.64s |
| 4 | `composer pint -- --test` | container | 0 | PASS — 254 files |
| 5 | `composer stan` (Larastan level 8) | container | 0 | No errors — 182 files |
| 6 | `composer validate --strict` | container | 0 | `composer.json is valid` |
| 7 | `composer audit --locked` | container | 0 | No security vulnerability advisories found |
| 8 | `npm run typecheck` (vue-tsc) | host | 0 | 0 errors |
| 9 | `npm run lint` (eslint) | host | 0 | 0 errors, 28 warnings (pre-existing single-line-element stubs) |
| 10 | `npm run test` (vitest) | host | 0 | **72 passed (17 files)** |
| 11 | `npm run build` (vue-tsc + vite) | host | 0 | built in ~41s |
| 12 | `npm run e2e` (playwright + axe, chromium) | host (vite preview :4173) | 0 | **27 passed** |
| 13 | `npm audit --audit-level=high` | host | 0 | found 0 vulnerabilities |
| 14 | `gitleaks detect --source . --no-git --redact` | host | 0 | no leaks found (6.47 MB scanned) |
| 15 | `docker build -f docker/php.Dockerfile --target dev .` | host | 0 | built (servana-verify-php:dev) |
| 16 | `docker build -f docker/nginx.Dockerfile --target prod .` | host | 0 | built (servana-verify-nginx:prod) |

## Reruns / flakes
- No flakes observed in this session. Backend serial and parallel produced the
  identical pass/skip counts. (A successful rerun does not erase a prior
  failure — none were recorded here.)

## Skipped tests (4) — all permanent, owner-tagged placeholders
File: `tests/Feature/Isolation/FutureResourceIsolationTest.php` — each enumerates
a Plan §8.4 denied-case row whose resource does not exist yet, and names its
owning **feature** phase so the future phase has a skipped→unskip test waiting:

| Skipped test | Owning phase | Reason |
|---|---|---|
| `GET /invoices/{foreign-ulid} → 404 + audit` | Phase 17 (Invoicing) | invoices table not built yet |
| Finance lists other-branch payments → empty/404 + audit | Phase 18 (Payments) | payments table not built yet |
| Export job given unscoped query → service refuses | Phase 18/19 | finance exports not built yet |
| Personnel requests another personnel queue → 404 | Phase 16 (queue/sessions) | queue/session own-scope not built yet |

These are **feature-delivery** placeholders (Section 5.4a), not pre-feature
defects, and do not gate Phase V.

## Direct DB evidence (not a test — runtime constraint proof)
On the disposable `servana_asbuilt` DB, after inserting one `audit_logs` row:
- `UPDATE audit_logs ...` → `ERROR: audit_logs is append-only (UPDATE blocked)`
- `DELETE FROM audit_logs ...` → `ERROR: audit_logs is append-only (DELETE blocked)`
- row remained intact.
Confirms the `audit_logs_block_mutation()` trigger (BEFORE UPDATE + BEFORE DELETE).
