# LOCAL-UAT-001 — complete-application local browser readiness — proof

| Field | Value |
|---|---|
| Phase | `LOCAL-UAT-001` — local environment and browser acceptance readiness (not UI-18, not Phase 25, not production) |
| Branch | `phase-local-browser-uat-001`, created from clean `main == origin/main` |
| Base | `c7e963c88cced493364705e9e25ab2206862e0c7` — PR #69 merge (verified live: merged `2026-10-14T16:54:14Z`, run `38516839765` five jobs SUCCESS) |
| Dates | 2026-10-14 → 2026-10-15 (Windows 10 Pro, PowerShell 7.6.7, Docker 29.8.2 / Compose v5.6.0, Node 24.15.0, Playwright 1.62.0, PHP 8.3 container, Laravel 12.69.3) |
| Status | `local_complete` — **product-owner hands-on UAT and visual acceptance pending**; GitHub release not started by Claude; production not authorized |
| Runbook | [`docs/runbooks/local-browser-uat.md`](../runbooks/local-browser-uat.md) |
| Machine register | [`docs/frontend/audits/local-uat-001/status-register.json`](../frontend/audits/local-uat-001/status-register.json) |

## 1. What "ready" means here — the separate gates

| Gate | Result | Evidence |
|---|---|---|
| `windows_hosts_mapped` | **pass 8/8** | native `System.Net.Dns` → `127.0.0.1` only for every host |
| `native_browser_hosts_reached` | **pass** | desktop browser pane (Chrome 152 MSIX, OS resolver) rendered all eight distinct landing pages; Playwright Chromium in `LOCAL_UAT_RESOLVER=hosts` mode (no resolver override) |
| `local_app_integrated` | **pass** | real nginx → Laravel/Sanctum → PostgreSQL/Redis → queue worker → Mailpit; no mocked API in `tests/e2e-local-uat/` |
| `agent_local_uat_complete` | **pass** (see §8 for exact counts) | every agent-executable local gate below |
| `product_owner_uat_approved` | **pending** | owner checklist: runbook §6 |
| `github_code_release` | not started by Claude | Codex Prompt 2 |
| `production_deployed` | not authorized / `blocked_external` | UI-17 + Phase 25 |

## 2. Baseline (before any change)

- `main == origin/main == c7e963c8…`, clean, `0 0`; PR #69 `MERGED`.
- Stack stopped; `servana-app:dev` built 2026-07-25, before the Phase 24 Dockerfile change (`PH24-OPCACHE-001`)
  → rebuilt (no volume touched). Vendor volume already current (`composer install --dry-run`: nothing to do).
- Local database held **only** the `migrations` table (132 rows) — no users, no tenants. The documented
  `make fresh` "demo tenants" did not exist (`DatabaseSeeder` creates one test user).
- Windows hosts file: no `.test` records (resolver FAIL 8/8). Process not elevated.
- Served SPA bundle matched current source (rebuilt manifest byte-identical, `CE0E39C0…`).

## 3. Windows hosts mapping (performed by Claude, approved by the owner through UAC)

- **Script review.** The first draft of `scripts/dev/servana-hosts.ps1` was hardened before elevation:
  it rewrote the file as ASCII (would have stripped the real file's UTF-8 BOM), used second-resolution
  backup names, did not detect unmanaged conflicting records or concurrent changes, could not be tested on
  a fixture, and an elevated child could not report its outcome. A fixture self-test then caught a real
  parsing bug (`-split <pattern>, -1` returned the whole file as one line) before the real file was touched.
  `scripts/dev/tests/servana-hosts.selftest.ps1`: **21/21** on temporary fixtures (CRLF, LF, BOM, non-ASCII,
  idempotence, conflict refusal, owner loopback respected, malformed marker refused, exact `-Remove` inverse).
- **Elevation.** `Start-Process pwsh -Verb RunAs` (normal UAC). Attempt 1: "The operation was canceled by the
  user" — no change. Attempt 2 (owner chose *Retry now* and approved): child exit `0`,
  `changed=true`, backup `C:\WINDOWS\System32\drivers\etc\hosts.servana-backup-20261015-074027-119-f97d46de`
  (machine-local, never committed), SHA-256 `E6881FAD…` → `C2B30584…`.
- **Independent read-back (non-elevated).** BOM kept, CRLF only, the original 273 bytes preserved as an exact
  prefix (Docker Desktop section intact), one managed block, eight explicit records, no wildcard.

| Host | Native resolver | Browser URL |
|---|---|---|
| `servana.test` | 127.0.0.1 | <http://servana.test:8080/> |
| `citrus.servana.test` | 127.0.0.1 | <http://citrus.servana.test:8080/> |
| `branch.servana.test` | 127.0.0.1 | <http://branch.servana.test:8080/> |
| `finance.servana.test` | 127.0.0.1 | <http://finance.servana.test:8080/> |
| `hr.servana.test` | 127.0.0.1 | <http://hr.servana.test:8080/> |
| `office.servana.test` | 127.0.0.1 | <http://office.servana.test:8080/> |
| `staff.servana.test` | 127.0.0.1 | <http://staff.servana.test:8080/> |
| `audit.servana.test` | 127.0.0.1 | <http://audit.servana.test:8080/> |

Port `8080` is the nginx publish in `docker-compose.yml`; HTTP only (no local TLS). Mail viewer
<http://localhost:8025/>.

## 4. Defect register (prove → root cause → precise fix → tests → evidence)

### LUAT-D01 — Front Office could not choose a service or eligible staff (C1) — `resolved_verified`

- **Observed.** Live Front Office session: `GET /api/v1/services?status=active` → **403
  `permission_denied`**; `GET /api/v1/services/{id}/eligibility` → **403**. Branch Manager control: 200.
  Consumers: `WalkInCreate`, `AppointmentCreate`, `AppointmentDetail`, `QueueEntryDetail`, `QueueTransfer`.
- **Root cause.** Those screens depended on the Branch Manager catalogue reads, authorized by `service.view`
  (Plan §19 matrix: Branch Manager only). The 403 itself is correct; the defect was the screens' dependence
  on the wrong, privileged contract. UI-13's browser suite mocks the API, so it never surfaced. No existing
  Front Office-permitted alternative existed.
- **Decision.** Owner chose **"Narrow FO endpoint"** (2026-10-15). Rejected: a new permission key, granting
  `service.view`, deferring.
- **Fix.** `GET /api/v1/front-office/service-options?purpose=walk_in|appointment` and
  `GET /api/v1/front-office/services/{service}/personnel-options?purpose=walk_in|appointment|appointment_assign|appointment_transfer|queue_assign|queue_transfer`
  (`FrontOfficeSelectionController`, `FrontOfficeServiceOptionRequest`, `FrontOfficePersonnelOptionRequest`,
  two resources). Each purpose maps server-side to the existing key of that one workflow (`queue.create`,
  `appointment.create`, `appointment.assign`, `appointment.transfer`, `queue.assign`, `queue.transfer`);
  unknown purpose 422, missing key 403. Services: active, acting merchant + branch, `{id, name,
  duration_minutes}` only. Personnel: active eligibility, active and employed staff of the service's branch,
  `{staff_profile_id, staff_name, active}` only. Archived / other-branch / foreign service → 404. Read-only.
  The five screens call `services/frontOfficeSelections.ts`; walk-in and appointment-create abort a stale
  personnel request when the service changes. **No permission key added; `service.view` and every catalogue
  read/write remain refused to Front Office.** OpenAPI regenerated (307/358 → 309/360) and TS types
  regenerated with the repository generators.
- **Tests.** `tests/Feature/FrontOffice/FrontOfficeSelectionApiTest.php` **12 tests / 79 assertions**:
  branch-only active services with picker fields; all six personnel purposes; inactive eligibility, inactive
  and terminated staff excluded; a `deny` override on `queue.transfer` blocks only that purpose; unknown or
  missing purpose 422 (no unscoped fallback); Branch Manager (holds `service.view`), Personnel, HR, Finance,
  Audit → 403; anonymous 401; foreign merchant / other branch / archived → 404; catalogue reads and writes
  still 403 for Front Office and 200 for Branch Manager; zero rows written; a choice that becomes ineligible
  is refused again on submission (422, no queue row); two-tenant isolation.
  `frontOfficeSelections.spec.ts` 3 tests. Live browser (`12-front-office-selection.spec.ts`, real clicks,
  native resolver): walk-in queued (Mercy Chebet) → queue-entry transfer (Otieno Ouma) → transfer page
  (Mercy Chebet) → appointment booked (Mercy Chebet) → appointment transfer (Otieno Ouma); each read its
  choices with its own purpose, made **no** `/services` call and received **no** 401/403, and each record
  persisted across reload. `appointment_assign` / `queue_assign` share the same code path and are covered
  by the API tests.

### LUAT-D02 — `?active=true` refused, four operational lists empty — `resolved_verified`

- **Observed.** Live crawl: `GET /api/v1/queue-entries?active=true&sort=position` → 422 `"The active field
  must be true or false."` on Branch queue and Front Office queue; same on `/service-sessions?…&active=true`
  and `/personnel/me/queue?active=true`.
- **Root cause.** OpenAPI declares `active` as `in: query, type: boolean` (serialised `true`/`false`, which
  axios sends); Laravel's `boolean` rule accepts only true/false/1/0/"1"/"0". Backend tests passed because
  the test client serialises PHP `true` as `1`. The request docblock itself documents `active=true`.
- **Fix.** `App\Http\Requests\Concerns\NormalizesQueryBooleans` maps only the literal words (any case) before
  validation on `QueueIndexRequest`, `ServiceSessionIndexRequest`, `PersonnelQueueIndexRequest`,
  `PersonnelServiceSessionIndexRequest`, `PlatformSubscriptionQueryRequest`. Rules unchanged.
- **Tests.** `QueryStringBooleanFilterTest` 6 tests: 5 red before (422), 6 green after; `maybe` / `yes` still 422.

### LUAT-D03 — the backend test suite erased the local database — `resolved_verified`

- **Observed.** During this phase a targeted `php artisan test <file>` in the app container left the local
  database with **0 users / 0 merchants** (it had held the seeded dataset). The task-start state (only the
  `migrations` table) is consistent with the same cause having run earlier.
- **Root cause.** The container exports `DB_DATABASE=servana`; `tests/bootstrap.php` forced the test cache,
  session, queue and mail drivers but not the database, so a non-parallel run inherited the developer
  database and `RefreshDatabase` ran `migrate:fresh` on it. Parallel runs and CI (`servana_test`) were safe.
- **Fix.** `tests/bootstrap.php` redirects a run whose database is not already a test database to
  `<name>_test`, creating it when missing; if it cannot, the run stops — it never falls back.
- **Tests / proof.** `tests/Unit/TestDatabaseIsolationTest.php` asks PostgreSQL `current_database()` from a
  `RefreshDatabase` test: red before (`servana`), green after in serial (`servana_test`) and parallel
  (`servana_test_<n>`). A canary table created in the dev database survived a full `RefreshDatabase` run
  (count 1 before and after). The lost data was the synthetic dataset created in this phase; it was reseeded.

### LUAT-D04 — signed-in users landed on a legacy page instead of `/dashboard` — `resolved_verified`

- **Observed.** Native browser: Branch sign-in landed on `/branch`, an in-app "role landing" repeating the
  marketing headline. Five accounts (`platform`, `merchant`, `branch`, `hr`, `finance`) did this; Front Office,
  Personnel and Audit already went to `*.dashboard`.
- **Root cause.** Stale `ROLE_ENTRY.landingRouteName` values; the binding contract (UI/UX §4.1,
  `default_authenticated_route: /dashboard` for all eight) was never applied to these five.
- **Fix.** The five entries point at `platform.dashboard`, `merchant.dashboard`, `branch.dashboard`,
  `hr.dashboard`, `finance.dashboard`. The legacy `/<role>` routes remain (they still redirect old links).
- **Tests.** New `roleEntryRoutes.spec.ts` case asserts all eight resolve to `/dashboard` (red before: Super
  Administrator resolved to `/platform`); `Verify.spec.ts` expectation updated with the contract cited.
  The browser sign-in helper now requires landing on `/dashboard`.

### LUAT-D05 — `crypto.randomUUID is not a function` on plain-HTTP hosts — `resolved_verified`

- **Observed.** Walk-in submit: `POST /api/v1/walk-ins` **201** (and audited), then the page stayed on the
  form; console `TypeError: crypto.randomUUID is not a function`.
- **Root cause.** `crypto.randomUUID` exists only in secure contexts (HTTPS or localhost). 28 calls in 16
  stores used it for toast ids and **every Idempotency-Key** (payments, validation, receipts, refunds,
  cash-up, period locks, payouts, platform billing) — so on the local HTTP hosts every success toast crashed
  and every financial action failed before sending. Production is HTTPS, which is why no phase saw it.
- **Fix.** `resources/spa/src/utils/uuid.ts` `randomUuid()`: `crypto.randomUUID()` when present, otherwise an
  RFC 4122 v4 UUID from `crypto.getRandomValues()` (available everywhere, equally strong).
- **Tests.** `uuid.spec.ts` (v4 shape; unique values with `randomUUID` absent). Live: the walk-in now shows
  "Walk-in added to the queue." and navigates to the entry. `navigator.clipboard` (Audit "copy reference")
  is also secure-context-only but already degrades to a visible "failed" state — left as is.

### Recorded, not changed

| ID | Observation | Why not changed here | Owner |
|---|---|---|---|
| LUAT-F01 | `/register` renders the merchant registration form when typed directly on the seven non-Merchant-Administrator hosts. No landing page links to it, and the backend binds the resulting link to the Merchant Administrator host. | UI route-scope change; no account boundary moves | product owner → UI owner |
| LUAT-F03 | `/auth/check-email?email=<address>` carries the address in the URL (browser history). nginx logs strip query strings. | UI change | product owner → UI owner |
| LUAT-F04 | The long-lived nginx container log still contains one pre-UI-17 (2026-08-11) line with a sign-in URL whose 15-minute token expired two months ago. All 32 sign-in lines written in this phase are path-only. | Deleting container logs needs owner consent (`docker compose up -d --force-recreate nginx` discards them) | product owner |
| — | Super Administrator `GET /platform/billing-settings` → 404 until first saved (`abort_if($current === null)`); the page renders its own state. | Intended fresh-install behaviour | — |

## 5. Real authentication

- Request → queued `MagicLoginLinkNotification` → Mailpit → link bound to the requesting host → verify →
  session → `/api/v1/me` 200. Replay → 422 `invalid_or_expired_token`. A link issued on `audit.` consumed on
  `finance.` → 422 and no session. A merchant session carries no session on another host (`/me` 401).
- Session cookie is HttpOnly (only `XSRF-TOKEN` is readable by JavaScript); `SameSite=Lax`; local HTTP only.
- MFA: Super Administrator, Merchant Administrator and Finance automation identities answer the real TOTP
  challenge; a newly registered Merchant Administrator enrols on the real setup screen (secret read from the
  page, recovery codes acknowledged) and then meets `/setup`.
- Rate limiters work and are documented (3/min per address, 10/h per IP, 3 registrations/h per IP); the
  acceptance run clears the **local** limiter cache, never the limiter.
- No token or cookie is written to tracked evidence; Playwright traces are disabled because they would
  record the consumed sign-in URL.

## 6. Real workflows (`10-role-workflows`, `12-front-office-selection`, `15-merchant-self-registration`)

| Role | Steps (HTTP status from the real stack) |
|---|---|
| Branch | open business day 200 · open queue 200 · cash-up draft 200 · cash-up submit 200 |
| Front Office | catalogue still refused 403 · own service options 200 · client create 201 · walk-in 201 · call 200 · start 200 · complete 200 · second walk-in waiting 201 · invoice 201 · finalize 200 · cash payment record 201 · invoice from a seeded session 201 · finalize 200 · M-Pesa offline record 201 (left pending) · appointment 201 · five UI flows in §4 |
| Finance | validate (maker ≠ checker) 201 → group `validated`, invoice `paid`, receipt issued · receipts 200 · validation queue 200 |
| Human Resource | roster 200 · payout run draft 201 · Finance payout surface 403 |
| Personnel | own appointments 200 · own earnings 200 · served clients 200 · SMS **preview** 200 (no send) |
| Audit | branch events 200 (events from the workflow present) · client create 403 |
| Merchant Administrator | self-registration → Mailpit → MFA enrolment → `/setup` ("Set up your business") → setup 200 → `/dashboard` |
| Boundaries | FO validate 403 · Branch validate 403 · cross-tenant client 404 · cross-branch client 403 · Personnel clients 403 · no session on another host 401 |

## 7. Non-destructive negative controls (live stack)

| Control | Result |
|---|---|
| Unknown host `evil.servana.test` | connection closed (nginx 444) |
| Stale fingerprinted asset `/spa-assets/index-STALE000.js` | 404 (nginx error page, not the SPA shell) |
| Worker stopped | sign-in request 202, **no** mail while stopped, delivered after restart (job kept in Redis) |
| PostgreSQL stopped | `/health/deep` 503 `database=error`; after restart 200 `ok` |
| Wrong-host / replayed token | 422, no session |
| Foreign tenant / other branch / wrong role | 404 / 403 as in §6 |

## 8. Test matrix (final source)

Real-stack, native-resolver acceptance (`pwsh -File scripts/dev/servana-local.ps1 uat`, final source,
2026-10-15T10:31Z): **256 passed / 0 failed / 0 flaky / 0 skipped** (15.2 min). Classification: everything
in `tests/e2e-local-uat/` is **`tested_live_laravel_postgres_redis`**; no request is intercepted.

| Family (live) | Result |
|---|---|
| Sign-in through the browser, 8 accounts (landing `/` → Log in → Mailpit → verify → MFA where mandatory → `/dashboard`) | 8 / 8 |
| Public hosts: `/`, `/login`, `/faq`, three `/legal/*` on 8 hosts; signed-out `/dashboard`; registration offered only by the Merchant Administrator landing; unknown host; health | 66 / 66 checks |
| Role workflows (§6) | 8 tests, 38 / 38 steps |
| Front Office selection flows (LUAT-D01) | 5 / 5 |
| Merchant self-registration → `/setup` → `/dashboard` | 1 / 1 |
| Authenticated contracts — 132 implemented, each by its own account on its own host | 132 / 132 (17 · 15 · 15 · 20 · 15 · 17 · 19 · 14) |
| Authenticated contracts — 28 `disabled_by_gate` shown unavailable, no live link, no working direct URL | 28 / 28 (5 · 8 · 3 · 4 · 4 · 2 · 1 · 1) |
| Cross-cutting per account: light default under a dark OS, 7 widths + 200 % equivalent, fixed footer, dark persistence, axe (0 serious/critical on `/`, `/login`, `/dashboard`, both themes) | 8 / 8 |

Only remaining page-load 4xx: Super Administrator billing settings 404 (intended, §4). The first crawl's 422
and 403 responses (LUAT-D01, LUAT-D02) are gone. One harness timing fix: the first PHP request after a
two-hour idle took > 4 s, so the sign-in redirect wait is 20 s (later requests 0.36–0.65 s).

| Repository gate (final source) | Result |
|---|---|
| Backend full suite, parallel (`php artisan test --parallel`) | 3,092 passed / 14 skipped (pre-existing environment skips: ClamAV, Redis/cache isolation, latency benchmark, staleness) / **1 failed** — this phase's own `TestDatabaseIsolationTest` asserted the config name instead of the live connection; corrected and passing serial + parallel |
| Backend focused, parallel (isolation + LUAT-D01 + LUAT-D02) | 19 passed / 97 assertions |
| Backend Docs suite after the PROGRESS/CHANGELOG edits | 96 passed / 8,755 assertions |
| Pint · Larastan level 8 | pass · pass (3 seeder nullability findings fixed first) |
| ESLint · vue-tsc · build | 0 errors (3 pre-existing `v-html` warnings) · pass · pass |
| Vitest | run 1: 1,469 passed / 2 timed out (5 s first dynamic-import timeouts while import time was 281 s); run 2, same source: **1,471 / 1,471** |
| `nav:check` · `content:check` · `assets:check` · `tokens:check` · `api:contract:check` · UI source · account hosts | all current (OpenAPI 309 paths / 360 operations) |
| UI-17 edge security contract · bundle budget | pass · pass |
| `npm audit --audit-level=high` · `composer audit` | 0 · no advisories |
| gitleaks | changed/untracked paths: **0**; whole tree: 1 = the known `tokens.generated.ts` digest false positive (file unchanged; CI fingerprint) |
| Hosts manager fixture self-test | 21 / 21 |
| Existing mocked Playwright suite (Vite preview, **`tested_mocked_fixture`**) | full: 2,305 tests — 2,216 passed / **6 failed** / 83 skipped / 0 flaky; the 6 mocked the pre-change contracts (5 × LUAT-D01 catalogue reads, 1 × LUAT-D04 legacy landing). After the mock updates, the affected specs (1,467 tests): **1,384 passed / 0 failed** / 83 skipped |

Both mocked runs rewrite historical tracked evidence (UI-01/04/05/06/08–15 screenshots and indexes: 386 and
240 files) and create 85 files; those were restored/removed exactly and the worktree was verified equal to the
pre-run snapshot. No baseline was regenerated.

## 9. Scope boundaries kept

No permission key, role grant, financial calculation, state machine, migration, legal copy or production
setting changed. Gate W stays closed; the 28 gated contracts stay inert; no Wallet / Refer & Earn / SMS
provider call, no real send, no money movement. No GitHub write by Claude. The local stack is left running
with the synthetic dataset.
