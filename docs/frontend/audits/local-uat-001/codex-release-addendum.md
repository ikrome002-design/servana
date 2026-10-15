# LOCAL-UAT-001 — Codex release addendum

Date: 2026-10-15. Scope: authenticated local-UAT code release only. Production deployment,
Gate W activation, manual owner UAT, visual acceptance and screen-reader acceptance remain excluded.

## Immutable handoff authentication

- Repository: `https://github.com/ikrome002-design/servana`; expected branch
  `phase-local-browser-uat-001`; base/initial HEAD/origin main
  `c7e963c88cced493364705e9e25ab2206862e0c7`.
- GitHub independently confirms PR #69 MERGED at `2026-10-14T16:54:14Z`, head
  `b4d28e0b641c010d3a71145ff3fedcd8c22df90f`; merge is in main ancestry.
- Original manifest: 19,141 bytes; SHA-256
  `a4ad05181bbfaf43ee20c004e568b222ad323f243e15f1e9d43dcc40076f59ae`, matching the separate handoff Markdown.
- Original handoff Markdown SHA-256:
  `f3a9500c6cd0d38e0858867c0afa8c34698e69fb49d2fe69cc2d82e5e0241bf2`.
  Claude's separate final response was not supplied to this session; this digest is independently
  calculated, not claimed as a comparison to an unseen final response.
- NUL-delimited porcelain verifier: exactly 70 paths (39 modified, 31 untracked), exact statuses,
  all 68 non-self-referential hashes/byte counts match; no extra/missing paths or symlinks;
  index empty. The two documented self-references alone have null hashes. No active Git operation
  or unmerged path. Original handoff files remain unchanged.
- `.env`, local MFA/fixture manifests, browser session/results and built SPA outputs are git-ignored.
  Gitleaks 8.30.1 scanned a temporary copy of every authenticated path: zero leaks.

## LUAT-REL-001 — native command failure hidden by lifecycle wrapper

| Field | Evidence |
|---|---|
| Observed failure | `pwsh -NoProfile -File scripts/dev/servana-local.ps1 logs __local_uat_missing_service__ 1` printed `no such service` but exited 0. Direct Docker command exited 1. |
| Root cause | `logs` piped native Docker output through redaction without checking `LASTEXITCODE`, then fell through to unconditional final success. `watch` had the same unchecked native-command path. |
| Why causal | The Docker command already reported failure; the wrapper's success was caused solely by ignoring that result. |
| Exact diff / files changed | Add `if ($LASTEXITCODE -ne 0) { $script:failed++ }` after the native commands in `scripts/dev/servana-local.ps1`; add `scripts/dev/tests/servana-local.selftest.ps1`. No service, health check, timeout or security rule changed. |
| Tests added / command | `pwsh -NoProfile -File scripts/dev/tests/servana-local.selftest.ps1`: child-process fixtures cover native success and failure for both actions, without changing Docker services. |
| Focused result | 4 passed / 0 failed. Real missing-service reproducer now exits 1 and prints `1 gate(s) failed.` |
| Proof of resolution | Identical real Docker failure now propagates to the caller; fixture successes still exit 0. |
| Full gate | Hosted Backend, Frontend, Docker, Security and E2E on the final pushed head remain mandatory; recorded on the PR, never assumed here. |
| Remaining risk | Watch failure is tested with a stub, avoiding a long-running watcher or disruption to the local stack. |

Codex-authorized paths beyond the historical manifest: this addendum, the new lifecycle self-test,
`tests/Unit/LocalUatDatasetSeederGuardTest.php`, and `composer.lock` (LUAT-REL-002 below).
The lifecycle wrapper, Front Office pagination/client/tests and generated API contracts have
post-handoff corrections detailed here. All other authenticated content stays unchanged.
Final SHA-256 values (raw working-file bytes):

| Path | SHA-256 |
|---|---|
| `scripts/dev/servana-local.ps1` | `78298f055d0c79ca33e279a7ef52d43d806bd4776a3567e6d3c1b5f105d23a67` |
| `scripts/dev/tests/servana-local.selftest.ps1` | `df090ffdf50d5e166f9a43c96313a674a9363c97974b93caae14d511cf1b2101` |
| `composer.lock` | `0212cfdbf17e7acade4ed5954ee128d5745bf3ff1e7d2142e6b6804e78ebee97` |
| `tests/Unit/LocalUatDatasetSeederGuardTest.php` | `7cbf41c404cf321c9a117aa754df0af51e402901551571bd618687795444d57e` |

This addendum does not hash itself. Its committed blob/tree and the PR receipt attest its final bytes.

## LUAT-REL-002 — Composer audit blocks publication

| Field | Evidence |
|---|---|
| Observed failure / reproducer | `docker compose exec -T app composer audit` and `composer audit --locked --format=json` both exit 1: two advisories affecting two packages, no ignored/filtered advisories. |
| Root cause | Both installed and locked `symfony/http-foundation` and `symfony/yaml` are 7.4.13. Current advisory metadata reports PKSA-xmwy-rmx5-m7h4 / CVE-2026-108082 (HeaderUtils Cache-Control ReDoS; fixed from 7.4.19), and PKSA-xz3r-9gp4-4m1z / CVE-2026-109413 (YAML inline lexer recursion; fixed from 7.4.18). Severity fields are null; no severity is invented. |
| Why causal | The audit's affected version ranges contain the two locked versions; installed/lock parity rules out a stale vendor-only failure. This invalidates reuse of the earlier clean audit, not Claude's historical record. |
| Exact diff / files changed | `composer update symfony/http-foundation symfony/yaml --no-install --no-scripts --no-interaction --minimal-changes` updates only those packages to 7.4.21. `composer.lock` has 12 inserted / 12 removed lines (versions, source/dist refs, source links, release times). Content hash, constraints, Laravel version and every other package are unchanged. |
| Focused checks | Targeted dry run first: exactly two patch updates, zero installs/removals. Updated locked audit: no advisories, exit 0. Installed-package regression results recorded below after installation. |
| Full gate | Unchanged hosted five-job workflow must pass on the resulting lockfile and final source head, including Composer audit. |
| Proof / remaining risk | Version-range exclusion and the clean locked audit prove remediation in the dependency graph. Full application and Docker checks remain required; no advisory suppression or audit policy change. |

## LUAT-REL-003 — unbounded Front Office lookup collections

| Field | Evidence |
|---|---|
| Observed failure / requirement | Plan §23 requires pagination (default 25, maximum 100) for unbounded collections, or a documented bounded lookup maximum. Both new controller actions used `get()` without a bound; the personnel action then sorted the entire collection in memory. The client made only one request. No catalogue/personnel-count invariant bounds those queries. |
| Root cause / why causal | The new endpoints copied an existing lookup precedent without applying the binding collection contract. Merely bounding the response would omit later picker choices because the client returned only the first `data` array. |
| Exact diff | Both Form Requests reuse `ApiPagination::rules()` and validate page >=1. Both actions paginate using `ApiPagination::perPage`; service name/ULID ordering stays stable, personnel name ordering moves to a tenant-scoped staff subquery with staff-ID tiebreaker. The client gathers every page with unchanged purpose and AbortSignal, and propagates later-page failures. Existing single-page fixture adapters remain compatible. API/TS projections are regenerated. |
| Files changed | `FrontOfficeSelectionController.php`, both `FrontOffice*OptionRequest.php`, `FrontOfficeSelectionApiTest.php`, `frontOfficeSelections.ts` and its spec, generated OpenAPI/TS artifacts. No permission, policy, role grant, route or financial rule changed. |
| Tests added | Backend: 26 services span pages 25+1, both endpoint limits reject 101 and page 0, both honor per_page=1. Client: service and personnel page aggregation, same cancellation/purpose on later pages, later-page failure rejects incomplete results. |
| Retained failed attempts | First backend fixture exhausted ServiceCategoryFactory's finite unique-name pool (0 assertions), corrected by reusing the scenario category. Two local Vitest attempts failed before worker startup (60-second fork-worker timeout, 0 tests); neither is claimed as an assertion failure or successful red test. Host free memory diagnostic: 690,208 KiB out of 8,282,708 KiB. No runner settings/timeouts were changed. |
| Focused/full result | Final PostgreSQL/security regression set: 54 passed / 257 assertions. Changed PHP Pint and level-8 analysis pass; diagnostic client tests 6/6 pass. Five-job exact-head CI remains mandatory. |
| Remaining risk | Picker completeness spans multiple bounded requests rather than an atomic catalogue snapshot; mutations still revalidate every choice server-side. |

Final LUAT-REL-003 raw-file attestation (original manifest remains immutable):

| Path | Bytes | SHA-256 |
|---|---:|---|
| `app/Http/Controllers/Api/V1/FrontOffice/FrontOfficeSelectionController.php` | 4515 | `6107a6823105a8b5be64dcf5501aa6dacd79bfd681610ca8c8030870d1e31253` |
| `app/Http/Requests/FrontOffice/FrontOfficePersonnelOptionRequest.php` | 1568 | `e792fe50c5b6eaa96cccdeb53cd9c1d277edc196e51158d366c6c656d0846d0f` |
| `app/Http/Requests/FrontOffice/FrontOfficeServiceOptionRequest.php` | 1310 | `c22ac4a581bbb29872c831dd4eeb11d808d62cfe34d955556540ff47b2697c85` |
| `tests/Feature/FrontOffice/FrontOfficeSelectionApiTest.php` | 12916 | `4717f49f84b4ce5a4000a024bb47734d8bc1256742ff0e8587ae898fe339da3f` |
| `resources/spa/src/services/frontOfficeSelections.ts` | 2376 | `26bf074065940f032eda46f6ef899af99c7645f546e8e0e549ef7e87ae4449af` |
| `resources/spa/src/services/frontOfficeSelections.spec.ts` | 3480 | `0a75ecff6f48bfb949ec7585ef087d53e156f6d9c2a1483b8eed6b9c562a1255` |
| `docs/api/openapi.json` | 2646723 | `556535779ef8acc6dcad02d6031e1889b342842b595510fe8b0b2c3ab696f87f` |
| `resources/spa/src/types/generated/api.ts` | 1044015 | `56c4873399c9d05c228617e11da35479cf904c2e61e060eb2ee9b49062ba4ffd` |

## LUAT-REL-004 — stale nginx upstream after PHP restart

| Field | Evidence |
|---|---|
| Observed failure | First post-patch real browser smoke: 5 passed, 3 failed, 71 dependent cases did not run, 0 retries. The first three accounts reached nginx's 502 page and timed out waiting for the account-context element. Direct Windows HTTP probe also returned 502. |
| Root cause / why causal | App was healthy at Docker IP `172.18.0.11`; nginx errors showed refused FastCGI connections to the previous `172.18.0.7:9000`. `docker/nginx/default.conf` lines 115/267 use static `fastcgi_pass app:9000`, resolved at configuration load. The PHP-only restart had changed the app IP while nginx retained its previous resolution. |
| Precise fix / files changed | `scripts/dev/servana-local.ps1` Wait-Healthy reloads nginx once after app reports healthy and nginx is running, then additionally requires live `/health` HTTP 200 before success. Existing four-minute readiness bound and three-second polling remain unchanged. No production nginx configuration, DNS, TLS, cookie or security policy changed. |
| Runtime correction | `docker compose exec -T nginx nginx -s reload` preserves container/logs. An immediate probe still returned 502 during graceful reload; subsequent probe returned 200, confirming why a real health probe is necessary rather than assuming the reload signal completes synchronously. |
| Tests | Lifecycle fixture self-test expanded to 8 passing cases: original logs/watch success/failure plus start/restart reload ordering and reload failure propagation, all native tools/HTTP stubbed. Actual corrected `start` workflow: all 29 gates passed, seven deep dependencies healthy, all eight host roots 200. Existing MinIO initializer reran idempotently; no volume/data removed. |
| Retained proof | Failed smoke JSON `test-results/local-uat/codex-smoke-5d49723975b340ab8cbb97fa38c21a64.json`; failure screenshots/context copied to a separate ignored directory before rerun. Original Claude report and public-host results restored byte-for-byte; no failed test was skipped to obtain green. |
| Full gate / remaining risk | Fresh real smoke: 79 passed / 0 failed / 0 skipped / 0 flaky / 0 retries (4.0 minutes), native resolver, all eight sign-ins, 66 public-host checks and all five affected Front Office flows. All five exact-head hosted jobs remain required. Direct manual PHP container restarts still require nginx reload or the corrected start workflow; status remains read-only and truthfully fails on 502. |

## Verification and retained history

- Local stack status: PostgreSQL, Redis, Meilisearch, MinIO, Mailpit, app, nginx and all workers running;
  deep app/database/Redis/cache/queue/search/S3 health all pass. All eight Windows-resolved host roots
  return 200 on port 8080 at preflight. After installing the two dependency patches, Codex restarted
  only app, worker, file-worker and scheduler to reload PHP code. PostgreSQL, Redis, nginx, search,
  storage and Mailpit were not stopped; no volume or nginx log was deleted. This restart interrupted
  an overlapping OpenAPI-generation command and focused test command before results; both attempts
  exited nonzero and are retained as orchestration errors. They were restarted sequentially after
  all four services reported Started. Final post-reload health/browser results are recorded below.
- Original local browser JSON report inspected independently: start `2026-10-15T10:31:45.727Z`,
  duration 913,635 ms, 256 expected/pass, 0 unexpected, 0 skipped, 0 flaky; resolver
  `hosts (ordinary browser)`. Reused because the only Codex implementation change affects logs/watch,
  not browser/runtime/auth code. This is agent automation, not manual owner acceptance.
- Current generated matrix: 160 contracts = 132 implemented + 28 disabled. By account
  (implemented/gated): Super Admin 17/5, Merchant Admin 15/8, Branch 15/3, HR 15/4,
  Finance 20/4, Front Office 17/2, Personnel 19/1, Audit 14/1.
- Before LUAT-REL-003: focused Vitest 4 files, 17 passed. ESLint: 0 errors, 3 inherited `v-html` warnings.
  Typecheck and production build passed. UI-source, navigation and API parity guards passed
  (309 paths / 360 operations). Hosts fixture self-test: 21 passed.
- Focused PostgreSQL feature/isolation suite before dependency installation: 19 passed / 97 assertions
  in 479.77 seconds; first migration setup took 455.51 seconds. PostgreSQL diagnostic showed the
  dedicated `servana_test` connection doing disk synchronization, not a developer-database connection.
- Added seeder safety regression: `php artisan test tests/Unit/LocalUatDatasetSeederGuardTest.php`
  passed 4 tests / 12 assertions, plus Pint for the new file. Public `run()` refuses a non-local
  application environment, remote database host, production database name and staging database name
  before any query is executed. This supplies executable negative controls for the local-only seed claim;
  it creates or deletes no data and changes no production seeder behavior.
- Source qualification: the local cross-cutting spec runs axe on landing/light, login/light and
  dashboard/light+dark. Its header and the original proof describe broader both-theme coverage;
  that broader claim is not established by this local spec. Existing hosted UI-16/17 gates remain
  mandatory. No historical handoff file was rewritten to conceal this distinction.
- Local Composer-wrapped Pint hit its 300-second process watchdog on the Windows bind mount.
  This failure is retained; direct `vendor/bin/pint --test` rerun passed all 1,950 files.
  Remaining local checks are recorded below before commit.
- Baseline full Larastan level 8: all 1,494 source files, no errors (before LUAT-REL-003).
  The subsequent Composer audit could not resolve repo.packagist.org/packagist.org (curl error 6);
  this external DNS failure is retained separately from the earlier two-advisory result and clean locked audit.
- Updated dependencies installed successfully: exactly two upgrades; installed and locked Composer audits
  both report no advisories (exit 0). The inherited ProbeTenantJob PSR-4 fixture warning appeared during
  optimized autoload generation and is not reported as a security finding.
- LUAT-REL-003: generated OpenAPI/TS contracts refreshed (same 360 production routes), changed PHP
  Pint 5/5 files pass; focused Larastan level 8 3/3 source files reports no errors. Client tests: 6/6 pass
  using an explicitly diagnostic `--pool=threads` invocation, unchanged assertions. This does not
  replace normal-runner acceptance; canonical hosted Vitest remains mandatory. No repository runner
  configuration or timeout was altered to conceal the two local fork-start failures.
- Final backend command (all following paths in one `php artisan test` run): FrontOfficeSelectionApiTest,
  QueryStringBooleanFilterTest, TestDatabaseIsolationTest, LocalUatDatasetSeederGuardTest,
  MagicLinkHostBindingTest, AccountHostDoesNotAuthorizeTest, PermissionMatrixParityTest.
  Result: **54 passed / 257 assertions / 0 failed / 0 skipped**, 223.77 seconds, patched dependencies,
  dedicated PostgreSQL test database. Includes new 25+1 collection pagination and unsafe-target refusals.
- The subsequent full ESLint run reported one new `no-useless-assignment` error for the initial
  `lastPage = 1` value overwritten inside the do-loop. Replaced it with a typed declaration;
  focused ESLint for the client and spec passes. Existing three `v-html` warnings remain unchanged.
- Final typecheck and production bundle build pass. Structural JSON comparison to the base confirms
  generated contract changes are limited to the two added Front Office paths and their two resources;
  there is no unrelated generated-path/schema drift.
- Final native-resolver live report: `test-results/local-uat/codex-smoke-8cd329b216a842e89d9e6290aa7f2210.json`
  (ignored), 79/79 pass, no failures/skips/flakes/retries. The same real nginx/Laravel/PG/Redis/worker/Mailpit
  integration handles all eight account sign-ins (MFA where required) and five persisted Front Office flows.
  Original Claude report and public-host output are restored, not replaced with this narrower smoke.
  All 29 actual startup gates pass after the upstream-refresh correction. The stack remains running.
- Claude's full-suite failures/retries/skips remain unchanged in the original proof §8 and manifest:
  backend isolation assertion correction; two initial Vitest import timeouts; six initial mocked-browser
  contract failures followed by affected-spec passes; 83 capture-only skips. No clean full hosted run
  is inferred from those partial reruns.

## Governance and external gates

Plan §§75–76, 81–82 and UI/UX §§4, 21, 24, 26, 28 govern this release. Live `Protect main`
ruleset 17624771: PR required, four strict checks required, zero required GitHub approvals,
no bypass actors, current user bypass `never`; merge/squash/rebase allowed. This release additionally
requires the complete E2E job. No existing phase PR found at preflight.

Plan §76 says security-sensitive PRs require a second reviewer. This release adds purpose-bound
authorized API reads and changes authentication destinations. Earlier versioned solo-maintainer
exceptions explicitly apply only to their named PRs. Do not infer a standing exception or independent
approval from zero-approval GitHub rules. Resolve the actual review requirement before merge;
record any explicit product-owner decision on the concrete PR without inventing a reviewer.

GitHub release is pending as of this source commit. Exact-head CI, actual reviews, merge strategy,
merge/tree/ancestry checks and cleanup belong in the authoritative PR receipt after they happen.
No direct main documentation commit or circular CI-head update is needed.

Deferred work retains its existing owners and entry conditions in PROGRESS/runbook/proof:
manual whole-product UAT; 82-page aesthetic approval; UI-17 D1–D8 and NVDA/VoiceOver;
Gate W / 20D-W / 21R-B / 21N; DNS/TLS and Phase 25 P1–P10; existing remediation records;
LUAT-F01, F03 and F04 owner decisions. No production deployment authorized.
