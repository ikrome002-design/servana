# Phase 21R-A Proof — Citrus Refer & Earn Referral Capture, Outbox, and Signed Delivery

> Servana is a **source product** for Citrus Refer & Earn (ADR-013). This phase builds Servana's own
> side only: referral capture at registration, the local immutable snapshot, the transactional
> outbound event outbox, signed delivery, redacted delivery evidence, and `merchant.*` lifecycle
> emission. It builds **no** R&E-owned capability (referrer accounts, referral codes as system of
> record, campaigns, reward rules, reward calculation, reward ledger, referrer payouts, reward
> statements) and **no** Phase 21R-B capability.

---

## Lifecycle

| Field | Value |
|---|---|
| Phase | 21R-A — Citrus Refer & Earn Integration Foundation (Plan §80, ADR-013) |
| Lifecycle | **`local_complete pending PR CI/review/merge`** — one completion commit, branch pushed, **no PR created** (separate authorization required) |
| Branch | `phase-21r-a-referral-capture-outbox` |
| Base commit | `6047835b3a388fff5cc92a13370963635700f5e3` (Phase 20H PR #43 squash merge) |
| Predecessor | Phase 20H — `verified_complete` (PR #43) |
| Next phase after this one | **not** 21R-B (blocked on 20D-W / Gate W); see "Deferred work" |

---

## Branch and repository state

Preflight (read-only, executed from PowerShell before any file change):

```text
branch                        = main
HEAD                          = 6047835b3a388fff5cc92a13370963635700f5e3
origin/main                   = 6047835b3a388fff5cc92a13370963635700f5e3
merge-base(origin/main, HEAD) = 6047835b3a388fff5cc92a13370963635700f5e3
origin/main...HEAD            = 0	0
working tree (porcelain -uall)= 0 entries
staged files                  = 0
git fsck --full               = clean (two pre-existing dangling objects only:
                                blob cb0a299d…, commit fd95305e…; no corruption)
git diff --check              = clean
local  phase-20h-payout-runs-earnings = absent
remote phase-20h-payout-runs-earnings = absent
local/remote phase-21r-a-*            = absent (no branch overwritten)
GIT PREFLIGHT OK
```

Branch creation:

```text
git checkout -b phase-21r-a-referral-capture-outbox
branch                        = phase-21r-a-referral-capture-outbox
HEAD                          = 6047835b3a388fff5cc92a13370963635700f5e3
merge-base(origin/main, HEAD) = 6047835b3a388fff5cc92a13370963635700f5e3
working tree                  = clean
PHASE 21R-A BRANCH READY
```

---

## Source-of-truth files read

Read in the Plan-mandated order before any edit.

| Source | Present | Used for |
|---|---|---|
| `CLAUDE.md` | yes | IDE operating guide, guardrails §6, commands §7 |
| `Servana Software Development Plan.md` | yes | §0/§1.2/§2.2, §8.1 (ADR-013/015), §9 rules 20–24, §9.1, §10.1, §12.1, §13.1–13.2, §13.17, §17.1, §24.1/§24.2/§24.5, §25.6, §58A, §58B.1/§58B.2/§58B.5, §67, §70, §74, §75/§75.1, §77.1, §80.1/§80.2, §81, §82, §85 |
| `Servana Project Scope.md` | yes | registration behaviour context only |
| `AGENTS.md` | yes | repo agent guide |
| `docs/PROGRESS.md`, `docs/CHANGELOG.md` | yes | historical claims (verified against repo/GitHub, never trusted as proof) |
| `docs/proof/phase-20h.md` | yes | predecessor evidence |
| `docs/governance/solo-maintainer-review-exception-pr-43.md` | yes | PR #43 governance truth |
| `docs/remediation/register.yaml` | yes | REM-RE-001 |
| `docs/traceability/servana-requirements.csv` | yes | 51 rows; no 21R row yet |
| `docs/architecture/adr/0013-…refer-and-earn-integration-authority.md` | yes | ownership boundary |
| `docs/architecture/adr/0015-cross-platform-machine-identity-and-webhook-signing.md` | yes | algorithm-aware signing |
| `docs/architecture/data-dictionary/refer-earn-integration.md` | yes | canonical DDL reference |
| `docs/architecture/migrations/manifest.yaml` | yes | migration manifest lint |
| `docs/auth/permission-matrix.yaml` | yes | R&E permission keys and owning phases |
| `resources/spa/src/types/generated/permissions.ts`, `api.ts` | yes | generated contract parity |
| `SERVANA COMBINED.txt` | **absent** | cited by Plan §2; recorded absent, not fabricated |
| `Wallet_by_Citrus_Platform_Project_Scope.md` | **absent** | Wallet authority; Gate W input |
| `Refer_and_Earn_Project_Scope.md`, `Citrus_Refer_and_Earn_Production_Software_Development_Plan.md` | **absent** | R&E authority; contract pins deferred to 21R-A entry per the data dictionary |
| `SERVANA_DEVELOPMENT_PLAN_CORRECTIONS.md` | **absent** | folded into the v4 plan |
| `docs/integrations/**` | **absent before this phase** | Gate W evidence + R&E contract-pin home |

---

## Phase 20H reconciliation evidence

Verified live against GitHub (not from `docs/`):

```text
gh pr view 43 --repo ikrome002-design/servana
number       = 43
title        = Phase 20H: Implement payout runs and earnings
state        = MERGED
baseRefName  = main
headRefOid   = 9824e463273ffb6d8b089c6cef683b165cdc8c25
mergeCommit  = 6047835b3a388fff5cc92a13370963635700f5e3
mergedAt     = 2026-07-22T04:27:01Z
reviewDecision = ''            (blank)
url          = https://github.com/ikrome002-design/servana/pull/43

gh run view 29890786464 --repo ikrome002-design/servana
databaseId = 29890786464   status = completed   conclusion = success
event      = pull_request  headSha = 9824e463273ffb6d8b089c6cef683b165cdc8c25
jobs:
  Frontend — ESLint, vue-tsc, Vitest, build  completed/success
  E2E — Playwright                           completed/success
  Security — gitleaks                        completed/success
  Docker — build images                      completed/success
  Backend — Pint, Larastan, Pest             completed/success
```

Recorded truth:

| Field | Value |
|---|---|
| Lifecycle | `verified_complete` |
| Implementation head | `309057c2f29e492bbc2602714d9c7e52ea1014b4` |
| Test-fix head | `16c368a96dbd3d53a5bb7fda8a3b39e55ac46b92` |
| Governance / final PR head | `9824e463273ffb6d8b089c6cef683b165cdc8c25` |
| Squash merge commit | `6047835b3a388fff5cc92a13370963635700f5e3` |
| Final successful CI run | `29890786464` (all five required jobs SUCCESS) |
| `reviewDecision` | **blank**, under the documented PR-specific solo-maintainer governance exception — **not** independent reviewer approval |
| Branch cleanup | local **and** remote `phase-20h-payout-runs-earnings` deleted (verified by `git branch --list` and `git ls-remote --heads`) |

**Narrow PR #43 CI repair (recorded truthfully).** The initial PR #43 Backend run failed on two stale
hand-maintained permission expectations. The repair changed **only**:

- `tests/Feature/Auth/PermissionMatrixTest.php` — `expectedMatrix()` was missing the 16 grants Phase
  20H activated;
- `tests/Feature/Auth/PermissionDatabaseProjectionTest.php:38` — used `payout_run.mark_paid` as its
  "still planned" fixture after 20H made that key **active**; replaced with the still-planned
  `personnel.my_sms.send` (Phase 21S).

Implementation permission truth (registry, policies, matrix YAML, generated artifacts) was **not**
changed. No test was weakened, skipped or deleted. Commit:
`16c368a96dbd3d53a5bb7fda8a3b39e55ac46b92` — *test: update permission expectations for phase 20h*.

Reconciled in: `docs/PROGRESS.md` (roadmap row 20H + Phase 20H section), `docs/CHANGELOG.md`
(Phase 20H heading + closure block).

---

## Gate W decision evidence

**Decision: Gate W is CLOSED. Proceed with Phase 21R-A. No pivot to Phase 20D-W.**

Plan §80.2.4 makes the gate evidence file the authoritative artifact:
*"the agent records gate evidence (credential receipt, OpenAPI version hash, a passing contract-test
run against sandbox, simulated end-to-end STK + C2B flows) in `docs/integrations/wallet/gate-w-evidence.md`."*

Inspection performed **before** branch creation (read-only):

| Path | Result |
|---|---|
| `docs/proof/phase-20d-w.md` | **absent** |
| `docs/integrations/` | **absent** (whole tree) |
| `docs/integrations/wallet/` | **absent** |
| `docs/integrations/wallet/gate-w-evidence.md` | **absent** |
| `config/integrations.php` | **absent** |
| `config/services.php`, `.env.example` | present; no Wallet sandbox credentials, key IDs, endpoints or contract pins |
| `docs/architecture/adr/0012-…wallet…boundary.md` | present — states Gate W is a *prerequisite* for 20D-W, and pins `resource_version`/`state_sequence` naming **at** Gate W (i.e. still unpinned) |
| `docs/architecture/data-dictionary/billing-and-wallet.md` | present — 20D-W tables are specification-only, "requires Gate W" |
| `docs/remediation/register.yaml` | REM-WALLET-001 correction still reads "Build in 20D-W … **after Gate W**" |
| `docs/PROGRESS.md` roadmap row 20D-W | "⛔ Blocked — External Gate W CLOSED" |
| `docs/proof/phase-20h.md` §H2 | independently recorded Gate W **CLOSED** |

Repository-wide search for readiness terms (`Gate W`, `External Gate W`, `Wallet Servana Collections
Slice`, `wallet.*sandbox`, `signing contract`, `resource_version`, `state_sequence`) returned only
**planning** references in the Plan, ADRs, data dictionary, prior proofs and PROGRESS/CHANGELOG — no
credential receipt, no pinned OpenAPI hash, no contract-test run, no sandbox STK/C2B transcript, no
signed-webhook transcript, no reconciliation transcript, no explicit PASS. Per the assignment's rule,
Gate W opening requires **authoritative** Wallet readiness evidence; planned docs, TODOs and config
placeholders do not qualify.

Consequence, per Plan §80.1 (`20B → 21R-A`, parallel-eligible with 20C–20E) and A-15 (*"If Gate W is
not open … the agent proceeds to the next non-Wallet phase and returns to 20D-W when the gate
opens"*): **Phase 21R-A is the next executable phase.**

### Phase 21R-A entry criteria (Plan §80)

| Criterion | Status |
|---|---|
| 20B complete (merchant lifecycle facts stable) | ✅ PR #36 merged, `verified_complete` |
| §1.3 plan-adoption PR merged | ✅ PR #34 merged, `verified_complete` |
| R&E sandbox service-account credentials received | ❌ **not received** — `docs/integrations/refer-earn/` did not exist; no credential receipt anywhere in the repository |

The Plan supplies its own fallback for the third criterion verbatim: *"if the R&E sandbox is
unavailable, implement against `FakeReferEarnClient` + recorded contract fixtures and mark a deferred
verification item that must close before Phase 25."* That fallback is what this phase executes; the
deferred-verification item is recorded in this proof and in `docs/remediation/register.yaml`.

---

## Scope statement

Servana-owned, built in this phase:

- merchant registration referral capture (query param, manual entry, central redirect);
- the local immutable `referral_snapshots` evidence row (at most one per merchant);
- the referral snapshot state machine (§25.6) and its non-regression guarantee;
- non-blocking asynchronous validation / attribution-confirmation seams;
- the transactional outbound event outbox `re_outbound_events` (append-only, per-merchant ordering);
- canonical JSON payloads + deterministic `content_sha256`;
- algorithm-aware signed delivery to the R&E product-events endpoint;
- redacted, bounded delivery history `re_event_deliveries`;
- the five Phase 21R-A `merchant.*` event types (§58B.1);
- schema, contract, traceability, proof and progress documentation.

Citrus Refer & Earn-owned, **not** built here: referrer accounts; referral codes as system of record;
campaigns; reward rules; reward calculation; reward ledger; referrer payouts; reward statements;
attribution uniqueness as the effective earning authority.

---

## Explicit exclusions

Not implemented in Phase 21R-A (asserted by tests where practical — see "Scope-purity audit"):

Wallet: 20D-W runtime, wallet merchant-account links, subscription payment attempts/payments/receipts/
reversals, wallet webhook inbox, billing reconciliation exceptions, subscription invoice payment locks,
merchant billing credits, STK submission, PayBill/Till instruction runtime, C2B validation/confirmation,
Daraja callbacks, Safaricom provider credentials, raw provider receipt uniqueness, provider reconciliation.

Phase 21R-B: `subscription.*` and `activity.*` events, `re_activity_rule_versions`,
`re_qualification_periods`, `re_qualification_decisions`, `re_inbound_requests`, the monthly
qualification engine, the inbound R&E reconciliation surface, `ReconcileReEventGapsJob`.

Other phases: personnel SMS (21S), notifications/scheduled reports (21N), search (22), release-wide
security/responsive/dark/accessibility audit (23), performance optimization (24), production
readiness/deployment (25).

---

## Pre-implementation inventory

### As-built registration path (inspected before wiring — Plan §81 rule 22)

| Artifact | Path | Note |
|---|---|---|
| Route | `routes/api.php:179` | `POST merchant-registration/self-register`, public, named `merchant-registration.self-register` |
| Controller | `app/Http/Controllers/Api/V1/Onboarding/MerchantRegistrationController.php` | thin; uniform 202 response (no enumeration) |
| Form Request | `app/Http/Requests/Onboarding/RegisterMerchantRequest.php` | `owner_name`, `email`, `business_name` only |
| Action | `app/Domain/Onboarding/Actions/RegisterMerchant.php` | single `DB::transaction`; creates user → merchant (`pending_setup`) → profile → owner `merchant_admin` membership → status history → `membership.created` audit; returns `null` for an existing email (no merchant created) |
| Audit coverage | `app/Domain/Audit/Support/AuditMutationCoverage.php:228` | `merchant-registration.self-register` ⇒ `['membership.created']` |

Proven insertion point: the referral snapshot and its two registration events must be created
**inside** the existing `DB::transaction` closure in `RegisterMerchant::handle()`, after the
membership row exists (so `merchant.admin_created` has its fact) and before the closure returns.
The `null` early-return path (existing email) creates no merchant, therefore no snapshot and no event.

### Other as-built seams

| Event | Seam | Path |
|---|---|---|
| `merchant.setup_completed` | `CompleteFirstTimeSetup::handle()` step 7 (`status → active`, `setup_completed_at`) inside its own `DB::transaction` | `app/Domain/Onboarding/Actions/CompleteFirstTimeSetup.php` |
| `merchant.status_changed` | `SuspendMerchant`, `ReactivateMerchant`, `DeactivateMerchant` — each locks the row, validates the transition and audits inside `DB::transaction` | `app/Domain/Merchants/Actions/*.php` |
| `merchant.identity_snapshot_changed` | merchant legal/business identity fields (`merchants.name`, `merchant_profiles` identity columns) | `app/Domain/Merchants/Models/{Merchant,MerchantProfile}.php` |

### Convention inventory (patterns this phase must follow, not re-invent)

| Concern | Existing convention |
|---|---|
| Bounded context | `app/Domain/{Context}/{Actions,Enums,Models,Jobs,Support,Contracts}` — Plan §10.1 pins `app/Domain/Integrations/ReferEarn` |
| Encryption at rest | Eloquent `'encrypted'` cast (`Client::phone_encrypted`, `PersonnelPayoutRun::external_payment_reference_encrypted`, …) |
| Migrations | one table per file, `id()` + `char('ulid',26)->unique()`, `timestampsTz`, `DB::statement` literal `CHECK`/trigger SQL (no interpolation), `dropIfExists` down |
| Tenancy | `BelongsToMerchant` (explicit `merchant_id` on create wins); `TenantOwnership::{TENANT_OWNED,BRANCH_OWNED,EXEMPT}` classification is asserted by `TenantColumnCoverageTest` |
| `withoutTenancy()` | restricted by `NoWithoutTenancyOutsidePlatformRule` to `App\Domain\Tenancy` and `App\Domain\Platform` |
| Jobs | `TenantAwareJob` for tenant work; plain `ShouldQueue` + `$tries`/`$backoff` + `onQueue(config(...))` for infrastructure work (see `ScanUploadedFile`) |
| Config | one file per domain (`config/files.php` shape) with `env()` defaults; secrets never defaulted to real values |
| Guards | `MigrationManifestTest`, `TenantColumnCoverageTest`, `RouteSecurityContractTest`, `AuditMutationCoverage`, `NoDirectProviderIntegrationTest`, OpenAPI/TS parity, permission parity |

### Pinned contract facts (from the Plan, not invented)

- Canonical signing string (§9 rule 22):
  `METHOD\nPATH\nTIMESTAMP\nNONCE\nCONTENT_SHA256\nEVENT_ID\nEVENT_TYPE\nEVENT_VERSION`.
- Headers (§58A.2): `X-Citrus-Key-Id`, `X-Citrus-Event-Id`, `X-Citrus-Event-Type`,
  `X-Citrus-Event-Version`, `X-Citrus-Timestamp`, `X-Citrus-Nonce`, `X-Citrus-Content-SHA256`,
  `X-Citrus-Signature`, plus `Idempotency-Key = event_id`.
- Delivery endpoint shape (§58A.2):
  `POST {RE}/api/v1/integrations/products/{productCode}/events`.
- Response handling (§58A.2): `202` → delivered; `409 EVENT_ID_PAYLOAD_MISMATCH` → dead-letter, never
  mutate-and-resend; `401/403` → pause + alert; `422` schema → dead-letter + contract-drift alert;
  `429/5xx/timeout` → exponential backoff with jitter (base 30 s, cap 1 h, max age 7 days → dead-letter).
- Referral code shape (§58A.1 / §13.17): `SERVANA-XXXXX`, normalized uppercase/trimmed
  (e.g. `SERVANA-X8T2K`); `code_normalized` is `null` when `invalid_format`.
- Redaction (§24.5): never log R&E signing keys, `X-Citrus-Signature` values, nonces paired with
  signatures, raw referral landing metadata, or the decrypted
  `referral_snapshots.raw_code_encrypted`.
- ADR-015: **do not** hardcode an algorithm — select by algorithm identifier + key ID + contract
  version, and fail closed when unknown/missing.

### Documentation drift found during inventory (root-cause block)

```text
Observed problem:
  docs/architecture/data-dictionary/refer-earn-integration.md labels `re_inbound_requests` as a
  Phase 21R-A table.
Evidence:
  docs/architecture/data-dictionary/refer-earn-integration.md:67  "### `re_inbound_requests` (21R-A)"
  Servana Software Development Plan.md:701   "re_inbound_requests(21R-B)"
  Servana Software Development Plan.md:1268  "re_inbound_requests (21R-B; replay protection …)"
  Servana Software Development Plan.md:2677  Phase 21R-B backend: "the inbound reconciliation
                                             controller + query classes + replay store"
Affected files / functions / routes / tables:
  docs/architecture/data-dictionary/refer-earn-integration.md (documentation only; no code exists)
Root cause:
  A transcription error in the v4 plan-adoption PR's data-dictionary stub; the owning phase was
  copied from the neighbouring 21R-A tables instead of from Plan §13.17.
Why this is the root cause:
  The Plan is source-of-truth rank 1 and states 21R-B in three independent places, including the
  canonical §13.17 DDL block and the §80 Phase 21R-B backend list. The data dictionary is rank 3
  documentation with no corresponding schema, migration or code. Nothing else references the
  21R-A label.
Correct fix:
  Correct the label to 21R-B in the data dictionary while aligning it with §13.17 during this
  phase's data-dictionary update. No code change; no migration.
Files changed:
  docs/architecture/data-dictionary/refer-earn-integration.md
Tests added or updated:
  none required (documentation-only); the absence of `re_inbound_requests` in 21R-A is asserted by
  the forbidden-table scan in the schema proof.
Test command / Test result / Proof of resolution:
  see "Database and migration proof" — the disposable-database forbidden-table scan lists
  re_inbound_requests among the tables that must NOT exist after 21R-A migrations.
Remaining risk:
  none — the corrected label matches the Plan and the built schema.
```

### File-level implementation checklist

**Database (3 additive migrations; nothing shipped was edited — ADR-004 forward-only):**
`2026_07_22_000001_create_referral_snapshots_table.php` ·
`2026_07_22_000002_create_re_outbound_events_table.php` ·
`2026_07_22_000003_create_re_event_deliveries_table.php`

**Bounded context `app/Domain/Integrations/ReferEarn/` (Plan §10.1) — 34 files:**

| Group | Files |
|---|---|
| Actions | `CaptureReferralSnapshot`, `TransitionReferralSnapshot`, `ValidateReferralCode`, `ConfirmAttribution`, `EnqueueProductEvent`, `DeliverProductEvent` |
| Clients | `ReferEarnClientInterface`, `HttpReferEarnClient`, `FakeReferEarnClient`, `Dto/EventDeliveryResult`, `Dto/ReferralCodeValidation`, `Dto/AttributionConfirmation` |
| Data | `ReferralCaptureData` |
| Enums | `ReferralSnapshotStatus`, `ReferralCaptureChannel`, `ReOutboundEventType`, `ReDeliveryStatus`, `ReDeliveryResponseClass`, `MerchantStatusReasonCategory` |
| Exceptions | `ReferralSnapshotStateException`, `ReferEarnSigningException` |
| Jobs | `ValidateReferralCodeJob`, `ConfirmAttributionJob`, `DeliverReOutboxJob` |
| Models | `ReferralSnapshot`, `ReOutboundEvent`, `ReEventDelivery` |
| Observers | `MerchantIdentityObserver` |
| Support | `CanonicalJson`, `CitrusEventSigner`, `DeliveryResponseRedactor`, `LandingMetadataAllowlist`, `MerchantEventPayloadBuilder`, `ReferralCodeNormalizer` |

**Factories:** `ReferralSnapshotFactory`, `ReOutboundEventFactory`, `ReEventDeliveryFactory`.

**Existing files extended (additive only, as-built inspected first — Plan §81 rule 22):**

| File | Change |
|---|---|
| `app/Domain/Onboarding/Actions/RegisterMerchant.php` | optional `?ReferralCaptureData` argument; capture + the two registration events **inside** the existing transaction; `ValidateReferralCodeJob` dispatched **after** commit |
| `app/Domain/Onboarding/Actions/CompleteFirstTimeSetup.php` | `merchant.setup_completed` enqueued inside the existing transaction, after the status flip |
| `app/Domain/Merchants/Actions/{Suspend,Reactivate,Deactivate}Merchant.php` | optional `MerchantStatusReasonCategory` (default `manual`); `merchant.status_changed` enqueued inside each existing transaction |
| `app/Http/Requests/Onboarding/RegisterMerchantRequest.php` | three optional referral rules + `referralCapture()` |
| `app/Http/Controllers/Api/V1/Onboarding/MerchantRegistrationController.php` | passes the referral intent through |
| `app/Domain/Audit/Enums/AuditEvent.php` | 4 cases: `re.referral_captured`/`re.attribution_confirmed`/`re.attribution_rejected` (info), `re.event_dead_lettered` (high) |
| `app/Domain/Tenancy/TenantOwnership.php` | 3 EXEMPT classifications with rationales |
| `app/Providers/AppServiceProvider.php` | fail-closed `ReferEarnClientInterface` binding + observer registration |
| `config/refer-earn.php`, `.env.example` | configuration; **no secret defaults** |
| `resources/spa/src/pages/auth/RegisterMerchant.vue` | optional referral field, `?ref=` pre-fill, dismissible notice, advisory format hint |

**Documentation:** `docs/architecture/data-dictionary/refer-earn-integration.md` (rewritten + drift fix),
`docs/architecture/migrations/manifest.yaml` (+3), `docs/architecture/state-machines/referral-snapshot.md`,
`docs/architecture/state-machines/re-outbound-event.md`,
`docs/integrations/refer-earn/{credentials-receipt,contract-pins}.md`,
`docs/integrations/refer-earn/schemas/*.json` (5 event schemas + 1 envelope reference),
`docs/remediation/register.yaml` (REM-RE-001 → `in_progress`; **REM-RE-002** raised),
`docs/traceability/servana-requirements.csv`, `docs/PROGRESS.md`, `docs/CHANGELOG.md`, this file.

**Tests (11 new backend files, 1 unit file, 1 e2e spec, 1 test-support class):**
`Phase21RASchemaTest` · `ReferralSnapshotStateMachineTest` · `ReferralCaptureTest` ·
`AttributionLifecycleTest` · `OutboxEmissionTest` · `OutboxTransactionGuardTest` ·
`OutboxDeliveryTest` · `ReferEarnMerchantLifecycleEventTest` ·
`ReferEarnPayloadDataMinimizationTest` · `ReferEarnTenantIsolationTest` ·
`ReferEarnScopePurityTest` · `tests/Unit/Integrations/ReferEarn/ReferralCodeNormalizerTest` ·
`tests/e2e/phase-21r-a.spec.ts` · `tests/Support/JsonSchemaAssert.php`.
Extended: `resources/spa/src/pages/auth/RegisterMerchant.spec.ts` (+9).

The Plan §75.1 file-level names (`ReferralCaptureTest`, `AttributionLifecycleTest`,
`OutboxEmissionTest`, `OutboxDeliveryTest`) are used verbatim, since Plan §25/§75.1 test names are
part of the specification (CLAUDE.md §8). The additional files carry the guarantees §75.1 folds into
those four but which deserve their own failure signal.

---

## Database and migration proof

Three additive `create` migrations; **no shipped migration was edited** (ADR-004 forward-only).
Applied cleanly to the dev database and to every `RefreshDatabase` run:

```text
docker compose exec -T app php artisan migrate
  2026_07_22_000001_create_referral_snapshots_table ....... DONE
  2026_07_22_000002_create_re_outbound_events_table ....... DONE
  2026_07_22_000003_create_re_event_deliveries_table ...... DONE
```

Manifest lint (`MigrationManifestTest`, 6 tests / 6 assertions) passes with the three new entries:
every migration on disk is declared, no dangling entry, no duplicate, each carries an existing
data-dictionary reference, no destructive change, and every declared dependency is itself in the
manifest.

Constraints and guards proven by `Phase21RASchemaTest` (25 tests / 513 assertions) against real
PostgreSQL 16 by querying `information_schema` and `pg_constraint`:

| Proof | Result |
|---|---|
| three tables exist | ✅ |
| **no** Phase 21R-B table (`re_activity_rule_versions`, `re_qualification_periods`, `re_qualification_decisions`, `re_inbound_requests`) | ✅ absent |
| **no** R&E platform table (`reward_ledgers`, `referrer_accounts`, `referrer_payouts`, `referrer_statements`, `referral_campaigns`, `referral_codes`, `reward_rules`) | ✅ absent |
| **no** Wallet/20D-W table (`subscription_payments`, `subscription_payment_attempts`, `subscription_payment_reversals`, `wallet_webhook_inbox`, `billing_reconciliation_exceptions`, `merchant_wallet_accounts`) | ✅ absent |
| all three tables classified `TenantOwnership::EXEMPT` with a non-empty rationale | ✅ |
| `referral_snapshots.merchant_id` NOT NULL + UNIQUE + indexed | ✅ |
| **no** column on any of the three tables matching `referrer\|reward\|payout\|campaign\|commission\|earning\|balance\|msisdn\|phone\|email` | ✅ |
| raw code encrypted at rest, plaintext absent from the column, `$hidden` from serialization | ✅ |
| second snapshot for the same merchant rejected | ✅ `QueryException` |
| `capture_channel` / `snapshot_status` CHECKs reject unknown values | ✅ |
| `code_normalized` NULL **iff** `invalid_format` (both directions) | ✅ |
| capture-evidence immutability trigger | ✅ *"capture evidence is immutable"* |
| terminal-status trigger | ✅ |
| outbox `event_type` CHECK rejects `subscription.invoice_issued` (a 21R-B type) | ✅ |
| outbox payload append-only trigger | ✅ *"append-only"* |
| outbox delete blocked | ✅ *"never deleted"* |
| `UNIQUE (merchant_id, sequence_no)` | ✅ |
| delivery-attempt append-only trigger | ✅ |
| `response_body_truncated_redacted` bounded to 512 characters | ✅ |
| enum ↔ DB CHECK parity for all five enums, counting literals so an **extra** DB value cannot hide | ✅ |

### Disposable-database proof (migrate from zero on real PostgreSQL 16)

A throwaway database, migrated from **nothing**, inspected, then dropped. The dev database is never
touched.

```text
PostgreSQL 16.14 on x86_64-pc-linux-musl
CREATE DATABASE servana_p21ra_proof_20260722…
php artisan migrate --force        → the three 21R-A migrations DONE, last in the ledger
migrations = 114        base_tables = 93        (20H baseline: 111 / 90)

Phase 21R-A tables present : re_event_deliveries, re_outbound_events, referral_snapshots
FORBIDDEN table scan       : (empty)
    — checked: re_activity_rule_versions, re_qualification_periods, re_qualification_decisions,
      re_inbound_requests, reward_ledgers, referrer_accounts, referrer_payouts, referrer_statements,
      referral_campaigns, referral_codes, reward_rules, subscription_payments,
      subscription_payment_attempts, subscription_payment_reversals, wallet_webhook_inbox,
      billing_reconciliation_exceptions, merchant_wallet_accounts, merchant_billing_credits

CHECK constraints (11)
  re_event_deliveries  :: response_class_check
  re_outbound_events   :: content_sha256_check, delivered_at_check, delivery_status_check,
                          event_type_check, merchant_public_id_check
  referral_snapshots   :: capture_channel_check, confirmed_at_check, normalized_code_check,
                          raw_code_check, status_check

Indexes / uniques (13)
  referral_snapshots   :: pkey, ulid_unique, merchant_id_unique, code_normalized_index,
                          snapshot_status_last_transition_at_index
  re_outbound_events   :: pkey, ulid_unique, event_id_unique, merchant_id_sequence_no_unique,
                          delivery_status_next_attempt_at_index, merchant_id_event_type_index
  re_event_deliveries  :: pkey, re_outbound_event_id_attempted_at_index

Foreign keys (all RESTRICT — confdeltype 'r')
  referral_snapshots  -> merchants (r)
  re_outbound_events  -> merchants (r)
  re_event_deliveries -> re_outbound_events (r)

Triggers (4)
  referral_snapshots  :: referral_snapshots_guard_trigger
  re_outbound_events  :: re_outbound_events_append_only_trigger, re_outbound_events_no_delete_trigger
  re_event_deliveries :: re_event_deliveries_append_only_trigger

Row counts after migrate : referral_snapshots=0 re_outbound_events=0 re_event_deliveries=0
DROP DATABASE            : ok
dev database `servana`   : intact
leftover proof databases : 0
```

*Honesty note:* the first attempt of this script aborted at the foreign-key query on a PostgreSQL
`text || "char"` operator ambiguity (`confdeltype` needs an explicit `::text` cast), which left one
proof database behind. The query was fixed, the proof re-run end-to-end, and the orphan database was
dropped — `leftover proof databases = 0` above is the verified final state.

---

## State-machine proof

`ReferralSnapshotStateMachineTest` (10 tests / 69 assertions) asserts the transition sets
**exhaustively** rather than sampling, so an added transition fails the test:

```text
captured   -> invalid_format | validating
validating -> validated | rejected
validated  -> confirmed | expired_unconfirmed | rejected
confirmed | rejected | invalid_format | expired_unconfirmed -> (terminal)
```

Also proven: no self-transition anywhere (a retry is not a state change); every terminal state is a
dead end for **all** seven targets; no transition ever moves to a lower rank (no regression);
the confirmed snapshot rejects every transition through the action; and the DB trigger blocks a
regression applied directly with the query builder, bypassing the action entirely.

Outbox machine:

```text
pending -> delivering ; delivering -> delivered | pending | dead_letter
delivered | dead_letter -> superseded ; superseded -> (terminal)
```

Documented in `docs/architecture/state-machines/referral-snapshot.md` and
`docs/architecture/state-machines/re-outbound-event.md`.

### Deviation recorded: `validated → rejected`

The §25.6 ASCII diagram draws `rejected` only off `validating`. Plan §58B.5 **R-04** ("Code valid but
attribution conflict at R&E … R&E confirm response drives `rejected`") and §58A.1 ("results drive
the §25.6 snapshot machine (`validated`, `confirmed`, `rejected`, `expired_unconfirmed`)") require
the confirm step to be able to reject. The transition is therefore implemented, and it is a **forward**
move into a terminal state, so it violates neither the no-regression rule nor the DB trigger. It is
called out in the enum, in the state-machine document, and in the exhaustive transition assertion.

---

## Registration capture proof

`ReferralCaptureTest` (14 tests) exercises the real public endpoint end-to-end.

| Claim | Evidence |
|---|---|
| `?ref=` capture | snapshot `captured`, channel `query_param`, normalized `SERVANA-X8T2K`, `ValidateReferralCodeJob` queued once |
| manual capture + normalization | `  servana-x8 t2k  ` → `SERVANA-X8T2K`; raw submission preserved as evidence |
| channel precedence | an unstated channel defaults to `manual_entry` — the request never claims provenance it did not prove |
| central redirect | accepted as its own channel |
| malformed code (R-02) | `invalid_format`, `code_normalized` NULL, raw kept, **no job queued**, **no outbox event** |
| unreferred registration | merchant created, **no** snapshot, **no** event, no job |
| **R&E fault never fails registration (A-19, R-03)** | a real fault injected into the capture step's audit write; registration still 202, merchant + owner + membership exist, snapshot rolled back to the savepoint, zero events |
| transactional atomicity | forced rollback ⇒ no merchant, no user, no snapshot, no event |
| landing-metadata allowlist | `email`, `phone`, `ip`, `user_agent`, `referrer_name`, `notes` all dropped; only `utm_source`/`utm_campaign`/`landing_path` stored |
| empty allowlist result | column stays NULL rather than storing `{}` |
| one snapshot per merchant | repeat registration creates neither a second merchant nor a second snapshot |
| atomic event pair | `merchant.registration_started` (seq 1) + `merchant.admin_created` (seq 2) |
| audit without the code | `re.referral_captured` context carries channel, status and metadata **keys**; the code is absent |
| no synchronous partner call | the fake client records zero validate/confirm/deliver calls during the request |

**Non-breaking extension proof (Plan §75.1 requirement).** The as-built Phase 6 suite is unchanged
and green: `tests/Feature/Onboarding` → **23 passed / 2975 assertions**
(`MerchantSelfRegistrationTest`, `FirstTimeSetupTest`, `NoPlatformMerchantCreationTest`).

**Savepoint detail (root cause worth recording).** Capture is wrapped in a *nested* `DB::transaction`,
which Laravel implements as a SAVEPOINT. Without it, a failed statement would abort the whole
PostgreSQL transaction and a caught exception would leave registration unable to continue — the
A-19 guarantee would silently be a lie. The savepoint rolls back only the capture.

---

## Outbox atomicity proof

`OutboxEmissionTest` (20 tests) + `OutboxTransactionGuardTest` (1 test).

- **Transaction guard.** `EnqueueProductEvent` refuses to run at `transactionLevel() === 0`. Proven in
  a file with **no** database trait, because `RefreshDatabase` wraps every test in a transaction and a
  test there would silently assert nothing. The guard throws before any query, so no database is needed.
- **Commit/rollback together.** A forced rollback leaves neither the renamed merchant nor its events;
  the committing run leaves both — and leaves **two** events, the explicit `status_changed` plus the
  `identity_snapshot_changed` the rename triggers through the observer, proving the observer's event
  is bound to the same transaction.
- **Emission scope (§58B.1).** Parameterised across all seven snapshot statuses: `captured`,
  `validating`, `validated`, `confirmed`, `expired_unconfirmed` emit; `invalid_format` and `rejected`
  are silent; an unreferred merchant is silent.
- **Sequence.** `[1,2,3]` for one merchant while a second merchant independently starts at `1`.
- **Canonical hash.** `content_sha256` equals `CanonicalJson::sha256(payload)`; the hash is identical
  for two differently-ordered representations of the same object; list order is preserved; floats are
  refused (ADR-005 integer minor units).
- **Schema validation.** Every one of the five event types validates against its committed JSON
  Schema.
- **Forbidden fields.** Payload keys checked against the §58B.2 banned list **and** payload bytes
  checked against the merchant's own contact email, phone, internal id, name and referral code.
- **Dispatch.** `DeliverReOutboxJob` is dispatched `afterCommit()`, asserted by the dispatch flag on
  the pushed job, and **not** dispatched at all when the emission-scope rule suppresses the event.

### Root-cause block: the outbox had no dispatcher

```text
Observed problem:
  EnqueueProductEvent inserted the outbox row but nothing ever dispatched DeliverReOutboxJob, so a
  pending event would have sat undelivered forever.
Evidence:
  A review pass over the delivery path found DeliverReOutboxJob referenced only by its own class and
  its tests. Plan §58A.2/§67: "the normal path is event-driven dispatch at commit; the sweep is the
  safety net" — and the sweep is Phase 21N scheduler work that does not exist yet, so there was no
  second chance.
Affected files / functions:
  app/Domain/Integrations/ReferEarn/Actions/EnqueueProductEvent.php::handle()
Root cause:
  The enqueue action was written to the §13.17 table contract (insert the row) without the §58A.2
  delivery contract (dispatch at commit). The existing tests all asserted the ROW, so nothing failed.
Why this is the root cause:
  No other code path can dispatch delivery — the action is the only writer of re_outbound_events,
  and the scheduler sweep that would otherwise pick the row up is not in this phase.
Correct fix:
  Dispatch DeliverReOutboxJob::dispatch($event->id)->afterCommit() from the action. `afterCommit` is
  load-bearing: the insert happens inside the source fact's transaction, so a plain dispatch could
  hand a worker an event whose transaction later rolled back.
Files changed:
  EnqueueProductEvent.php (+1 dispatch, +1 import, docblock item 5)
Tests added:
  OutboxEmissionTest — "marks the delivery dispatch afterCommit so a rolled-back event is never
  delivered" and "dispatches no delivery job when the emission-scope rule suppresses the event";
  Queue::fake() added file-wide (the test QUEUE_CONNECTION is `sync`, so a real dispatch would have
  delivered inline and every "still pending" assertion would silently have tested the delivered state).
Test command / result:
  docker compose exec -T app php artisan test tests/Feature/Integrations/ReferEarn/OutboxEmissionTest.php
  → 22 passed (110 assertions)
Proof of resolution:
  The pushed job carries the correct event id AND afterCommit === true; an unreferred merchant
  dispatches nothing.
Remaining risk:
  A worker crash between commit and pickup leaves the event `pending` with a due `next_attempt_at`
  until the Phase 21N sweep exists. No data is lost; recorded in "Remaining risks" item 5.
```

**A second defect, in the test rather than the code, is recorded honestly.** The first version of the
dispatch test asserted `Queue::assertNotPushed(...)` after a rolled-back transaction. It failed —
`Queue::fake()` records a push immediately and ignores `afterCommit` entirely. The assertion was
therefore testing the fake's behaviour, not the guarantee. It was replaced with an assertion on the
dispatch flag, and the complementary fact (a rolled-back transaction leaves no event row at all) is
proven separately. The weaker assertion was not kept alongside the stronger one.

---

## Payload schema and data-minimization proof

`ReferEarnPayloadDataMinimizationTest` (28 tests / 116 assertions).

- The payload builder's **code** (comments tokenized away, so the class's own warning text cannot
  produce a false failure) contains none of `->contact_email`, `->contact_phone`, `->phone_encrypted`,
  `->email_encrypted`, `->suspension_reason`, `->raw_code_encrypted`, `->code_normalized`,
  `->address`, `client`/`Client`, `staff`/`Staff`, `invoice`/`Invoice`, `payment`/`Payment`,
  `toArray()`, `getAttributes()`, `attributesToArray()`.
- No spread, no `array_merge($merchant…)`, no `$merchant->toArray` — every payload is an explicit
  per-type allowlist.
- All five committed schemas set `additionalProperties: false`, require the full §58B.2 envelope, and
  declare no forbidden property.
- Exactly one schema file per event type, and no orphan schema.
- Identity is transmitted as a SHA-256 digest over `{name, business_category, receipt_display_name}`
  plus a changed-field **count**; contact details and address deliberately do not participate, even
  as hash input.

---

## Signing and delivery proof

`OutboxDeliveryTest` (19 tests).

| Claim | Evidence |
|---|---|
| canonical string (§9 rule 22) | asserted byte-for-byte: `METHOD\nPATH\nTIMESTAMP\nNONCE\nCONTENT_SHA256\nEVENT_ID\nEVENT_TYPE\nEVENT_VERSION`, plus a fixed signing vector |
| header set (§58A.2) | exactly `X-Citrus-Key-Id`, `-Event-Id`, `-Event-Type`, `-Event-Version`, `-Timestamp`, `-Nonce`, `-Content-SHA256`, `-Signature`, plus `Idempotency-Key = event_id` |
| signing timestamp is delivery time (R-21) | asserted distinct from the event's business `occurred_at` |
| **fail closed** (ADR-015) | unpinned / unknown / blank algorithm all raise; missing key id or secret raises |
| body-hash integrity | signing a body whose hash ≠ stored `content_sha256` raises rather than sending |
| 202 → delivered | `delivered_at` set, `attempt_count` 1, `next_attempt_at` cleared, one attempt row; bytes sent equal the canonical encoding and their hash matches |
| retry keeps id + hash (R-06) | two attempts, identical `event_id` and `content_sha256`, both recorded |
| 409 mismatch (R-07) | `dead_letter`, high-severity `re.event_dead_lettered` audit, and a second delivery attempt is refused — never mutate-and-resend |
| 422 (R-08) | `dead_letter` |
| 401/403 | stays retriable; credential failure logged without any credential value |
| backoff cap + max age | capped at 1 h (+≤20% jitter); an event older than the max age dead-letters |
| per-merchant ordering | a later `sequence_no` refuses to deliver while an earlier one is undelivered, then delivers once it lands |
| cross-merchant independence | one merchant is never held behind another |
| not-yet-due events | skipped |
| redaction | stored body contains none of the signature hex, email or referral code, retains the diagnostic text, and is ≤ 512 characters |

`ReferralCodeNormalizerTest` (27 tests) covers normalization determinism and the allowlist:
9 well-formed variants (case, padding, quotes, line wraps, zero-width and non-breaking characters)
all normalize to `SERVANA-X8T2K`; 11 malformed inputs — including SQL-ish and HTML payloads and an
oversized submission — all normalize to `null`, which is the `invalid_format` contract.

---

## Retry / dead-letter proof

Covered in the delivery table above. Policy source: `config/refer-earn.php`
(`backoff_base_seconds` 30, `backoff_cap_seconds` 3600, `max_age_days` 7), matching Plan §58A.2.
Every attempt — including the ones that only reschedule — writes one `re_event_deliveries` row, so
the attempt trail is the complete record.

---

## Route / API / OpenAPI proof

**No new route, no new controller, no new policy, no new permission.** The only contract change is
that the existing public `POST /api/v1/merchant-registration/self-register` request body gained three
optional fields.

```text
docker compose exec -T app php artisan servana:openapi
  Wrote docs/api/openapi.json (280 production routes).

npm run api:types            → api.ts regenerated
npm run api:contract:check   → OK — 235 paths, 280 operations
php artisan servana:permission-types --check
  resources/spa/src/types/generated/permissions.ts is up to date.
npm run typecheck            → clean
```

Path and operation counts are **unchanged** from Phase 20H (235 / 280). The `openapi.json` diff is
+53/−4 lines: the three optional request fields, and the four new `AuditEvent` values appearing in
the four audit-read response enums that publish that enum.

**Permission decision (recorded).** `platform.integrations.refer_earn.manage` carries
`owning_phase: Phase 21R-A` in the matrix, and it remains **`planned`**. The capabilities it
describes — rule-version creation, dead-letter replay, inbound key-set changes — are all Phase 21R-B
or Integrations-Health work; Phase 21R-A adds no R&E admin route, so activating the key would grant
authority over surfaces that do not exist. No permission was changed, so no permission-parity change
was required.

---

## Frontend / responsive / dark / accessibility proof

Touched exactly one screen: `resources/spa/src/pages/auth/RegisterMerchant.vue` (§12.1 item 5).

- optional `referral_code` field with an explicit "(optional)" label and a plain-language hint;
- `?ref=` pre-fill plus a dismissible "Referral code applied: …" notice (dismissing keeps the code);
- channel honesty — `query_param` for a URL referral, downgraded to `manual_entry` the moment the
  user edits the field;
- an **advisory** format hint that never disables submission and never becomes a field error;
- the referral value survives a server validation error;
- **no referrer identity is rendered** — Servana does not hold one;
- an unreferred submission omits the referral keys entirely, so its request body is byte-identical to
  the pre-21R-A contract.

**Recorded honestly:** the SPA does **not** currently send `referral_landing_metadata`. The field is
part of the capture contract (Plan §13.17 `landing_metadata`) and exists for the central-redirect
landing case, where the redirect surface — not this form — supplies the utm-style context. It is
accepted, allowlist-filtered and fully tested server-side, and adding a hidden metadata payload to
the registration form now would collect context the form has no legitimate source for.

```text
npm run lint       → 0 errors, 138 warnings  (= the origin/main baseline; no new warnings)
npm run typecheck  → clean
npm test           → 96 files, 490 tests passed   (20H baseline 481, +9 referral tests)
npm run build      → built
npx playwright test tests/e2e/phase-21r-a.spec.ts → 16 passed
```

Playwright coverage: pre-fill + dismissible notice; `query_param` vs `manual_entry` channel bodies;
edit-downgrades-channel; unreferred body has no referral keys; malformed code never blocks (R-02);
code survives a 422; no referrer identity anywhere in the rendered body; label + focus + Tab-to-submit
keyboard path; **axe (wcag2a + wcag2aa) at 360 / 768 / 1280 in both light and dark**, each with an
explicit no-horizontal-overflow assertion; and an axe scan of the invalid-format hint in both themes.

---

## Security and redaction proof

| Control | Evidence |
|---|---|
| raw referral code encrypted at rest | `Phase21RASchemaTest` — ciphertext in the column, plaintext through the cast, `$hidden` from `toArray()` |
| decrypted code never audited | `ReferralCaptureTest` — the audit context does not contain the code |
| decrypted code never logged | no log call in the capture path; §24.5 items are not passed to any logger |
| signature / nonce / key never logged or stored | delivery persists only class, status, error code and a redacted body; the credential-failure log carries event id, type and status only |
| partner response redaction | `DeliveryResponseRedactor` unit + integration proof: key-shaped values, emails, MSISDNs, referral codes and long hex runs all redacted **before** truncation, so a secret cannot survive by sitting past the cut |
| no secret in the repository | `gitleaks detect --source . --no-git --redact` → **no leaks found**; every credential is `env()` with a `null` default; `.env.example` placeholders are empty |
| fail-closed transport | `HttpReferEarnClient` is bound only when the integration is enabled **and** base URL, algorithm, key id and secret are all present; otherwise the deterministic fake is bound, so CI cannot reach a partner (Plan §81 rule 21) |
| no direct provider integration | `NoDirectProviderIntegrationTest` re-run green; nothing in this phase touches a provider |

---

## Tenant-isolation proof

`ReferEarnTenantIsolationTest` (6 tests). The isolation argument here is deliberately different from a
normal tenant-owned table: the three tables have **no merchant-facing surface at all**, so the test
proves that absence rather than asserting a scope that does not apply.

- No route path contains `referral`, `refer-earn`, `refer_earn`, `outbound-event`, `attribution` or
  `integrations/products`.
- No R&E controller, policy or resource file exists.
- Each snapshot is bound to exactly one merchant; two merchants submitting the **same** code produce
  two independent claims (R-22 — attribution uniqueness is R&E's decision, not Servana's).
- Outbox sequences are independent per merchant.
- Payloads carry the merchant **public ULID** and never the internal id.

---

## Permission parity proof

**No permission changed, so no parity change was required.** `docs/auth/permission-matrix.yaml`,
`PermissionRegistry`, the database projection and
`resources/spa/src/types/generated/permissions.ts` are all untouched;
`php artisan servana:permission-types --check` reports the generated file up to date.

Decision recorded: `platform.integrations.refer_earn.manage` carries `owning_phase: Phase 21R-A` in
the matrix and deliberately stays **`planned`**. Its description is "rule-version creation, outbox
dead-letter replay, inbound key-ID set changes" — the first and third are Phase 21R-B, and the second
needs an admin surface Phase 21R-A does not add. Activating a key whose entire surface does not exist
would grant an authority with nothing to authorise, and would have to be un-picked when the real
screens land. `platform.integrations.health.view` is `owning_phase: Phase 20D-W` and is likewise
untouched.

`PermissionMatrixTest` and `PermissionDatabaseProjectionTest` were **not** edited — the exact class
of stale hand-maintained expectation that broke Phase 20H's first CI run. They pass unchanged because
this phase genuinely changed no permission truth.

---

## Traceability updates

- `docs/traceability/servana-requirements.csv` — new row `SRV-REFERRAL-001` (Phase 21R-A).
- `docs/remediation/register.yaml` — `REM-RE-001` `not_started → in_progress` (21R-A half in progress,
  21R-B half blocked behind 20D-W / Gate W; closes `verified_complete` only when **both** halves
  merge); new `REM-RE-002` (`not_started`) for the deferred R&E sandbox verification, explicitly
  authorised by the Plan §80 Phase 21R-A entry-criteria fallback and required to close before Phase 25.

---

## Scope-purity audit

`ReferEarnScopePurityTest` (7 tests / 40 assertions) is the standing guard, not a one-off review:

- exactly the five 21R-A event types exist, in order;
- all eleven Phase 21R-B catalogue types are absent from the enum **and** rejected by the DB CHECK;
- no 21R-B table, no R&E platform table, no Wallet/20D-W table exists;
- an explicit 34-file inventory of the bounded context — a new file is either named here or is scope
  creep the test catches (the scan is recursive, because `glob('**')` in PHP silently misses nested
  directories and would have made the guard blind to `Clients/Dto`);
- no `Qualification`, `RewardLedger`, `ReferrerAccount`, `Campaign`, `Payout` or `Reconciliation`
  symbol appears anywhere in the context.

Working-tree audit: **87 entries — 66 new files, 21 modified — all Phase 21R-A.** No file outside the
phase's declared surface was touched; no shipped migration was edited; no test was weakened, skipped
or deleted.

The 21 modified files are exactly: `.env.example`; `AuditEvent`; the three merchant status-governance
actions; `RegisterMerchant` and `CompleteFirstTimeSetup`; `TenantOwnership`;
`MerchantRegistrationController` and `RegisterMerchantRequest`; `AppServiceProvider`;
`RegisterMerchant.vue` and its spec; the two generated contract artifacts; and seven documentation
files. Notably **absent**: `tests/Feature/Auth/PermissionMatrixTest.php` and
`tests/Feature/Auth/PermissionDatabaseProjectionTest.php` — the two files whose stale expectations
broke Phase 20H's first CI run. They are untouched because this phase changed no permission truth.

---

## Test commands and results

Every command below was run in this session; nothing is quoted from a previous phase. No filter that
selected zero tests was accepted as a pass.

| Command | Result |
|---|---|
| `docker compose exec -T app php artisan test tests/Feature/Integrations/ReferEarn/Phase21RASchemaTest.php` | **25 passed** (513 assertions) |
| `… ReferralSnapshotStateMachineTest.php` | **10 passed** (69 assertions) |
| `… ReferralCaptureTest.php` | **14 passed** (61 assertions) |
| `… AttributionLifecycleTest.php` | **11 passed** (38 assertions) |
| `… OutboxEmissionTest.php` | **22 passed** (110 assertions) |
| `… OutboxDeliveryTest.php` | **19 passed** (68 assertions) |
| `… ReferEarnMerchantLifecycleEventTest.php` | **11 passed** (22 assertions) |
| `… ReferEarnPayloadDataMinimizationTest.php` | **28 passed** (116 assertions) |
| `… ReferEarnTenantIsolationTest.php` + `ReferEarnScopePurityTest.php` | **13 passed** (59 assertions) |
| `… tests/Unit/Integrations/ReferEarn/ReferralCodeNormalizerTest.php` | **27 passed** |
| `docker compose exec -T app php artisan test --group=referearn` | **180 passed / 1 failed** — the failure was the first `afterCommit` assertion described in the outbox root-cause block (it asserted against `Queue::fake()` semantics, not the guarantee). The corrected file was re-run: `OutboxEmissionTest` **22 passed**, and the full serial suite below covers the whole group on the final code. The group run itself was **not** re-run, so no group total is claimed for the final code. |
| `docker compose exec -T app php artisan test tests/Feature/Onboarding` | **23 passed** (2975 assertions) — the Phase 6 as-built regression suite, unchanged |
| `docker compose exec -T app php artisan test --group=audit` | **117 passed** (1292 assertions) — after the 4 new `AuditEvent` cases |
| `docker compose exec -T app php artisan test tests/Feature/Infrastructure/MigrationManifestTest.php` | **6 passed** |
| `docker compose exec -T app php artisan test` (full, serial) | see Quality gates |
| `docker compose exec -T app php artisan test --parallel --processes=4 --recreate-databases` | see Quality gates |
| `npm test` (Vitest) | **96 files / 490 passed** (20H baseline 481; +9 referral cases) |
| `npx playwright test tests/e2e/phase-21r-a.spec.ts` | **16 passed** |
| `npx playwright test` (full) | see Quality gates |

### Two environment flakes, recorded rather than hidden

1. **Full Playwright run under contention — 4 failed / 428 passed.** The run was executed
   concurrently with the 4-process parallel backend suite *and* a Docker image build. The four
   failures (`appointments.spec.ts:112`, `audit.spec.ts:105`, `auth-magic-link.spec.ts:141`,
   `phase-20b.spec.ts:313`) are all pre-existing specs untouched by this phase, and all four are
   `waiting for …` locator timeouts. **Re-run in isolation: `66 passed`, 0 failed.** No code was
   changed for this.
2. **Docker `app` image build — `failed to build: NotFound: forwarding Ping: no such job …`.** A
   BuildKit session error under the same contention; the `nginx` prod image in the same command
   succeeded. **Re-run in isolation: both images build.** No Dockerfile change.

---

## Quality gates

| Gate | Result |
|---|---|
| `composer validate` / Pint | `vendor/bin/pint --test` → **PASS, 1528 files** |
| Larastan level 8 (+ custom rules) | **[OK] No errors** (1185 files) |
| Backend serial (PostgreSQL 16 + Redis) | **1844 passed / 7 skipped / 0 failed** (11005 assertions), 1114 s — 20H baseline 1663/7 |
| Backend parallel (4 processes, recreated databases) | **identical: 1844 passed / 7 skipped / 0 failed** (11005 assertions), 644 s |
| Migration proof on a disposable PostgreSQL 16.14 database | 114 migrations / 93 tables; forbidden-table scan **empty**; database dropped; dev database intact; 0 leftovers |
| ESLint | **0 errors**, 138 warnings — identical to the `origin/main` baseline |
| `vue-tsc --noEmit` | clean |
| Vitest | **96 files / 490 tests passed** |
| `npm run build` | built |
| Playwright | full suite **432 tests** = the 20H baseline of 416 **+ exactly the 16 new Phase 21R-A tests**; 428 passed under contention with 4 pre-existing specs timing out, all **66 passed** on isolated re-run; `phase-21r-a.spec.ts` **16 passed** |
| OpenAPI + generated types | `servana:openapi` → 280 routes; `api:contract:check` → **OK, 235 paths / 280 operations** (unchanged from 20H); `servana:permission-types --check` → up to date. **Determinism proven:** two full regeneration passes produced byte-identical artifacts — `openapi.json` `61b6903a…c34c0cc0`, `api.ts` `70220382…c6a8ae4a`, `permissions.ts` `02e42d7d…e7616694` |
| `composer audit` | **No security vulnerability advisories found** |
| `npm audit --audit-level=high` | **found 0 vulnerabilities** |
| `gitleaks detect --source . --no-git --redact` | **no leaks found** (~20.18 MB scanned) |
| Docker | `docker build -f docker/php.Dockerfile --target dev` ✅ · `docker build -f docker/nginx.Dockerfile --target prod` ✅ (the exact CI targets) |

---

## Final branch state

```text
branch                        : phase-21r-a-referral-capture-outbox
base / merge-base             : 6047835b3a388fff5cc92a13370963635700f5e3
origin/main                   : 6047835b3a388fff5cc92a13370963635700f5e3   (unchanged during the phase)
origin/main...HEAD            : 0	1                                    (exactly one completion commit)
working tree                  : clean
git diff --check              : clean
PR                            : none — not authorised in this session
```

---

## Remaining risks

Only genuine, unresolved items. Nothing here is a reassurance.

1. **The R&E contract is unverified against the real partner (`REM-RE-002`, must close before Phase
   25).** No credentials, no product code, no signing algorithm and no schema versions were issued.
   The signing string, header set and response mapping are transcribed from the Servana Plan, not
   from the R&E contract, and the product code `SRV` is an assumption (Plan §81 rule 24). A mismatch
   would surface in production as `401/403` (pause + alert) or `422` dead-letters rather than at
   build time. Mitigations in place: the algorithm is unpinned and fails closed; the raw R&E result
   code is stored verbatim so a later mapping pin has the history; a pin change is a one-line
   configuration change, not a code change.
2. **The confirm-window value is a configured guess.** Plan §81 rule 24 lists the R&E attribution
   confirm window as a blocking ambiguity. `REFER_EARN_CONFIRM_WINDOW_HOURS` defaults to 168 h. No
   expiry sweep runs it yet (scheduling is Phase 21N), so today a snapshot R&E never answers for
   stays `validated` indefinitely rather than becoming `expired_unconfirmed`. That is visible in the
   data, not silent, and it suppresses nothing (a `validated` snapshot still permits emission).
3. **`MerchantIdentityObserver` does not fire on query-builder mass updates.** Every as-built writer
   of `merchants.name` and the identity profile columns uses a model save, so nothing is missed
   today; a future bulk-rename path would have to emit explicitly. Stated in the observer's docblock.
4. **`merchant.status_changed` always reports `reason_category: manual`.** No as-built governance
   request supplies a category, and inferring one from operator prose would risk leaking the very
   free text §58B.1 forbids. The parameter exists and is tested, so a future request field populates
   it without touching the event contract.
5. **No delivery sweep or dead-letter monitor is scheduled.** Delivery is dispatched `afterCommit` on
   the normal path, which is what Plan §58A.2 calls the normal path — but the every-minute sweep, the
   hourly gap reconciliation and the dead-letter alert are Phase 21N/§67 scheduler work. Until then,
   an event whose dispatch is lost (worker crash between commit and pickup) waits for the sweep that
   does not exist yet. The row is `pending` with a due `next_attempt_at`, so no data is lost.
6. **`platform.integrations.refer_earn.manage` remains `planned` although the matrix owns it to
   21R-A.** Deliberate and recorded above; a reviewer who expects the key active will not find it.
7. **The Integrations Health screen is deferred to 20D-W.** Plan §80 lists it under the 21R-A
   frontend; the reasoning for deferring is recorded above and in PROGRESS. If the product owner
   disagrees, the R&E panel is a small addition once the permission question is settled.
8. **`JsonSchemaAssert` is a purpose-built validator, not a library.** It fails closed on any keyword
   it does not implement, so it cannot silently half-check — but it is Servana code validating
   Servana schemas, which is weaker than an independent implementation.

---

## Deferred work and owner phases

| Deferred item | Owner phase | Reason |
|---|---|---|
| Wallet payment runtime, subscription payment attempts/payments/receipts/reversals, wallet webhook inbox, billing reconciliation exceptions, billing credits, STK/PayBill/Till/C2B/Daraja | **20D-W** | External Gate W CLOSED |
| `subscription.*` + `activity.*` event emission | **21R-B** | requires 20D-W payment received/cleared sources |
| Monthly qualification engine, `re_activity_rule_versions`, `re_qualification_periods`, `re_qualification_decisions` | **21R-B** | Plan §58B.3 |
| Inbound R&E reconciliation surface, `re_inbound_requests`, `ReconcileReEventGapsJob` | **21R-B** | Plan §58B.4, §80 Phase 21R-B backend |
| Personnel bulk SMS | **21S** | Plan §64 |
| Notifications, queues/Horizon topology, scheduled reports | **21N** | Plan §§66–67, §69 |
| Search | **22** | Plan §68 |
| Release-wide security / responsive / dark / accessibility audit | **23** | Plan §80 |
| Performance optimization | **24** | Plan §72 |
| Production readiness / deployment | **25** | Plan §§76–78 |
| Referrer accounts, referral codes as system of record, campaigns, reward rules/calculation/ledger, referrer payouts, reward statements | **not Servana** — Citrus Refer & Earn platform | ADR-013 ownership matrix |
| Payment provider credentials, STK/C2B/Daraja, raw provider callbacks | **not Servana** — Wallet by Citrus | ADR-012, Plan §9 rule 20 |

---

## Exact next action

**Product-owner authorization to open the Phase 21R-A pull request and observe CI.**

Phase 21R-A is `local_complete pending PR CI/review/merge`: the single completion commit exists and
the branch is pushed. No PR was created, nothing was merged, and no branch was deleted — all of that
needs separate authorization.

Nothing else is blocking. Phase 21R-B remains blocked behind Phase 20D-W, which remains blocked
behind External Gate W; 21R-A does **not** unblock it on its own.
