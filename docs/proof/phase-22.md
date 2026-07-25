# Phase 22 Proof — Search

> Plan sections implemented: **§68** (Search) and **§80 Phase 22** (Correction 16; security), under
> **§64** (personnel served-client rules), **§73** (threat model — RK-05 personnel contact
> exfiltration, cross-tenant/branch access, over-privileged staff), **§74** (privacy, masking,
> retention), **§24.5** (log redaction), **§23/§24.1–24.2** (API contract, route classification,
> pagination/allowlisted sort), **§19.4** (non-overridable "Personnel can never gain contact
> export"), and **ADR-010** (personnel contact protection).
>
> Remediation items: none closed by this phase. **REM-DEP-002 opened** (npm high-severity advisory
> gate — pre-existing upstream drift that Phase 22 discovered but did not cause; it **blocks the
> Phase 22 PR's Frontend check** until remediated on its own branch — see §9 item 1).
> **REM-SMS-002** and **REM-RE-002** remain open (deferred live-provider verification, before
> Phase 25).

---

## Lifecycle

| Field | Value |
|---|---|
| Phase | 22 — Search |
| Status | `local_complete pending PR CI/review/merge` — with **one gate blocked by a pre-existing dependency condition** (`REM-DEP-002`, §9 item 1) |
| Branch | `phase-22-search` |
| Base commit | `d8a7a15603c22e41354e570f4d2735935468d973` (Phase 21S PR #45 merge commit == `origin/main`) |
| Predecessor | Phase 21S (PR #45, merged) — reconciled `local_complete → verified_complete` in this branch |
| Specification | [`search-catalogue.md`](../architecture/search/search-catalogue.md) · [`search-indexing.md`](../architecture/search/search-indexing.md) · [`search-security.md`](../architecture/search/search-security.md) |

---

## 1. Preflight and predecessor verification (read-only, before any file change)

Executed on clean `main` before the branch was created.

| Check | Result |
|---|---|
| `git fetch origin --prune` | ok |
| `git fsck --full` | exit 0; two benign dangling objects (blob `cb0a299`, commit `fd95305`) |
| `git diff --check` | clean |
| Branch / HEAD | `main` @ `d8a7a15603c22e41354e570f4d2735935468d973` |
| `origin/main` | `d8a7a15603c22e41354e570f4d2735935468d973` |
| `merge-base origin/main HEAD` | `d8a7a15603c22e41354e570f4d2735935468d973` |
| `git rev-list --left-right --count origin/main...HEAD` | `0 0` |
| Working tree / staged (`--untracked-files=all`) | clean / empty |

### Phase 21S PR #45 — verified complete

| Field | Value |
|---|---|
| PR | [#45](https://github.com/ikrome002-design/servana/pull/45) "Phase 21S: Implement personnel bulk SMS" — `MERGED`, base `main`, head `phase-21s-personnel-bulk-sms`, not draft, merged `2026-07-23T09:13:10Z` |
| Implementation commit | `9d2c547a4a8e8af76a80bc138ae0b608e448dfe7` |
| CI-fix commit | `34a5921ca5b2f4502e20172c10ed472d7d416954` |
| Final PR head | `dc48d095529757dd1282ad5a8659e8e087cbc2a8` (empty PR-ref resync) |
| Merge commit | `d8a7a15603c22e41354e570f4d2735935468d973` (== `origin/main`) |
| Final CI run | [29992575586](https://github.com/ikrome002-design/servana/actions/runs/29992575586) — `pull_request` on `dc48d09…`, `completed` / `success` |
| CI jobs | Backend — Pint, Larastan, Pest **SUCCESS**; Frontend — ESLint, vue-tsc, Vitest, build **SUCCESS**; Docker — build images **SUCCESS**; Security — gitleaks **SUCCESS**; E2E — Playwright **SUCCESS** |
| Governance | [PR #45 comment 5056479540](https://github.com/ikrome002-design/servana/pull/45#issuecomment-5056479540) — contains the solo-maintainer heading, the final head, the run id, "This is not independent reviewer approval", and "Gate W remains closed" |
| `reviewDecision` | **blank** under that PR-specific exception — **not** independent reviewer approval |
| Branch cleanup | local **and** remote `phase-21s-personnel-bulk-sms` both absent |

Each of the three commits appears exactly once in the PR commit list.

### External Gate W — re-verified **CLOSED** before branch creation

| Path | State |
|---|---|
| `docs/integrations/wallet/gate-w-evidence.md` | **absent** |
| `docs/integrations/wallet/` | **absent** |
| `docs/proof/phase-20d-w.md` | **absent** |
| `docs/integrations/` | present, containing **only** `refer-earn/` (21R-A contract pins, credentials receipt, 6 event schemas) |

Consistent with `docs/PROGRESS.md` row 20D-W. Consequences under live Plan §80.1 (line 2517) and
§80.2:

- **20D-W** blocked — Gate W closed; Plan §0 forbids stubbing or partially implementing a Wallet
  capability.
- **21R-B** blocked — entry criteria are `21R-A + 20B + 20D-W + 16C + 18B`, and 20D-W is blocked.
- **21N** blocked — dependency `(17,18,20D-W) → 21N`.
- **Phase 22 executable** — `→ 22` follows the 21S clause in the dependency graph, 21S is
  `verified_complete`, and §68 has no Wallet dependency.

---

## 2. Source-of-truth findings

### F-1 — The directive's "Plan §21 Search Strategy" does not exist in the live Plan

Live **§21** is *"Merchant Operational-Status Enforcement"* (Plan line 1620). The Plan's **only**
Search text is **§68** (line 2347, a single sentence) and the **Phase 22 roadmap entry** (lines
2696–2699). The following details cited in the phase directive appear **nowhere** in the Plan:
`GET /api/v1/search`; "TenantContext injects mandatory tenant/branch filters"; "the SPA never holds
a search API key"; "index sync uses queued Scout jobs"; "a `scout:verify-counts` drift check exists
as a command"; "Front Office speed search can use PostgreSQL indexes for exact phone/number lookups
and Meilisearch for fuzzy name". The `AS-8` reference in `.env.example:117` likewise does not exist
in the Plan.

**Resolution — no product-owner input required.** Live Plan §68 + the Phase 22 roadmap entry are
binding (hierarchy ranks 4). The additional detail is compatible with §68 and strictly *safer* than
it, so it is adopted as **product-owner design intent at hierarchy rank 12** and implemented. It is
recorded here with its true provenance and is never cited as a Plan section anywhere in the code or
docs.

### F-2 — The anticipated privacy tension does not exist

The directive warned that "older search strategy text may mention indexing client phone/email".
Live §68 says nothing of the kind. §9 rule 6 (line 124), §64 (line 2314), §73 RK-05 (line 2770),
§74 (line 2368) and ADR-010 all point the same way, so the strict resolution — no raw or encrypted
phone/email, no `phone_index` or blind-index value indexed or returned; masked display only;
authorized exact phone lookup solely through the existing server-side blind index; personnel
served-client search name-only/own-scope — stands **unopposed**. Phase 22 goes one step further and
returns **no contact field of any kind** from search (see D-22-03).

### F-3 — No Phase 22 search permission key exists (escalated; resolved by the product owner)

`docs/auth/permission-matrix.yaml` holds **130 active + 38 planned** keys. All 38 planned keys were
enumerated with their `owning_phase`: 20A (9), 20B (4), 20D-W (4), 20F (9), 21N (2), 21R-A (1),
21R-B (2), 23 (1). **None is owned by Phase 22.** No `search.*`-prefixed key exists in the matrix,
`PermissionRegistry`, `permissions.ts`, or the Plan. The only search key in the product is
`front_office.search` (**active**, scope `branch`, `default_roles: [front_office]`,
`billing_read_only_behavior: allow_read`, "Search clients (branch-scoped, masked)").

This was escalated to the product owner before any implementation. Resolution: **D-22-01** below.

### F-4 — Two pre-existing conditions, recorded and left alone

1. `staff.view` is `implementation_status: planned` with `owning_phase: Phase 20F`, although Phase
   20F is `verified_complete`. Phase 22 does not activate it (it is not Phase 22's key) and does
   not depend on it.
2. `GET /api/v1/staff` (`staff.index`) performs no `authorize()` call and carries no
   `EnsurePermission`; only the detail route is policy-gated. Phase 22 anchors `staff` search on
   the **stricter** detail-route authority, so it neither relies on nor widens that gap.

Neither is in Phase 22's scope; both are noted so a future phase can act on them deliberately.

---

## 3. Decisions

### D-22-01 — Search endpoint gate has no new permission; it intersects existing per-type authority

**Observation.** The live permission matrix has 130 active / 38 planned keys. No key is owned by
Phase 22. No `search.*` key exists anywhere. No planned global search key exists.

**Question.** How should `GET /api/v1/search` be gated?

**Decision.** Do not invent a new permission key. Do not broaden `front_office.search`. Gate the
route by authentication, tenant context, active membership, and the existing `search` rate limiter.
Authorize *results* by intersecting the existing per-type permissions, role boundaries, branch
scope, own-scope rules, billing/read-only rules and masking rules that already govern each type's
own list/detail route.

**Reason.** Phase 22 search is an aggregator, not an independent data authority. Search must never
return more than the caller can already reach through existing canonical routes. A global
permission would create matrix / registry / generated-contract churn without a Plan-defined key —
and the hand-maintained `PermissionMatrixTest::expectedMatrix()` and
`PermissionDatabaseProjectionTest` fixtures are exactly what broke PR #43's first CI run.
Broadening `front_office.search` would change an existing role-specific permission's semantics and
leak Front-Office client-search semantics to roles that should not hold it. An empty result set for
callers with no searchable permissions avoids an existence oracle over the search catalogue.

**Result.**
- No permission-matrix key is added.
- No `PermissionRegistry` key is added.
- No database permission-projection row is added.
- No generated `permissions.ts` search key is added.
- No `docs/proof/phase8-matrix.txt` search row is added.
- No route result depends on frontend authorization.
- Active/planned counts stay **130 / 38**.

**Route-level rule.** `GET /api/v1/search` is an authenticated, tenant-scoped aggregator route. It
grants access to no document type. It can only return a document type after the server proves the
caller already has the existing authority for that type.

**No route-security exception is needed.** `RouteSecurityContractTest` constrains **non-GET**
routes only (`isNonGet()`), so a GET read route requires no classification and no
`EnsurePermission` — exactly like the existing `clients.index`, `appointments.index`,
`queue.index`, `services.index` and `staff.index` reads, which authorize in the controller through
their policies. The search aggregator follows that established house convention rather than
introducing an exemption. This closes the directive's stop-condition
"RouteSecurityContractTest cannot allow the search route exception without a broader architecture
decision" — no exception exists to allow.

### D-22-02 — Substrate is Meilisearch via Laravel Scout, and the pre-existing suite is decoupled from it

`docker-compose.yml` already runs `getmeili/meilisearch:v1.10`; `.env.example` already declares
`SCOUT_DRIVER=meilisearch` under the comment "Wired with Scout in Phase 22". Scout itself was
absent, so Phase 22 adds the minimum: `laravel/scout`, `meilisearch/meilisearch-php`,
`http-interop/http-factory-guzzle`, and a hand-written `config/scout.php`.

`phpunit.xml` sets `SCOUT_DRIVER=null` with `force="true"`, so the 2006 pre-existing backend tests
acquire **zero** indexing coupling — no network call, no async-index wait, no behaviour change. The
Phase 22 tests that must exercise the real engine opt in explicitly, and CI gains an ephemeral
`getmeili/meilisearch:v1.10` service so that proof runs in CI and not only locally.

### D-22-03 — Search returns no contact field of any kind

Rule 2 of the catalogue ("never more revealing than the safest existing resource") permits masked
contact for the types whose Resources already return it (`AppointmentResource`,
`QueueEntryResource`, `ServiceSessionResource` and `InvoiceResource` all return
`client.phone_masked` + `phone_last_four` today). Phase 22 nevertheless emits **none of it**: the
search response schema has no `phone`, `phone_masked`, `phone_last_four`, `email` or `email_masked`
key at all. This makes the contact-protection invariant a *schema* property rather than a
per-branch conditional, which is both stronger and trivially testable. `ReceiptResource` exposes no
client, so `receipt` search documents carry no client name either.

### D-22-04 — The aggregator returns a bounded top-N per type; `meta.next_cursor` is always `null`

Deep pagination stays with each type's own canonical list route, which already paginates
(Plan §23). A cross-type cursor would have to encode per-index offsets and would invite exactly the
"fetch broad, filter later" pattern §68 forbids.

### D-22-05 — The drift-check command ships; its *scheduled* invocation is Phase 21N's

`servana:search-verify-counts` exists and is runnable. No scheduler entry is added, because queue
topology, the scheduler and scheduled reporting are Phase 21N's scope (Plan §80.1
`(17,18,20D-W) → 21N`) and 21N is blocked behind Gate W.

### D-22-06 — `served_client` is searched in PostgreSQL, never indexed

Indexing "clients this staff profile served" would require a derived, high-churn
`served_by_staff_profile_ids` array on the client document whose staleness would be an own-scope
leak. Phase 21S's `ServedClientSelector` is already the authoritative, tested definition of
"personally served" (at least one COMPLETED session performed by that staff profile, merchant and
branch pinned explicitly in the `EXISTS` sub-query, name-only search with LIKE metacharacters
escaped). Search reuses it verbatim.

---

## 4. Pre-implementation inventory

### 4.1 Search substrate (before Phase 22)

| Artefact | State at entry |
|---|---|
| `laravel/scout` in `composer.json` / `composer.lock` | **absent** |
| `meilisearch/meilisearch-php` | **absent** |
| `config/scout.php`, `config/search.php` | **absent** |
| `docker-compose.yml` | `meilisearch` service present — `getmeili/meilisearch:v1.10`, healthcheck, port 7700, `servana-meili` volume, container **running** |
| `docker-compose.prod.yml` | no Meilisearch service (only a comment about bounded timeouts) |
| `.env.example` | `SCOUT_DRIVER=meilisearch`, `MEILISEARCH_HOST`, `MEILISEARCH_KEY` present under "Wired with Scout in Phase 22" |
| `.github/workflows/ci.yml` services | `postgres:16` + `redis:7` only — **no Meilisearch** |
| `RateLimiter::for('search', …)` | **present** at `app/Providers/AppServiceProvider.php:416` — 60/min per principal, defined but used by no route |
| `/api/v1/search` route | **absent** |
| `app/Domain/Search/` | **absent** |
| Search-related console commands | **absent** (7 commands total, none Scout-related) |
| `php-http/discovery` in `allow-plugins` | already present |

### 4.2 Permission inventory

Recorded in F-3 above. Per-type authority anchors are in `search-catalogue.md` §3.3. All anchor
keys are **active** today: `client.view`, `front_office.search`, `service.view`,
`appointment.view`, `branch.dashboard.view`, `queue.view`, `service_session.view`, `invoice.view`,
`receipt.view`, `personnel.my_served_clients.view`, `branches.manage_users_lifecycle`,
`staff.suspend`.

### 4.3 Contact substrate available for the authorized exact-phone path

| Artefact | Role |
|---|---|
| `app/Domain/Clients/Support/ClientContactIndex.php` | keyed HMAC-SHA256 blind index over the normalized phone (`CLIENT_CONTACT_INDEX_KEY`, HKDF fallback outside production); one-way; `phone_index` is `$hidden` and log-redacted |
| `app/Domain/Clients/Support/PhoneNumberNormalizer.php` | deterministic E.164 normalization (Kenyan-first) |
| `app/Domain/Messaging/Sms/Support/PhoneNumberDisplayMasker.php` | masked display helper (21S) |
| `ClientController::index` + `ClientIndexRequest` | the live precedent: branch-scoped search by name **or** normalized phone via the blind index, gated by `front_office.search`, sorts allowlisted away from contact columns |

No decrypted-phone scan exists anywhere in the codebase, and Phase 22 adds none.

### 4.4 Frontend inventory

| Artefact | State at entry |
|---|---|
| Global search slot in any of the 8 layouts | **absent** |
| `docs/frontend/screens/inventory.json` | 123 screens — 97 `implemented`, 18 `phase_11`, 8 `planned`. **None of the 8 planned screens is a search screen** (merchant-profile, merchant-reports, branch-calendar, branch-reports, hr-eligibility, platform-wallet-config, platform-billing-reconciliation, platform-audit-reports) |
| `docs/frontend/navigation/role-navigation.yaml` | no `search` entry |
| Existing in-page search | `ClientList.vue` (client directory), `ClientSms.vue` (21S served-client name search), `WalkInCreate.vue` — all per-screen, none global |

Per the directive §11.4, with neither a layout slot nor a planned screen present, Phase 22 adds the
smallest route/component consistent with the current UI architecture and documents it as the
Phase 22 search surface.

### 4.5 Constraints the implementation must satisfy

- `TenancyStaticAnalysisTest`: no `withoutTenancy`/`withoutGlobalScopes` outside Tenancy/Platform;
  no `::find()` in controllers; no raw SQL built by concatenation or interpolation.
- `MerchantScope` / `BranchScope` are **no-ops when no merchant is resolved**, so out-of-request
  code (the reindex command, queued index jobs) must pin predicates explicitly — the pattern
  `ServedClientSelector` already uses.
- `RouteSecurityContractTest` classifies **non-GET** routes only.
- Generated artefacts (`docs/api/openapi.json`, `resources/spa/src/types/generated/api.ts`,
  `resources/spa/src/types/generated/permissions.ts`) are produced by their own generators and must
  never be hand-edited.

---

## 5. Scope boundary

**Not built, and asserted absent rather than merely omitted:** Wallet payment search, Wallet
provider logs, Daraja/STK/C2B/PayBill/Till search, R&E reward/referrer/campaign/qualification
search, integration-payload search, notification-centre search, scheduled-report search or
delivery, report catalogue or engine, exportable search results, CSV/XLSX/PDF search export,
print/copy/download of search results, full-phone or email search for Personnel served clients,
global unscoped search, any frontend Meilisearch client, any frontend-held Meilisearch key,
analytics dashboards, Phase 23 release-wide audit work, Phase 24 performance work beyond the
Phase 22 search checks, and Phase 25 deployment work.

Search result navigation links only to pages that already exist, and only when the caller holds the
underlying permission. No new business-workflow screen was created because a result type exists.

---

## 6. Implementation record

### 6.1 Substrate

| File | Change |
|---|---|
| `composer.json` / `composer.lock` | added `laravel/scout ^10.0` (v10.25.0), `meilisearch/meilisearch-php ^1.10` (v1.16.1), `http-interop/http-factory-guzzle ^1.2`; `composer audit` reports no advisories |
| `config/scout.php` | **new**, hand-written: driver, environment-derived `prefix`, dedicated `search-index` queue, `after_commit: true`, `soft_delete: false`, **`identify: false`**, and per-index settings for all seven indexes (searchable/filterable/sortable/displayed) |
| `phpunit.xml` | `SCOUT_DRIVER=null` + `SCOUT_PREFIX=servana_testing_` (both `force="true"`) — D-22-02 |
| `.github/workflows/ci.yml` | added a `getmeili/meilisearch:v1.10` service to the Backend job, a readiness probe, and `MEILISEARCH_HOST`/`MEILISEARCH_KEY` on the test step, so the engine tests run in CI rather than only locally |

### 6.2 Backend

| Area | Files |
|---|---|
| Enums | `app/Domain/Search/Enums/SearchDocumentType.php` (the request allowlist), `SearchSort.php` |
| DTO | `app/Domain/Search/DTO/SearchContext.php`, `SearchResultItem.php` |
| Contract | `app/Domain/Search/Contracts/SearchDocumentDefinition.php` |
| Definitions | `AbstractSearchDocumentDefinition.php` (the three-layer fetch flow) + `Client`, `Staff`, `Appointment`, `QueueEntry`, `ServiceSession`, `Invoice`, `Receipt`, `ServedClient` |
| Services | `SearchDocumentCatalogue.php`, `SearchScopeResolver.php`, `SearchQueryParser.php`, `MeilisearchCandidateResolver.php`, `ClientPhoneLookup.php`, `SearchService.php` |
| Support | `SearchPhoneCandidate.php`, `SearchLikeTerm.php`, `SearchIndexName.php` |
| Indexing | `app/Domain/Search/Concerns/SearchableDocument.php` + a one-line `use` in each of the seven indexed models (2-line diff per model; no other model change) |
| HTTP | `app/Http/Controllers/Api/V1/Search/SearchController.php`, `app/Http/Requests/Search/SearchRequest.php`, `app/Http/Resources/SearchResultResource.php` |
| Route | `routes/api.php` — `GET /api/v1/search` (`search.index`) with `throttle:search`, inside the authenticated + `ResolveTenantContext` + `EnsureMerchantActive` group; **no** `EnsurePermission`, **no** `RouteClass` (GET) |
| Commands | `app/Console/Commands/SearchReindexCommand.php`, `SearchVerifyCountsCommand.php` |
| Contracts regenerated | `docs/api/openapi.json` (288 → **289** operations), `resources/spa/src/types/generated/api.ts`; `permissions.ts` **unchanged** (D-22-01) |

### 6.3 Frontend

| Area | Files |
|---|---|
| Store | `resources/spa/src/stores/searchStore.ts`, `searchScope.ts` (the request allowlist as a type) |
| Screen | `resources/spa/src/pages/search/GlobalSearch.vue` |
| Route | `resources/spa/src/router/routes/search.ts` (`search` → `/search`, `requiresAuth` + `requiresActiveMerchant`), registered in `router/index.ts` |
| Navigation | `roleNavigation.ts` — one `Search` item for each of the seven merchant-side roles, **no `permission`** (there is no key to gate on); `role-navigation.yaml` regenerated by its own snapshot test. Deliberately **not** added to the Super-Administrator list: a platform user has no merchant tenant context, so the tenant-scoped aggregator would always be empty for them — a dead end, not a nav item |
| Inventory | `docs/frontend/screens/inventory.json` (+1 → 124 screens), `inventory.yaml` regenerated, `docs/frontend/screens/search/global-search.md` generated by `scripts/generate-screen-specs.mjs` |

### 6.4 Findings the tests forced, and the product changes they caused

| # | Finding | Root cause | Fix |
|---|---|---|---|
| F-5 | `ClientSearchDefinition` pointed at `front-office.client-detail`, a route that does not exist | The SPA route is `front-office.clients.detail`; guessed rather than verified | corrected; `SearchApiTest` now asserts the exact route name, so a broken link fails |
| F-6 | `meta.query` echoed a phone number straight back into the response body | The endpoint echoes the term, and a phone-like term is a phone number | a phone-like term is redacted to `•••` in `meta.query`; non-phone terms still echo. `SearchPhoneLookupTest` covers both halves |
| F-7 | Scout composed a **second** identifier attribute (`ulid`) into every real index document, breaking the "exactly the declared keys" invariant | Scout builds `[getScoutKeyName() => getScoutKey()] + toSearchableArray()`, and the key name was `ulid` while the builders emit `id` | `getScoutKeyName()` returns `id`, so Scout's key and the builder's key are the same attribute. Proven against the REAL engine by `SearchEngineIntegrationTest` ("composes a real index document with exactly the declared keys") |
| F-8 | An index that has documents but no synced settings makes search **silently return nothing** | Meilisearch rejects a filter on a non-filterable attribute, and the resolver correctly degrades to "no candidates" — so a populated-but-unconfigured index looks empty | `servana:search-reindex` now runs `scout:sync-index-settings` **first**, making that state unreachable through the command. Both the failure mode and the fix are covered (`SearchEngineIntegrationTest`, `SearchCommandTest`) |
| F-9 | `servana:search-verify-counts` reported drift that did not exist, immediately after a backfill | Meilisearch indexing is asynchronous; the count was taken while tasks were still queued | the check now waits for the index to settle (bounded, 15s) before counting, so a reported difference is real drift rather than a race |
| F-10 | Three navigation/layout Vitest specs broke | Their hand-written test routers did not know the new `search` route, so `RouterLink` could not resolve the nav item | registered `search` in each test router (3 × 3-line diff) |

F-5 through F-9 are genuine product defects found by the tests before any review; F-10 was a fixture gap.

Two further findings arrived from the **full-suite** run rather than the targeted one:

| # | Finding | Root cause | Fix |
|---|---|---|---|
| F-11 | Six engine tests failed under the full suite with a Meilisearch `TimeOutException`, having passed in isolation | `meilisearch-php` defaults `waitForTask()` to **5000 ms**; under the full run these tests create and delete their own per-run indexes while 2200 other tests execute, so the task queue backs up and 5 s expires on a healthy engine | one shared `P22_TASK_TIMEOUT_MS = 60_000` used by every `waitForTask` call, and index teardown wrapped so a slow engine during cleanup can never fail an otherwise-passing test. A **test-timing** defect, not a product defect |
| F-12 | `servana:search-reindex` reported success while documents were still queued | Meilisearch applies documents asynchronously and the command did not wait | new `App\Domain\Search\Support\SearchEngineTasks::settle()` (bounded, 60 s), used by **both** commands: the backfill now waits and warns if an index is still applying, and the drift check counts only a settled index. Also removes the operational foot-gun where an operator runs a backfill, searches immediately, gets nothing, and concludes the backfill failed |

A third gap was found by reading the compose files rather than by a test:

| # | Finding | Root cause | Fix |
|---|---|---|---|
| F-13 | The `search-index` queue had **no consumer**, so observer-driven index updates would never apply | The dev worker consumed `mail,default` only (the same is true of the `mail`-adjacent and `re-outbox` queues earlier phases introduced — full per-queue topology is Phase 21N's scope) | `search-index` added **last** to the dev worker's queue list — the minimum wiring Search itself requires. `docker-compose.prod.yml` is deliberately **not** touched (Phase 25 owns deployment); recorded as a residual risk with `servana:search-reindex`/`search-verify-counts` as the interim path |

---

## 7. Security and privacy proof

| Threat (search-security.md §1) | Proven by |
|---|---|
| T1 cross-tenant leakage | `SearchTenantIsolationTest` — a matching row with an identical name exists in another merchant and is never returned, for clients and appointments |
| T2 cross-branch leakage | same suite — the same client name exists in branch B; a branch-A actor never sees it. Merchant-wide (Merchant Admin) reaches **both** own branches but no foreign merchant; branch-scoped HR reaches only its own. Removing the branch assignment mid-flight stops results immediately |
| T3 own-scope leakage | `SearchServedClientOwnScopeTest` — two Personnel members in one branch each with an identically-named served client see only their own; a client with only a *pending* session is not "served"; a membership with no staff profile gets nothing |
| T4 permission-filter bypass | `SearchPermissionFilteringTest` — one matching row of every type exists in the actor's own branch, and each role sees exactly the types its own routes allow. A Branch Manager gets appointments and queue entries but **no clients and no invoices**; Finance gets invoices but not the client directory; Audit gets neither. Explicitly requesting an unauthorized type returns empty, not 403. `client` requires **both** `client.view` and `front_office.search` |
| T5 frontend-held key | `SearchScopePurityTest` (no Meilisearch host/key/index token anywhere in the SPA tree, none in `openapi.json` or `api.ts`, no `VITE_MEILI*`), plus the Playwright test that records every request host and asserts none is the engine |
| T6 unscoped cache filtered client-side | `searchStore.spec.ts` + `phase-22-search.spec.ts` — nothing is written to `localStorage`/`sessionStorage`, held results clear on a membership or branch-scope change, and a stale in-flight response can never overwrite a newer one |
| T7 contact leakage | `SearchApiTest` asserts the response body contains no `phone`, `email`, full number, national form, last four, `phone_index`, `phone_encrypted` or `email_encrypted`; `SearchIndexDocumentTest` asserts the exact key set of all seven document types; `SearchEngineIntegrationTest` asserts the same against the **real** index |
| T8 search-by-contact becoming export | `SearchPhoneLookupTest` (complete numbers in five written forms resolve; five partial fragments do not; the result carries the name only; `meta.query` is redacted) and `SearchServedClientOwnScopeTest` (no phone path at all, not even masked) |
| T9 integration payloads indexed | `SearchIndexDocumentTest` — the indexed model classes resolve to exactly `clients`, `staff_profiles`, `appointments`, `queue_entries`, `service_sessions`, `invoices`, `receipts`, and to none of 20 named forbidden tables |
| T10 forged filters | `SearchInjectionSafetyTest` — all 21 forgery fields rejected with 422 by name, plus a positive check that the honest query still returns only own-tenant rows |
| T11 filter/sort injection | same suite — four Meilisearch filter-escape payloads and five SQL payloads are ordinary text; four LIKE-wildcard payloads cannot widen their pattern while a legitimate `100%` term still matches; control characters are stripped; unknown `sort`/`types` are 422. Confirmed against the **real engine** too |
| T12 enumeration | `SearchRateLimitTest` — the route carries `throttle:search` in addition to the group limiter, 429 arrives with the structured envelope, and the limit is per principal. `SearchApiTest` proves a zero-result query and an unauthorized query are byte-identical |

### 7.1 The strongest single proof

`SearchEngineIntegrationTest` → *"never resolves a POISONED engine candidate that PostgreSQL says is
out of scope"*: a foreign merchant's client is written into the index **with this tenant's
`merchant_id` and `branch_id`**. The test first asserts the engine really does hand that candidate
over under the server's own filter, then asserts the endpoint returns `[]`. That is the difference
between "we filter the engine" and "the engine cannot be trusted, and it does not need to be".

---

## 8. Quality gates

Every command below was run from this branch's final tree. Commands are recorded verbatim so the run
is reproducible.

| Gate | Command | Result |
|---|---|---|
| Composer manifest | `composer validate --strict --no-interaction` | **`./composer.json is valid`** |
| PHP style | `docker compose exec -T app vendor/bin/pint --test` | **PASS — 1655 files** |
| Static analysis | `docker compose exec -T app composer stan` | **`[OK] No errors`** — Larastan level 8, 1287 files |
| Phase 22 suites | `php artisan test tests/Feature/Search tests/Unit/Search tests/Feature/Auth/Phase22SearchGateTest.php` | **223 passed / 908 assertions** |
| Backend — serial | `docker compose exec -T -e MEILISEARCH_HOST=… app php artisan test` | **2229 passed / 7 skipped / 0 failed / 13336 assertions** (2006/7/0 at Phase 21S → +223), exit 0, 1645s |
| Backend — parallel | `docker compose exec -T -e MEILISEARCH_HOST=… app php artisan test --parallel` | **identical: 2229 passed / 7 skipped / 0 failed / 13336 assertions**, exit 0, 945s |
| Generator determinism | `servana:openapi` → `npm run api:types` → `servana:permission-types` → `--check` → `api:contract:check`, run **twice** | `openapi.json`, `api.ts` and `permissions.ts` **byte-identical across the baseline and both passes** (SHA-256 `2CE8955A…`, `C204FC79…`, `D849F356…`). `permissions.ts` shows **no diff at all** versus the committed tree — the mechanical proof of D-22-01 |
| Real engine | included above — `SearchEngineIntegrationTest` (14) + `SearchCommandTest` (13) against the live `getmeili/meilisearch:v1.10` dev service | **27 passed**, including the poisoned-index and settings-not-synced cases |
| OpenAPI ⇄ TS parity | `npm run api:contract:check` | **OK — 243 paths, 289 operations** (242/288 at Phase 21S → +1/+1) |
| Frontend lint | `npm run lint` | **0 errors**, 138 warnings — the exact pre-existing baseline; **no new warning from any Phase 22 file** |
| Type check | `npm run typecheck` (`vue-tsc --noEmit`) | clean |
| Frontend unit | `npm run test` (Vitest) | **519 passed / 98 files** (501 at Phase 21S → +18) |
| SPA build | `npm run build` | built |
| E2E — Phase 22 | `npx playwright test tests/e2e/phase-22-search.spec.ts` | **26 passed**, incl. axe serious/critical **0** at 360/768/1280 × light/dark, 200% zoom, keyboard, live region |
| E2E — full | `npx playwright test` | **479 passed** (453 at Phase 21S → +26) — required because Phase 22 touches shared navigation and the router |
| Composer audit | `docker compose exec -T app composer audit --locked --no-interaction` | **No security vulnerability advisories found** |
| Secrets | `gitleaks detect --source . --no-git --redact` | **no leaks found** (~23 MB scanned) |
| Docker | `docker compose build app` · `-f docker-compose.prod.yml build app` · `… build nginx` | all three images built (`servana-app:dev`, `servana-app:prod`, `servana-nginx:prod`) |
| npm audit | `npm audit --audit-level=high` | **⛔ FAILS — 15 high**. Pre-existing upstream drift, not a Phase 22 regression: see `REM-DEP-002` and §9 item 1 |

### 8.1 The 7 skipped tests, with reasons

Unchanged from the Phase 21S baseline of 7 — Phase 22 neither adds nor resolves a skip, and none is a
Phase 22 test.

| Count | Tests | Reason |
|---|---|---|
| 3 | `ClamAvEicarIntegrationTest` — EICAR detection, clean verdict, engine/signature versions | `clamd` unreachable in the local stack (the ClamAV service is an opt-in profile: `docker compose --profile clamav up -d clamav`). CI runs these against a real `clamav/clamav:stable` service with `--fail-on-skipped`, so they are **not** skipped there |
| 4 | Four documented threat-model scenario placeholders (`GET /invoices/{ulid-of-other-merchant}`, Finance cross-branch payment listing, unscoped export refusal, Personnel cross-queue request) | Pre-existing markers whose behaviour is already covered by live tests elsewhere; they carry Pest skip notes rather than assertions |

### 8.2 Migrations

**None.** `git status` shows no change under `database/migrations`, so there is no disposable
PostgreSQL schema proof in this phase — Phase 22 is additive behaviour over existing substrate.

---

## 9. Residual risks

Only real, unresolved items are listed. Each names its owner.

1. **`REM-DEP-002` — the npm high-severity advisory gate is BLOCKED, and it blocks the Phase 22 PR.**
   `npm audit --audit-level=high` reports **15 high findings** where Phase 21S recorded 0, against a
   `package.json`/`package-lock.json` this phase **did not modify** (confirmed by `git status`). Two
   upstream advisories published since the 21S closure run:
   `brace-expansion` [GHSA-mh99-v99m-4gvg](https://github.com/advisories/GHSA-mh99-v99m-4gvg)
   (DoS via unbounded expansion), reached through `minimatch` by `eslint`, `eslint-plugin-vue`,
   `@eslint/config-array`, `@eslint/eslintrc`, `glob`, `editorconfig`, `js-beautify`,
   `@vue/test-utils`, `@vue/language-core`, `vue-tsc`, `@redocly/openapi-core` and
   `openapi-typescript` — 12 of the 15; and `postcss`
   [GHSA-r28c-9q8g-f849](https://github.com/advisories/GHSA-r28c-9q8g-f849) (path traversal in
   previous-source-map auto-loading) — the remaining direct one.
   npm reports the brace-expansion fix as **`eslint@10.8.0`, `isSemVerMajor: true`**, and a
   `npm audit fix --dry-run` confirms all 15 remain after a non-breaking pass. Every affected package
   is a **devDependency**, so production exposure is nil; the exposure is to the CI and developer
   toolchain. **But CI runs this gate in the Frontend job**, so the Phase 22 PR's Frontend check will
   fail until it is remediated. Not fixed here because `package-lock.json` is not a Phase 22 Search
   path and a breaking lint-toolchain major must not ride along in a security-feature PR — the
   repository already established the separate-branch pattern with PR #42.
   **Owner:** a product-owner-authorized dependency-remediation branch, before Phase 25 exit.
2. **Production queue wiring for `search-index` (F-13).** The dev worker now drains it; the
   production worker does not, because `docker-compose.prod.yml` is Phase 25's file and full per-queue
   topology (Horizon, priorities, alerting) is Phase 21N's, which is blocked behind Gate W — the same
   situation the `re-outbox` queue is already in. Consequence if unaddressed: observer-driven index
   updates would not apply in production, so search would return stale-or-missing results (never wrong
   or leaked ones, because every candidate is re-resolved against PostgreSQL).
   `servana:search-verify-counts` detects it and `servana:search-reindex` repairs it.
   **Owner:** Phase 21N (queue topology) / Phase 25 (production compose).
3. **Index-lag visibility has no scheduled check (D-22-05).** `servana:search-verify-counts` exists
   and exits non-zero on drift, but nothing invokes it on a schedule, because the scheduler is Phase
   21N's scope. **Owner:** Phase 21N.
4. **`service_session` results land on a list, not a detail page.** The SPA has no service-session
   detail screen, and Phase 22 does not create business-workflow screens. A result therefore targets
   `front-office.sessions` and the operator finds the row there. **Owner:** whichever phase adds a
   session detail screen, if one is ever wanted.
5. **A phone-like term still travels in the query string** of `GET /api/v1/search?q=…`, so it can
   reach a server access log or browser history. This is **exactly** the posture of the already
   shipped and `verified_complete` `GET /api/v1/clients?q=<phone>` (Phase 15A), so Phase 22 introduces
   no new class of exposure; and the response body no longer echoes it (F-6). Eliminating it entirely
   would mean making search a POST, which contradicts it being a read and its route classification.
   **Owner:** product-owner decision if the URL exposure is ever deemed unacceptable for both surfaces
   together.
6. **Two pre-existing conditions recorded, not fixed** (F-4): `staff.view` is still `planned` with
   `owning_phase: Phase 20F` although 20F is `verified_complete`, and `GET /api/v1/staff` performs no
   `authorize()` call. Phase 22 anchored `staff` search on the stricter *detail-route* authority so it
   neither relies on nor widens either. **Owner:** a future phase, deliberately.

`REM-SMS-002` (live SMS provider/callback verification) and `REM-RE-002` (live R&E sandbox
credentials) remain open from earlier phases and are untouched by Phase 22.

---

## 10. Scope boundary — confirmed absent

Asserted by test, not merely omitted: no Wallet runtime, no Daraja/STK/PayBill/Till/C2B surface, no
R&E reward/referrer/campaign/qualification surface (`SearchScopePurityTest` scans the whole search
domain with comments stripped, so only runtime code counts); no notification centre; no scheduled
report or report engine; no search-result export, download, print or clipboard path anywhere — route,
response or UI control (`SearchApiTest`, `SearchScopePurityTest`, `phase-22-search.spec.ts`); no
contact export in any form; no scheduler entry (D-22-05); no Phase 23 audit work, no Phase 24
performance work beyond Phase 22's own checks, no Phase 25 deployment work.

Phase 22 adds **no migration and no table** — it is additive behaviour over existing substrate, which
is why there is no disposable-PostgreSQL schema proof in this phase.
