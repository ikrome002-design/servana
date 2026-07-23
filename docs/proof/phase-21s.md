# Phase 21S Proof — Personnel Bulk SMS

> Plan sections implemented: **§64** (Personnel Bulk SMS), **§80 Phase 21S entry**, **§13.13**
> (canonical DDL for the four SMS tables), **§19.3/§19.4** (permission matrix + non-overridable
> "Personnel can never gain contact export"), **§20** (plan-entitlement enforcement), **§22**
> (billing-status gate), **§24.5** (log redaction), **§70** (audit), **§73** (threat model:
> personnel contact extraction), **§74** (privacy/masking/retention), **ADR-010** (personnel
> contact protection), **ADR-005** (integer minor units).
>
> Remediation item closed by this phase: **REM-SMS-001** (C1, FEATURE_DELIVERY_OBLIGATION).

---

## Lifecycle

| Field | Value |
|---|---|
| Phase | 21S — Personnel Bulk SMS |
| Status | `local_complete pending PR CI/review/merge` |
| Branch | `phase-21s-personnel-bulk-sms` |
| Base commit | `b5a8733616a4603996e18695db31528299cdf8d7` (PR #44 merge commit) |
| Predecessor | Phase 21R-A (PR #44, merged) — reconciled to `verified_complete` in this branch |

---

## Branch and repository state

Preflight (Section 6 of the phase directive) executed read-only on `main` before any file change:

```
git fetch origin --prune          -> ok (pruned deleted origin/phase-21r-a-referral-capture-outbox)
git fsck --full                   -> exit 0 (2 dangling objects only; no corruption)
branch                            -> main
git status --porcelain -uall      -> 0 entries
git diff --cached --name-only     -> 0 entries
git diff --check                  -> exit 0
HEAD                              -> b5a8733616a4603996e18695db31528299cdf8d7
origin/main                       -> b5a8733616a4603996e18695db31528299cdf8d7
merge-base(origin/main, HEAD)     -> b5a8733616a4603996e18695db31528299cdf8d7
divergence (left-right count)     -> 0  0
local  branch phase-21s-...       -> absent
remote branch phase-21s-...       -> absent
```

Branch creation:

```
git checkout -b phase-21s-personnel-bulk-sms
branch      = phase-21s-personnel-bulk-sms
HEAD        = b5a8733616a4603996e18695db31528299cdf8d7
origin/main = b5a8733616a4603996e18695db31528299cdf8d7
merge-base  = b5a8733616a4603996e18695db31528299cdf8d7
divergence  = 0  0
tree        = clean (0 entries, untracked included)
```

---

## Source-of-truth files read

Read before any file change, in the directive's hierarchy order:

- `CLAUDE.md`; `Servana Software Development Plan.md` (§5.3 remediation index, §8 ADR table +
  ADR-010, §13.13 canonical DDL, §19.2–19.5 permission matrix, §20 entitlements, §22 billing
  status, §24.1 route classes, §64, §68, §70, §73, §74, §80.1 dependency chain, §80 Phase 21S
  entry).
- `docs/PROGRESS.md` (phase table rows 15A / 16A / 16C / 18A / 19 / 20A / 20C / 20D-W / 20H /
  21R-A / 21R-B / "21N / 21S" / 22).
- `docs/remediation/register.yaml` (REM-SMS-001 row).
- `docs/auth/permission-matrix.yaml` (`personnel.my_sms.send`, `personnel.my_served_clients.view`,
  `personnel.my_sessions.view` rows).
- `docs/frontend/navigation/role-navigation.yaml` (`merchant_personnel` list).
- `docs/architecture/migrations/manifest.yaml` (tail: 20H + 21R-A entries — note format).
- `docs/architecture/data-dictionary/**`, `docs/architecture/state-machines/**` (file inventory +
  `earnings-query` / `referral-snapshot` as format references).
- Live code: `routes/api.php` (personnel own-scope block), `app/Http/Routing/RouteClass.php`,
  `app/Http/Middleware/*`, `app/Domain/Tenancy/TenantContext.php`,
  `app/Domain/Tenancy/TenantOwnership.php`, `app/Domain/Clients/**`,
  `app/Domain/Scheduling/**`, `app/Domain/Billing/**`, `app/Domain/Audit/**`,
  `app/Domain/Integrations/ReferEarn/**`, `app/Domain/Auth/Services/PermissionRegistry.php`,
  `app/Support/Money.php`, `app/Http/Api/ApiPagination.php`, `resources/spa/src/**`,
  `tests/Feature/Auth/*Permission*`, `tests/Feature/Tenancy/*`.

---

## Phase 21R-A reconciliation evidence

Verified live through `gh` and `git` before branch creation. All values below are **read from the
live repository / GitHub**, not from the directive text.

| Field | Live value |
|---|---|
| PR | [#44 — Phase 21R-A: Implement referral capture and R&E outbox](https://github.com/ikrome002-design/servana/pull/44) |
| State | `MERGED` (`mergedAt` `2026-07-22T10:17:57Z`) |
| Base ref | `main` |
| Head ref / SHA | `phase-21r-a-referral-capture-outbox` / `7b7cdb342ffa37df09ac91a030d8417746266710` |
| Base before 21R-A | `6047835b3a388fff5cc92a13370963635700f5e3` (Phase 20H PR #43 squash) |
| Implementation commit | `a9ee4445d56be29217c9db146d585228bf3f27ed` |
| CI-stabilization patch head | `7b7cdb342ffa37df09ac91a030d8417746266710` |
| **Merge commit** | **`b5a8733616a4603996e18695db31528299cdf8d7`** |
| Merge strategy | GitHub **merge commit** (not squash) — `mergeCommit.oid` ≠ head SHA and `origin/main` is a merge commit. Recorded truthfully; history not rewritten. |
| Final CI run | `29909918754`, `event=pull_request`, `status=completed`, `conclusion=success`, `headSha=7b7cdb34…` |
| Required jobs | `Backend — Pint, Larastan, Pest` SUCCESS · `Frontend — ESLint, vue-tsc, Vitest, build` SUCCESS · `Docker — build images` SUCCESS · `Security — gitleaks` SUCCESS · `E2E — Playwright` SUCCESS |
| `reviewDecision` | **blank** — solo-maintainer governance exception; **not** independent approval |
| Governance evidence | 1 matching PR comment found: <https://github.com/ikrome002-design/servana/pull/44#issuecomment-5044610118> |
| Remote branch cleanup | `git ls-remote --heads origin phase-21r-a-referral-capture-outbox` → 0 refs (deleted) |
| Local branch cleanup | existed, reported merged into `main`, deleted with `git branch -d` (was `7b7cdb3`) |
| `origin/main` == merge commit | yes |
| local `main` == merge commit | yes |

Phase 21R-A lifecycle is therefore reconciled from `local_complete pending PR CI/review/merge` to
**`verified_complete`** in `docs/PROGRESS.md`, `docs/CHANGELOG.md`, `docs/proof/phase-21r-a.md`,
`docs/traceability/servana-requirements.csv` and `docs/remediation/register.yaml`.

### Phase 21R-A deferred owners preserved (not closed by 21S)

- **REM-RE-002** — no R&E sandbox credentials / algorithm / product code available; delivery is
  fixture-verified only. Remains **open**; must close before Phase 25.
- **Integrations Health screen** and `platform.integrations.health.view` — **deferred to Phase
  20D-W** (the screen carries Wallet panels; Gate W is closed).
- **21R-B** (`subscription.*` / `activity.*` events, qualification engine, inbound reconciliation,
  gap reconciliation) — blocked until 20D-W exists.
- **21N** (notifications, queue topology/Horizon, scheduled reports) — blocked until 20D-W exists.

---

## Gate W / dependency decision

**External Gate W is CLOSED.** Read-only evidence collected on the merge commit:

| Path | Exists |
|---|---|
| `docs/integrations/wallet/gate-w-evidence.md` | **no** |
| `docs/integrations/wallet/` | **no** |
| `docs/proof/phase-20d-w.md` | **no** |
| `docs/integrations/` | yes — contains **only** `refer-earn/` (contract pins, credentials receipt, 6 event schemas) |

No Wallet Servana Collections Slice evidence, no sandbox service-account credentials, no pinned
Wallet OpenAPI hash, no contract suite, no STK/C2B transcript, no signed-webhook transcript.

Next-executable-phase determination against Plan §80.1
(`(17,18,20D-W) → 21N ; 16C + 15A(consent) → 21S`):

| Phase | Status | Reason |
|---|---|---|
| 20D-W | **blocked** | External Gate W closed (§80.2). |
| 21R-B | **blocked** | Entry criteria require 21R-A **and** 20D-W (payment received/cleared sources). |
| 21N | **blocked** | §80.1 dependency `(17,18,20D-W) → 21N`. |
| **21S** | **eligible** | §80.1 dependency `16C + 15A(consent) → 21S`; both prerequisites live-verified below. |

### 15A / 16C prerequisite proof (live, not from memory)

- **Phase 15A** — `docs/PROGRESS.md` row: `verified_complete`, PR #24 merged into `main`
  (merge commit `81a5866`, final head `1fcfa40`), CI run `28338582235`, five required checks
  SUCCESS. Consent substrate present in code: `client_consents` table classified `BRANCH_OWNED`
  + `COMPOSITE_CONSISTENCY` in `TenantOwnership`; `App\Domain\Clients\Models\ClientConsent`
  (`BelongsToMerchant` + `BelongsToBranch`), `ConsentChannel` (`sms`), `ConsentState`
  (`opted_in` / `opted_out`), `ChangeClientConsent` action, unique `(client_id, channel)`.
- **Phase 16C** — `docs/PROGRESS.md` row: `verified_complete`, PR #28 merged into `main`
  (squash `ffe37cc`, final head `79746bb`). Service-session substrate present:
  `App\Domain\Scheduling\Models\ServiceSession` (branch-owned, `status` cast to
  `ServiceSessionStatus` with a `completed` terminal state), `ServiceSessionStateMachine`,
  `service_sessions` in `TenantOwnership::BRANCH_OWNED` + `COMPOSITE_CONSISTENCY`, and the
  personnel own-scope read surface `GET /api/v1/personnel/me/sessions`.

No missing substrate. Phase 21S proceeds.

---

## Scope statement

Implement controlled in-platform SMS from a **Personnel** user to **clients that user personally
served**, with contact protection as the phase-defining invariant. Delivered surface:

own-scope served-client list (masked) → recipient selection under a configured max batch →
composer with character/segment counting → server-authoritative preview (eligibility + cost) →
explicit confirmation with server revalidation → transactional campaign + recipient snapshots →
queued delivery through a provider-adapter abstraction → delivery-attempt tracking with transient-
only retry → permanent-failure / opt-out suppression → `sms_billing_entries` roll-up → final
campaign status in the UI → typed audit events → permission / entitlement / billing-status gates →
OpenAPI + generated TypeScript contracts → Personnel SMS screen + store → responsive / dark /
accessibility proof → complete no-contact-export proof.

## Explicit exclusions

CSV/XLSX/PDF/print/clipboard export of contacts · any bulk full-phone API response · full-phone
frontend persistence, URL/query params, logs, analytics, audit context, OpenAPI examples or
Playwright artefacts · unmasked contact tables · cross-personnel client lookup · marketing
automation · campaign templates beyond the nullable `message_template_id` column · subscription
payment events · R&E qualification engine (21R-B) · notifications / scheduled reports (21N) ·
Wallet or direct provider payment runtime (20D-W; Gate W closed) · search indexing (22) ·
external CRM export · provider dashboard integration · any route shaped like
export/download/print/copy for contacts.

---

## Pre-implementation inventory (as-built substrate)

Recorded **before** any SMS code was written, as required.

### Clients + consent (15A)

| Item | Live state |
|---|---|
| `clients` | branch-owned; `ulid` route key; `phone_encrypted` + `email_encrypted` (AES-256-GCM `encrypted` cast), `phone_index` keyed-HMAC blind index, `phone_last_four`. `$hidden = [phone_encrypted, phone_index, email_encrypted]`. `maskedPhone()` → `••• ••• 1234`; `maskedEmail()`. `status` = `ClientStatus` (`active`/`archived`), `scopeActive()`. |
| `client_consents` | branch-owned; `channel` = `ConsentChannel::Sms` only; `state` = `ConsentState::OptedIn|OptedOut`; `source`; `changed_at`; unique `(client_id, channel)`. **No row = no consent recorded.** |
| Actions | `CreateClient`, `UpdateClient`, `ChangeClientConsent`. |
| Support | `PhoneNumberNormalizer`, `ClientContactIndex` (blind index). |

### Service sessions (16C)

| Item | Live state |
|---|---|
| `service_sessions` | branch-owned; columns include `client_id`, `service_id`, `staff_profile_id`, `status`, `completed_at`. Composite FK `(branch_id, merchant_id) → merchant_branches(id, merchant_id)`. |
| `ServiceSessionStatus` | `pending` · `in_progress` · `completed` · `cancelled`; `completed` is terminal. **`completed` is the served-client predicate for 21S.** |
| Own-scope precedent | `PersonnelServiceSessionController::index()` derives `StaffProfile` from `TenantContext::merchantUser()`; no staff identifier is accepted from the client; `PersonnelServiceSessionIndexRequest` allowlists sorts only. `PersonnelServiceSessionResource` returns `phone_masked`, never a full phone. This is the pattern 21S reuses. |
| Queue/appointments | `queue_entries` / `appointments` exist but are **not** used to derive served clients — Plan §64 says *"completed at least one service session"*, so `service_sessions` alone is the source. |

### Tenancy

`TenantOwnership::BRANCH_OWNED` / `TENANT_OWNED` / `EXEMPT` / `MODELS` / `COMPOSITE_CONSISTENCY`
are the single registry read by `TenantColumnCoverageTest` and `ModelTenancyTraitCoverageTest`;
an unregistered table fails coverage. `TenantContext` exposes `merchant()`, `merchantUser()`,
`branchIds()`, `canAccessBranch()`, `permissions()`, `can()`.

### Route classification and middleware

`RouteClass` = `public_mutation` · `authenticated_global_mutation` · `tenant_mutation` ·
`branch_mutation` · `financial_mutation` · `platform_mutation` · `provider_webhook_mutation` ·
`liveness_readiness`, each with required/forbidden middleware enforced by
`RouteSecurityContractTest`. `financial_mutation` **requires** `EnsureIdempotentRequest`.
Middleware present: `EnsurePermission`, `EnsureBranchScope`, `EnsureBillingMutable`,
`EnsureIdempotentRequest`, `EnsureMerchantActive`, `ResolveTenantContext`, `RequireFreshMfa`,
`EnsurePrivilegedMfa`, `CorrelationIdMiddleware`. **There is no entitlement middleware** — see
the conflict register below.

### Entitlement substrate (20A/20B)

`plan_entitlements(plan_id, entitlement_key, limit_int, enabled)` + `ResolvePlanEntitlement`
(pure) + `EntitlementDecision` (`allowed`, `entitlement_absent`, `entitlement_disabled`,
`entitlement_limit_exceeded`, `no_active_plan`) + `PlanContextResolver` interface. The container
binds `PlanContextResolver` → `UnboundPlanContextResolver`, which **returns `null` for every
merchant**. `merchant_subscriptions(merchant_id, plan_id, status)` exists (20B) with a partial
unique index for one non-terminal subscription per merchant, but nothing resolves it into a plan.
No production code consumes the entitlement resolver today.

### Billing status

`EnsureBillingMutable` reads **only** `merchants.billing_status` via
`Merchant::billingBlocksMutations()`; `read_only_grace` and `suspended_billing` block mutations,
`trialing`/`active`/`overdue` allow them. Reads are never blocked.

### Audit

`AuditEvent` (backed enum, ~200 cases) with `domain(): AuditDomain` (`general`/`finance`/
`compensation`) and `severity(): AuditSeverity` (`info`/`notice`/`warning`/`high`/`critical`)
match arms. `AuditRecorder`/`DatabaseAuditRecorder`; `AuditMutationCoverage`;
`AuditValueMasker`. Coverage guarded by `AuditEventCoverageTest`, `AuditMutationCoverageTest`,
`AuditSeverityCoverageTest`. 21R-A precedent: context carries ULIDs/status/response class only,
never decrypted secrets.

### Provider-adapter conventions (21R-A, the model to follow)

`ReferEarnClientInterface` + `FakeReferEarnClient` + `HttpReferEarnClient`;
`Dto/` result objects; `DeliveryResponseRedactor` (bounded, redacted body before persistence);
`CitrusEventSigner` **fails closed** when the algorithm/key/contract version is unpinned;
`re_event_deliveries` is append-only (trigger) with `response_body_truncated_redacted` bounded to
512 chars. `NoDirectProviderIntegrationTest` forbids Safaricom/Daraja/STK/C2B in Servana — an SMS
provider adapter is unrelated to platform-billing provider integration and must not introduce any
of those symbols.

### Redaction / rate limiting

Named limiters in `AppServiceProvider`: `magic-link-request`, `magic-link-verify`, `registration`,
`invitation-accept`, `mfa-confirm`, `mfa-challenge`, `api` (120/min), `finance-sensitive`
(30/min), `search` (60/min), `file-upload` (20/min). Plan §64 requires rate-limited **search and
sends** — `search` already exists and is reused; a send limiter is added.

### Money

`App\Support\Money` — integer minor units only, `guardInt()` overflow detection, `toArray()` →
`{amount, currency, formatted}` (Plan §11.4). `Currency::KES`.

### Existing export prohibition

No contact-export route exists anywhere (`REM-SMS-001` repository evidence, re-verified by route
inspection). `PermissionRegistry` documents "there is NO contact/client export key anywhere
(guardrail §6.8)"; Plan §19.4 makes "Personnel can never gain contact export" non-overridable.

### Frontend

`resources/spa/src/pages/personnel/` = `DashboardStub.vue`, `MyAppointments.vue`, `MyQueue.vue`,
`MyServiceSessions.vue`, `Earnings.vue` (+ specs). Routes in
`resources/spa/src/router/routes/personnel.ts` under `PersonnelLayout`. Stores are Pinia setup
stores in `resources/spa/src/stores/` calling `apiClient`. UI components `Sv*` in
`components/ui/`. `MyServiceSessions.vue` renders masked contact only and holds no phone in
store state — the pattern 21S follows. Nav rows already declared in
`docs/frontend/navigation/role-navigation.yaml`: `personnel.my-served-clients`
(`availability: planned`) and `personnel.my-sms` (`label: Client SMS`, `availability: planned`,
`permission: personnel.my_sms.send`, `phase: Phase 21S`).

### Permission substrate

`PermissionRegistry` (PHP catalogue + role default grants + grantable overrides) ↔
`docs/auth/permission-matrix.yaml` ↔ DB projection ↔
`resources/spa/src/types/generated/permissions.ts` ↔ `docs/proof/phase8-matrix.txt`.
Current counts: **128 active / 40 planned** (`PermissionPlannedKeyIsolationTest`,
`Phase20HPermissionActivationTest`). A `planned` key must be absent from the PHP registry, the DB
projection, generated TypeScript, every role grant set, and every route's `EnsurePermission`
middleware.

---

## Source-of-truth conflicts and decisions

Conflicts found during inventory, with live evidence, consequence, and the smallest safe
correction. None required a product-owner question — each is resolvable from an authoritative
source (the Plan outranks generated/hand-maintained docs).

### C-21S-1 — `personnel.my_served_clients.view` owning phase is wrong in three places

| Source | Claim |
|---|---|
| `docs/auth/permission-matrix.yaml:2449` | `owning_phase: Phase 21N` |
| `docs/frontend/navigation/role-navigation.yaml` (`personnel.my-served-clients`) | `phase: Phase 15A` |
| **Plan §64 (Phase 21S)** | *"personnel opens served-clients view (own served clients only, paginated, masked contact)"* |
| Plan §80 Phase 21N entry | queues, notifications, scheduled reports — **no** served-clients surface |
| Plan §80 Phase 21S entry | **Frontend:** *"served-clients view (masked), recipient selection…"* |

**Consequence if unresolved:** the served-client list is the entry point of the SMS flow. Shipping
it while its permission key stays `planned` would be permission drift (a live route with no
matrix-active key), and gating it under `personnel.my_sms.send` instead would conflate a *read*
(`allow_read` in billing read-only) with a *send* (`block`), so a merchant in read-only grace
would lose the ability to read their own served-client list.

**Smallest safe correction (applied):** activate `personnel.my_served_clients.view` in Phase 21S
alongside `personnel.my_sms.send`, and correct `owning_phase` → `Phase 21S` in the matrix and
`phase: Phase 21S` + `availability: live` in the navigation YAML. Attributes are taken verbatim
from the existing matrix row (scope `own`, `entitlement_key: null`,
`billing_read_only_behavior: allow_read`, severity `info`, non-overridable, default role
`personnel`) — nothing is widened.

### C-21S-2 — the §20 entitlement gate has no runtime; `sms` could never be granted

**Evidence:** `AppServiceProvider` binds `PlanContextResolver` → `UnboundPlanContextResolver`,
whose `resolveActivePlan()` returns `null` unconditionally (docblock: *"Phase 20B provides the
real implementation reading the active subscription"*). Phase 20B shipped
`merchant_subscriptions` (with `plan_id` and a partial unique index for one non-terminal
subscription per merchant) but never replaced the binding. `grep` shows **no** production consumer
of `ResolvePlanEntitlement` at all, and **no entitlement middleware exists**.

**Plan authority:** §20 — *"Merchant's effective entitlements derive from the active
`merchant_subscriptions.plan_id`"*; *"an entitlement gate runs after permission resolution and
before period-lock (§9.4 step 10)… returns 403 with an upgrade-relevant code"*. §20 names bulk SMS
explicitly: *"bulk SMS (`personnel.my_sms.send`) requires the SMS entitlement"*. The matrix row
for `personnel.my_sms.send` carries `entitlement_key: sms`.

**Consequence if unresolved:** Phase 21S cannot satisfy its own acceptance criterion
("entitlement gates work"). Leaving the unbound resolver would make every SMS preview/send return
`no_active_plan` → the feature is dead on arrival; deleting the entitlement requirement would
violate §20 and the matrix.

**Smallest safe correction (applied):** ship `SubscriptionPlanContextResolver` (reads the single
non-terminal `merchant_subscriptions` row and returns its plan) and an `EnsureEntitlement`
middleware that runs after `EnsurePermission`, returning the §11.5 structured 403 with the
`EntitlementDecision` code. Scope is deliberately minimal: no new table, no new permission, and
the only route class that uses it is 21S's. Because nothing else consumes the resolver today, the
blast radius is exactly Phase 21S.

### C-21S-3 — no SMS provider credentials exist anywhere

**Evidence:** no `config/services.php` SMS entry, no `SMS_*` environment key, no provider contract
document under `docs/integrations/`. The directive forbids inventing provider credentials.

**Correction (applied):** `SmsProviderClientInterface` with a deterministic
`FakeSmsProviderClient` bound by default (and always in `testing`); `HttpSmsProviderClient` **fails
closed** — it throws on a missing base URL, API key, sender id, or unpinned contract, and it is
only bound when every one of those is configured. CI never reaches a live provider. Live-provider
callback verification is recorded as a deferred item (**REM-SMS-002**) owned by provider-credential
onboarding, exactly as REM-RE-002 was recorded for R&E.

---

## File-level implementation checklist

### Migrations (4, forward-only, ADR-004)

| File | Table |
|---|---|
| `2026_07_22_000004_create_personnel_sms_campaigns_table.php` | `personnel_sms_campaigns` |
| `2026_07_22_000005_create_personnel_sms_recipients_table.php` | `personnel_sms_recipients` |
| `2026_07_22_000006_create_sms_delivery_attempts_table.php` | `sms_delivery_attempts` |
| `2026_07_22_000007_create_sms_billing_entries_table.php` | `sms_billing_entries` |

### Bounded context `app/Domain/Messaging/Sms/`

| Group | Files |
|---|---|
| Actions (9) | `PreviewSmsCampaign`, `CreateSmsCampaign`, `ConfirmSmsCampaign`, `QueueSmsCampaign`, `DeliverSmsRecipient`, `RecordSmsDeliveryReceipt`, `FinalizeSmsCampaign`, `CancelSmsCampaign`, `CreateSmsBillingEntry`, `SuppressSmsRecipient` |
| Clients (3 + 1 DTO) | `SmsProviderClientInterface`, `FakeSmsProviderClient`, `HttpSmsProviderClient`, `Dto/SmsSendResult` |
| Enums (7) | `PersonnelSmsCampaignStatus`, `PersonnelSmsRecipientDeliveryStatus`, `SmsDeliveryAttemptStatus`, `SmsProviderResultClass`, `SmsBillingEntryStatus`, `SmsConsentSnapshotStatus`, `SmsRecipientExclusionReason` |
| Exceptions (3) | `PersonnelSmsStateException`, `SmsBatchLimitException`, `NoEligibleSmsRecipientsException`, `SmsProviderConfigurationException` |
| Jobs (1) | `DeliverSmsRecipientJob` (`TenantAwareJob`) |
| Models (4) | `PersonnelSmsCampaign`, `PersonnelSmsRecipient`, `SmsDeliveryAttempt`, `SmsBillingEntry` |
| Services (4) | `PersonnelSmsCampaignStateMachine`, `PersonnelSmsRecipientStateMachine`, `SmsBillingEntryStateMachine`, `PersonnelSmsBillingEntryFinalizer` |
| Support (7) | `ServedClientSelector`, `SmsRecipientEligibilityEvaluator`, `SmsMessageSegmentCalculator`, `SmsCostCalculator`, `SmsBatchLimiter`, `PhoneNumberDisplayMasker`, `SmsProviderPayloadRedactor`, `ContactExportAttemptDetector` |
| Value objects (4) | `SmsMessageMeasurement`, `SmsEligibleRecipient`, `SmsExcludedRecipient`, `SmsRecipientEvaluation`, `SmsCampaignPreview` |

### HTTP + entitlement substrate

`app/Http/Controllers/Api/V1/Messaging/{PersonnelServedClientController, PersonnelSmsCampaignController}`;
`app/Http/Requests/Messaging/*` (6);
`app/Http/Resources/{ServedClientForSmsResource, SmsCampaignPreviewResource, PersonnelSmsCampaignResource, PersonnelSmsRecipientResource}`;
`app/Policies/PersonnelSmsCampaignPolicy`;
`app/Http/Middleware/EnsureEntitlement`;
`app/Domain/Billing/Services/SubscriptionPlanContextResolver`;
`app/Domain/Billing/Exceptions/EntitlementDeniedException`;
`config/sms.php`; route block in `routes/api.php`; bindings + policy in `AppServiceProvider`;
404-probe hook in `bootstrap/app.php`.

### Frontend

`resources/spa/src/pages/personnel/ClientSms.vue`;
`resources/spa/src/stores/personnelSmsStore.ts` (+ `.spec.ts`);
route `personnel.sms`; navigation rows in `roleNavigation.ts` + `role-navigation.yaml`.

---

## Entitlement substrate proof

**Root cause (C-21S-2).** `grep` proved `PlanContextResolver` was bound to
`UnboundPlanContextResolver`, whose `resolveActivePlan()` returns `null` unconditionally, and that
**no production code consumed the entitlement resolver at all**. Phase 21S is the first phase whose
permission carries `entitlement_key: sms`, so the gap became load-bearing.

`SmsEntitlementAndBillingGateTest` proves each half:

| Assertion | Evidence |
|---|---|
| the concrete resolver is bound | `app(PlanContextResolver::class)` is a `SubscriptionPlanContextResolver` |
| the OLD behaviour would have failed 21S | the same merchant resolves a plan through the new resolver and `null` through `UnboundPlanContextResolver`; re-binding the placeholder makes preview return 403 `no_active_plan` |
| it resolves the active plan + entitlements | plan id matches, `sms` entitlement `enabled` |
| no subscription → fail closed | 403 `no_active_plan` |
| terminal subscription (`cancelled` / `expired`) → fail closed | history is never an entitlement source |
| foreign merchant | each merchant resolves only its own plan |
| entitlement disabled | 403 `entitlement_disabled` on preview, create AND confirm |
| entitlement row absent | 403 `entitlement_absent` |
| upgrade-relevant meta | `error.meta.entitlement = "sms"` |
| **blast radius** | exactly four route names carry `EnsureEntitlement`: `personnel.sms-campaigns.{preview,store,confirm,cancel}` — no read, no other domain |

Entitlement and billing access are independent gates (Plan §9.4): a `read_only_grace` merchant
still HAS the entitlement, and `EnsureBillingMutable` is what stops the send.

---

## Database and migration proof

`Phase21SSchemaTest` asserts against the **live PostgreSQL catalogue**, never the migration source:

- all four tables exist; the forbidden-table scan (contact export, Wallet runtime, R&E reward,
  21R-B, 21N) finds **none**;
- `TenantOwnership` classification: three `BRANCH_OWNED` + `COMPOSITE_CONSISTENCY`,
  `sms_delivery_attempts` `EXEMPT` with a written reason;
- campaigns: 7 CHECKs + the guard trigger + `(id, merchant_id)` unique;
- recipients: `phone_encrypted` is **NULLABLE** (`is_nullable = YES`), 7 CHECKs including the
  jsonb no-phone guard (which names `phone`, `phone_encrypted`, `msisdn`, `phone_number`), both
  triggers, the `(campaign_id, client_id)` dedupe unique and the `(merchant_id, branch_id)` index;
- attempts: 5 CHECKs including `provider_message_redacted !~ '[0-9]{7}'`, the append-only trigger,
  and `(recipient_id, attempt_number)` unique;
- billing entries: 5 CHECKs including `amount_minor = quantity * unit_cost_minor`, the partial
  unique `sms_billing_entries_live_campaign_unique`, and integer-only money columns;
- **every** Phase 21S FK is `RESTRICT` on delete;
- all 8 composite merchant-consistency FKs exist.

`Phase21SEnumParityTest` proves every PHP enum's backing values are **exactly** the values its
column's CHECK admits (campaign 8, recipient 6, consent snapshot 3, attempt status 3, result class
9, billing status 5), and that the SMS consent vocabulary deliberately extends 15A's `ConsentState`
with `missing` because *absence of a consent row is never consent*.

---

## State-machine proof

`SmsIsolationAndStateMachineTest` walks the FULL cross-product of every state pair for all three
machines and asserts the guard's verdict matches the documented inventory, throwing
`PersonnelSmsStateException` (422 `invalid_state_transition`) for every illegal pair. Specs:
[`personnel-sms-campaign.md`](../architecture/state-machines/personnel-sms-campaign.md),
[`personnel-sms-recipient.md`](../architecture/state-machines/personnel-sms-recipient.md),
[`sms-billing-entry.md`](../architecture/state-machines/sms-billing-entry.md).

Database backstops proven independently (each in its own test, because a failed statement aborts the
surrounding transaction): a terminal campaign status change, a recipient DELETE, and a recipient
snapshot rewrite all raise at the database.

No controller or job assigns a status string: every write goes through a named action + machine.

---

## Own-scope served-client proof

`ServedClientSelectorTest` — the Plan §64 sentence, proven clause by clause:

| Property | Result |
|---|---|
| a personally-served client is listed, MASKED (`••• ••• 5678`) | ✅ and the response contains no `+254712345678`, no `712345678`, no `phone_encrypted`, no `phone_index`, no email |
| a client served by ANOTHER personnel member | excluded |
| a `pending` / `in_progress` / `cancelled` session | does **not** make a client "served" (one scenario per status — `service_sessions_active_staff_unique` permits only one active session per staff) |
| an archived client | excluded from the list |
| another branch's client | excluded |
| another merchant's client | excluded |
| a served client with no/withdrawn consent | still LISTED — consent gates sending, not visibility |
| search | matches the NAME only; `0701020304`, `701020304`, `+254701020304` and `0304` all match nothing |
| LIKE metacharacters | `%` and `_` match nothing (escaped) |
| a client-supplied `staff_profile_id` / `staff_profile_ulid` / `staff_id` | never honoured — own scope is always derived |
| a non-personnel role | 403 |
| pagination + sort allowlist | `per_page=1000` → 422; `sort=phone_last_four` → 422 |

---

## Consent proof

Consent is read from the 15A substrate (`client_consents`, channel `sms`) and **fails closed**:

- `opted_in` → eligible;
- `opted_out` → suppressed with `consent_opted_out`, recorded as recipient status `opted_out`;
- **no row at all** → suppressed with `consent_missing` (never treated as consent);
- archived client → `client_archived`, with the OBSERVED consent state recorded truthfully on the
  snapshot (an archived client may still be opted in);
- ownership is checked BEFORE consent, so a Personnel user can never learn the consent state of a
  client they did not serve.

Re-validated at confirm: `SmsPreviewAndConfirmTest` withdraws consent and archives a client between
draft and confirm, and both recipients are suppressed with the campaign re-priced from the
survivors.

---

## Entitlement and billing-status proof

See *Entitlement substrate proof* above, plus:

| Billing status | Served-client READ | Preview / create / confirm |
|---|---|---|
| `trialing` / `active` / `overdue` | allowed | allowed |
| `read_only_grace` | **allowed** (matrix `allow_read`) | 403 `billing_read_only` |
| `suspended_billing` | **allowed** | 403 `billing_read_only` |

Reading an existing campaign, its detail and its recipients also survives read-only grace.

---

## Preview proof

- returns `recipient_count`, `excluded_count`, `excluded_reasons` (**code → count**),
  `message_character_count`, `segment_count`, `requires_unicode`, `estimated_cost`
  (`{amount, currency, formatted}`), `unit_cost_minor`, `max_recipients`, `max_message_characters`
  and the billing notice;
- creates **no** campaign, **no** recipient, **no** billing entry, and sends nothing;
- recomputes segments and cost server-side (161 GSM chars → 2 segments → 200 minor units; one emoji
  → UCS-2);
- enforces the batch cap and the message length server-side;
- rejects **12** server-owned fields with the canonical 422 field envelope, including `phone`,
  `phone_last_four`, `estimated_cost_minor`, `unit_cost_minor`, `staff_profile_id` and `currency`;
- is audited (`personnel.sms.previewed`, info) with counts only — the audit context contains no
  message body, no phone and no client ULID.

**Anti-enumeration:** a foreign client and an absent ULID both report `unknown_client`; a client of
this merchant served by someone else reports `not_served`; neither response names a client.

---

## Confirmation and snapshot proof

- one transaction under a row lock: revalidate → suppress → refuse-if-empty → re-price → snapshot
  consent → stamp `confirmed_at` → create the single provisional charge;
- **queueing runs in `DB::afterCommit`** — a rolled-back confirmation leaves no dispatched job;
- refusing when nothing survives rolls everything back: the campaign is still `draft`, unbilled, and
  its recipient is still `pending`;
- **duplicate confirm sends once** — with the queue faked so the campaign stays in flight, two
  confirmations with **different** idempotency keys produce 1 campaign, 1 recipient, 1 live billing
  entry and exactly **1** `DeliverSmsRecipientJob`;
- a repeated Idempotency-Key replays the stored response;
- a missing Idempotency-Key → 422 `idempotency_key_required` (financial route class);
- `acknowledged` must be literally true;
- confirming an already-settled campaign → 422 `invalid_state_transition`;
- the composition/pricing snapshot is frozen by the trigger once the campaign leaves `draft`.

---

## Delivery/retry/dead-letter proof

`SmsDeliveryTest`:

- an accepted submission → recipient `sent`, attempt 1 `accepted`/`accepted`, campaign `completed`,
  `final_cost_minor` 100, billing entry `billable` (quantity 1, amount 100);
- the destination reaches the provider (asserted through a SHA-256 digest, never plaintext) and the
  correlation reference is **not** the client's identity;
- `invalid_recipient` → `failed`, no retry, no extra dispatch; provider `opted_out` → `opted_out`
  (a consent fact), no retry;
- a transient failure with `max_attempts = 2`: attempt 1 stays `pending` with `next_retry_at` set;
  attempt 2 dead-letters → recipient `failed`, campaign `failed`,
  `personnel.sms.delivery_dead_lettered` at **high** severity, and the submissions are still
  billable;
- a duplicate dispatch is a no-op — one attempt row, one provider submission (the recipient's own
  status is the claim);
- a mixed outcome → `partially_failed`, billing 2 × 1 × 100 = 200;
- a provider-reported opt-out mid-flight → the provisional entry is **cancelled** and replaced by a
  `billable` entry for the corrected quantity, leaving both rows as an auditable trail;
- the attempt log is append-only (UPDATE and DELETE both raise);
- **no receipt route ships** — a structural scan finds no SMS route containing `receipt`,
  `callback`, `webhook`, `delivery-report`, `dlr` or `status-callback`, and no SMS route is
  classified `provider_webhook_mutation`;
- the internal receipt action is idempotent: with receipts enabled, `sent` stays outstanding until
  a receipt resolves it, a duplicate/out-of-order receipt is a no-op, and a receipt never reopens a
  settled campaign.

**Provider binding:** the fake is bound even with a fully configured, enabled integration, because
`testing` short-circuits unconditionally. The HTTP client throws
`SmsProviderConfigurationException` naming the missing key for each of the four credentials, and
every credential's env default is `null`.

---

## Billing entry proof

- `amount_minor = quantity * unit_cost_minor` is a DB CHECK; money columns are integer types; the
  cost path runs through `Money::multiply()`, which detects 64-bit overflow;
- one live entry per campaign is structural (partial unique index) — duplicate confirm, job retry
  and concurrent settlement all fail to double-bill;
- suppressed and opted-out recipients are **never** billed; a permanently failed submission IS
  billed (the provider consumed it);
- a cancelled campaign cancels its live entry and owes nothing;
- a changed settled quantity is a cancel-and-replace, never an in-place edit, because the monetary
  columns are frozen;
- `billing_invoice_line_id` stays null: Phase 21S owns the queue, never the invoicing.
- **No Wallet or payment row is created** — `NoDirectProviderIntegrationTest` stays green and the
  forbidden-table scan finds no Wallet table.

---

## Contact-export prohibition proof

`SmsContactExportProhibitionTest` — the phase-defining invariant:

| Claim | Evidence |
|---|---|
| no export-shaped route in the SMS / served-client surface | route-table scan for `export`, `download`, `print`, `copy`, `csv`, `xlsx`, `pdf`, `vcard`, `contacts` → empty |
| **10** guessed export paths | all 404 **and** each records `personnel.sms.export_attempt_blocked` at HIGH severity with the sanitised path only |
| a mistyped legitimate download | 404 with **no** export-attempt audit (the detector is scoped) |
| no full phone from ANY endpoint | served clients, campaign list, campaign detail, recipients and preview all checked for `+254712345678`, `712345678`, `phone_encrypted`, `phone_index` and `provider_message_id` |
| recipients are masked | `••• ••• 5678`; exactly four digits survive |
| the model never serializes the snapshot | `phone_encrypted` present for delivery, absent from `toArray()` and `json_encode()` |
| no phone in any audit context | every `personnel.sms.%` row checked — no phone, no last-four, no client ULID, no client name |
| no phone in the application log | the whole flow captured through `Log::listen`, asserted unconditionally |
| no phone accepted INTO the API | `phone`, `phone_encrypted`, `phone_last_four` all 422 |
| campaigns bind by ULID only | the internal numeric id 404s |
| **no contact-export permission for any role** | catalogue scan: no key is both contact-ish and export-ish |
| the two SMS keys are Personnel-only | every role checked, defaults **and** grantable overrides |
| the committed OpenAPI carries no contact | no full phone, no `phone_encrypted`, no `phone_index` |

Frontend half (Playwright, `tests/e2e/phase-21s.spec.ts`): no export/download/print/copy control or
`a[download]` anywhere; `navigator.clipboard.writeText` and `window.print` are instrumented and
called **0** times across the whole flow; no full phone in the DOM text, the HTML, the URL,
`localStorage` or `sessionStorage`; and no request body ever carries `staff_profile`,
`estimated_cost`, `recipient_count`, `unit_cost` or a phone.

---

## Provider redaction proof

Three layers, each tested:

1. `SmsProviderPayloadRedactor` strips labelled `api_key` / `to` / `from` / `text` / `email` values
   and free-standing emails and long hex runs;
2. `stripDigitRuns()` removes **any** run of 7+ digits however punctuated
   (`+254 712 345 678`, `254-712-345-678`, `(0712) 345678`, `00441234567890`, `1234567`), while
   leaving short numbers (`503`, `2 retries`) readable;
3. the `sms_delivery_attempts_redaction_check` DB CHECK rejects the row outright — proven by
   bypassing the redactor entirely and watching the insert fail.

Redaction runs **before** truncation: a secret placed 400 characters into a body still disappears
when the column bound is 64.

---

## Route/API/OpenAPI proof

Eight routes, all `/api/v1/personnel/me/...`, all own-scope:

| Method | Path | Class | Gates |
|---|---|---|---|
| GET | `served-clients/sms` | — (read) | `personnel.my_served_clients.view` |
| POST | `sms-campaigns/preview` | `branch_mutation` | permission + `EnsureEntitlement:sms` + `EnsureBillingMutable` |
| POST | `sms-campaigns` | `branch_mutation` | same |
| POST | `sms-campaigns/{campaign}/confirm` | **`financial_mutation`** | same + `EnsureIdempotentRequest` |
| POST | `sms-campaigns/{campaign}/cancel` | **`financial_mutation`** | same + `EnsureIdempotentRequest` |
| GET | `sms-campaigns` | — (read) | `personnel.my_sms.send` |
| GET | `sms-campaigns/{campaign}` | — (read) | `personnel.my_sms.send` |
| GET | `sms-campaigns/{campaign}/recipients` | — (read) | `personnel.my_sms.send` |

`RouteSecurityContractTest`, `FinancialRouteIdempotencyCoverageTest`, `AuditMutationCoverageTest`
and `AuditSeverityCoverageTest` all pass with the new routes registered.

OpenAPI: **235 → 242 paths, 280 → 288 operations** (exactly the eight new operations);
`npm run api:contract:check` → OK 242/288.

---

## Frontend/responsive/dark/accessibility proof

`tests/e2e/phase-21s.spec.ts` — **21 tests, all passing**, axe serious/critical **0** at
360 / 768 / 1280 in **both** light and dark, plus a 200%-zoom viewport, all with no horizontal
overflow. Also proven: keyboard selection (Space), keyboard preview and send (Enter), Escape closes
the confirmation dialog and **focus returns to the control that opened it**, and the `role="status"`
`aria-live="polite"` region announces the preview figures and the send outcome.

`personnelSmsStore.spec.ts` (Vitest) proves the store holds only `phone_masked`, sends only
`client_ulids` + `message_body` (then `acknowledged`), invalidates the preview on any selection or
message change, renders entitlement/billing refusals as actionable copy rather than raw codes,
writes **nothing** to `localStorage`/`sessionStorage`, and exposes no `export`/`download`/`print`/
`copy` action.

---

## Security and log-redaction proof

- Plan §24.5: no phone, no message body and no credential reaches a log line or an audit context —
  asserted over the whole flow, not sampled.
- Plan §73 (personnel contact extraction): the guessed-export probe is recorded at HIGH severity
  while the response stays an ordinary 404, so the probe learns nothing.
- `NoDirectProviderIntegrationTest` remains green: the SMS adapter introduces no
  Safaricom/Daraja/STK/C2B symbol.
- `gitleaks detect --no-git --redact` → no leaks; every SMS provider credential is env-only with a
  `null` default.

---

## Tenant/branch/personnel isolation proof

| Attempt | Result |
|---|---|
| read another MERCHANT's campaign / recipients | **404** (never 403 — existence never leaks) |
| read, confirm or cancel another PERSONNEL member's campaign in the same merchant | **403** (policy denies on the staff-profile check) |
| list campaigns | only the acting staff profile's own |
| message another merchant's client by exact ULID | 422 `no_eligible_recipients`, no campaign created |
| message a client this member never served | 422 `no_eligible_recipients` |
| every non-personnel role (Front Office, HR, Finance, Branch Manager, Audit, Merchant Admin) | 403 on all three surfaces |

Structural backstops: composite FKs on branch, campaign, client, session and staff profile, plus the
`BelongsToMerchant` + `BelongsToBranch` global scopes.

---

## Permission parity proof

`Phase21SPermissionActivationTest` + the existing matrix suites:

- the two keys are `active` in the YAML, present in the PHP registry, projected to the DB, and
  carry no `owning_phase`;
- **130 active / 38 planned**, catalogue size unchanged at 168 — phases activate, they never invent;
- granted to Personnel only, by default **and** by grantable override, across every role;
- read ≠ send: `allow_read` + no entitlement + `info` vs `block` + `sms` + `warn`; both `own` scope,
  both `non_overridable`, neither MFA nor step-up;
- no contact-export key exists and nothing was retired;
- the still-blocked families stay planned (`platform.billing_reconciliation.resolve`,
  `platform.integrations.health.view`, `platform.integrations.refer_earn.manage`).

`docs/proof/phase8-matrix.txt` and `resources/spa/src/types/generated/permissions.ts` are
regenerated deterministically.

---

## Traceability updates

| File | Change |
|---|---|
| `docs/traceability/servana-requirements.csv` | `SRV-REFERRAL-001` → `verified_complete` with PR #44 evidence; new `SRV-SMS-001` row for Phase 21S |
| `docs/remediation/register.yaml` | `REM-SMS-001` `not_started` → `in_progress` with real repository evidence; **`REM-SMS-002` opened** (deferred live-provider verification, closes before Phase 25) |
| `docs/PROGRESS.md` | 21R-A → `verified_complete`; 21N and 21S split into separate rows; new Phase 21S section |
| `docs/CHANGELOG.md` | Phase 21S entry + the 21R-A closure block |

## Test commands and results

All commands run in a **new IDE session** from the dirty implementation checkpoint (no in-memory
monitor carried over — every result below is from a fresh command against the final working tree).
Backend runs in the `app` container against the PostgreSQL 16.14 service (never SQLite, guardrail
§13).

| Gate | Command | Result |
|---|---|---|
| Targeted 21S backend | `php artisan test tests/Feature/Messaging/* tests/Unit/SmsSupportTest.php tests/Feature/Auth/Phase21SPermissionActivationTest.php` | after F2 fix: green (`SmsContactExportProhibitionTest` 24/295) |
| **Full backend serial** | `php artisan test` | **2006 passed / 7 skipped / 0 failed / 12414 assertions / 1357.80s** |
| **Full backend parallel** | `php artisan test --parallel` | **4 processes — 2006 passed / 7 skipped / 0 failed / 12414 assertions / 702.22s** |
| Vitest | `npm run test` | **501 passed / 501 (97 files)** |
| Playwright 21S | `npx playwright test tests/e2e/phase-21s.spec.ts` | **21 passed** |
| Playwright full | `npx playwright test` | **453 passed / 0 failed** (see F3: one prior load-flake, rerun clean) |

### Failures found and fixed in this closure session

| ID | Classification | Root cause | Fix | Rerun |
|---|---|---|---|---|
| **F1** | implementation defect (style) | `tests/Feature/Messaging/SmsDeliveryTest.php` and `tests/Unit/SmsSupportTest.php` were written with CRLF line endings plus minor `unary_operator_spaces` / `single_blank_line_at_eof`. | `vendor/bin/pint` on the two files only. | `pint --test` → 1611 files clean. |
| **F2** | contact-export prohibition / OpenAPI drift | The two SMS composition Form Requests were refactored from a **denylist** (which published `phone_encrypted` etc. as `prohibited` request fields) to an **allowlist** (`rules()` exposes only `client_ulids`/`message_body`/`acknowledged`), but `docs/api/openapi.json` was **not regenerated**, so the committed contract still carried the stale denylist properties — including `phone_encrypted`. Caught by `SmsContactExportProhibitionTest` ("no full phone in the committed OpenAPI"). | Regenerated the contract with the official generators (`servana:openapi` → `api:types` → `servana:permission-types`); **no generated file hand-edited**. `phone_encrypted` and `phone_index` disappear; path/op counts unchanged at **242 / 288**; byte-stable across two passes. | `SmsContactExportProhibitionTest` 24/24; full serial + parallel green. |
| **F3** | environment/load flake | In the first full Playwright run, `appointments.spec.ts › Front Office › lists masked appointments` (Phase 16A — untouched by 21S) failed on an element-visibility timeout under full-suite browser + webServer contention. | None — no code changed. Reran the spec in isolation (**13/13**), then reran the full suite (**453/0**). | Both reruns green; failure recorded, not hidden. |

## Quality gates

| Gate | Command | Result |
|---|---|---|
| composer manifest | `composer validate --strict` | `./composer.json is valid` |
| Pint | `vendor/bin/pint --test` | **1611 files, 0 issues** (after F1) |
| Larastan | `composer stan` (level 8) | **No errors** (1257 files) |
| OpenAPI path count | `scripts/check-api-contract.mjs` | **242 paths** |
| OpenAPI operation count | `scripts/check-api-contract.mjs` | **288 operations** |
| Generator determinism | two full `openapi → api:types → permission-types` passes | `openapi.json`, `api.ts`, `permissions.ts` all **byte-identical (SHA-256) across both passes** |
| `permission-types --check` | `php artisan servana:permission-types --check` | up to date |
| `api:contract:check` | `npm run api:contract:check` | OK — 242 paths, 288 operations |
| ESLint | `npm run lint` | **0 errors / 138 warnings** (= the `origin/main` baseline; no new warnings, none in 21S files) |
| vue-tsc | `npm run typecheck` | clean |
| Vitest | `npm run test` | 501 / 501 |
| Production build | `npm run build` | built OK (56.45s) |
| npm audit | `npm audit --audit-level=high` | **found 0 vulnerabilities** |
| composer audit | `composer audit --locked` | No advisories |
| gitleaks | `gitleaks detect --source . --no-git --redact` | **no leaks found** (22.53 MB scanned) |
| Docker dev app | `docker compose build app` | built |
| Docker prod app | `docker compose -f docker-compose.prod.yml build app` | built |
| Docker prod nginx | `docker compose -f docker-compose.prod.yml build nginx` | built |

## Disposable PostgreSQL proof

A unique disposable database was created from zero and migrated, **never** the dev database.

```
disposable db          = servana_p21s_proof_20260723083808   (CREATE DATABASE, exit 0)
migrate --force        = 118 migrations applied from zero (all 4 Phase 21S migrations DONE)
pg_version             = PostgreSQL 16.14 on x86_64-pc-linux-musl
migration_count        = 118
public table count     = 97
Phase 21S tables       = personnel_sms_campaigns, personnel_sms_recipients,
                         sms_billing_entries, sms_delivery_attempts   (4/4 present)
phone_encrypted null   = YES  (personnel_sms_recipients.phone_encrypted is NULLABLE — ADR-010)
triggers (5 distinct)  = personnel_sms_campaigns_guard_trigger,
                         personnel_sms_recipients_guard_trigger,
                         personnel_sms_recipients_no_delete_trigger,
                         sms_billing_entries_guard_trigger,
                         sms_delivery_attempts_append_only_trigger (UPDATE+DELETE)
CHECK constraints       = campaigns 9, recipients 8, delivery_attempts 5, billing_entries 5
                         (pg_constraint contype='c'; the SchemaTest asserts the specific business
                          CHECKs and passed)
partial unique          = sms_billing_entries_live_campaign_unique present (1)
merchant/branch index   = personnel_sms_recipients merchant_id-leading index present (1)
dedupe unique           = personnel_sms_recipients UNIQUE (campaign_id, client_id) present (1)
FORBIDDEN TABLES        = (none)  — scan of subscription_payment*/wallet_webhook_inbox/
                         merchant_wallet_accounts/re_qualification_*/reward_ledgers/referrer_*/
                         referral_*/reward_rules/notification_subscriptions/
                         scheduled_report_deliveries/search_indexes → 0 hits
DROP DATABASE           = ok
leftover proof DBs      = 0
dev DB after            = 97 tables, 4/4 Phase 21S tables intact (untouched)
```

The one defect the checkpoint recorded here — `personnel_sms_recipients` missing its
`merchant_id`-leading index, caught by `TenantColumnCoverageTest` — is confirmed fixed (the index
is present above), and the gitleaks fixture defect (a `sk_live_…`-shaped string tripping the Stripe
rule, replaced with a non-secret-shaped fixture) is confirmed by the clean `gitleaks` run above.

## Scope-purity audit

- **Changed-path classification:** all 120 working-tree entries fall in authorised Phase 21S
  categories (SMS domain, minimal entitlement runtime, migrations, HTTP layer, `config/sms.php`,
  factories, frontend, docs, tests, and the narrow regression updates — permission-parity tests,
  `TenantOwnership`, `AuditEvent`, generated contracts). No forbidden path (no Wallet/Daraja/R&E
  reward/notification/search/deploy runtime, no `vendor`/`node_modules`/`.env`/reports/dumps;
  only `.env.example` is touched).
- **Forbidden runtime grep** over the Phase 21S source (`app/Domain/Messaging/**`, the Messaging
  controllers/requests, the SMS resources/policy, `EnsureEntitlement`,
  `SubscriptionPlanContextResolver`, `config/sms.php`, `routes/api.php`) for
  `Daraja|STK|PayBill|Till|C2B|wallet webhook|subscription_payment|payment provider|reward ledger|
  referrer payout|qualification engine|notification center|scheduled report` → **no hits**.
- **Contact-export controls in the frontend** (`ClientSms.vue`, `personnelSmsStore.ts`, the
  personnel routes) for `clipboard|window.print|export|download|csv|xlsx|vcard` → the only matches
  are the JavaScript `export` keyword and two comments (one noting the pre-existing Phase 20H
  earnings `download` permission, one asserting *"No contact export exists"*). No SMS
  export/download/print/copy control.
- **`.env.example`:** every SMS credential key (`SMS_API_KEY`, `SMS_BASE_URL`, `SMS_SENDER_ID`,
  `SMS_CONTRACT_VERSION`) is empty; `SMS_ENABLED=false`, `SMS_PROVIDER=fake`. No secret default.
- Forbidden-table scan on the disposable database: **none** (above).

## Final branch state

Single completion commit `phase-21s: implement personnel bulk sms` on
`phase-21s-personnel-bulk-sms`, on top of base
`b5a8733616a4603996e18695db31528299cdf8d7`; `origin/main…HEAD = 0 1`; local HEAD ==
`origin/phase-21s-personnel-bulk-sms` after push; working tree clean. **No PR opened, no merge, no
branch deletion** — that is the product-owner's next action.

## Deferred work and owner phases

| Deferred | Owner | Why |
|---|---|---|
| Live SMS provider verification: credentials, pinned result-code map, pinned per-segment tariff, authenticated delivery-receipt contract | **REM-SMS-002**, closes before Phase 25 | The Plan pins no SMS provider. Delivery is fixture-verified against `FakeSmsProviderClient`; **no receipt endpoint ships**, because Plan §24.1 forbids an unverifiable provider webhook and an unauthenticated one would let anyone mark another merchant's messages delivered. |
| Scheduled retention purge of aged delivery snapshots (Plan §74 "retained per policy then purged") | **21N** (§67 scheduler) | Phase 21S ships the minimization half (a suppressed recipient's number is never stored; a stored snapshot is encrypted, hidden and read once). `personnel_sms_recipients_no_delete` means the purge must be an explicit, audited job, never an ad-hoc DELETE. |
| Rolling a `billable` SMS charge into a subscription invoice line (`billable → invoiced`, setting `billing_invoice_line_id`) | the billing phase that owns SMS charge aggregation | Phase 21S owns the liability QUEUE and its correctness; the nullable FK to `subscription_invoice_items` is the waiting seam. |
| Wallet payment runtime; Integrations Health shared screen | **20D-W** | External Gate W is CLOSED. |
| `subscription.*` / `activity.*` R&E events; qualification engine; inbound reconciliation; R&E gap reconciliation | **21R-B** | Requires 20D-W payment sources. |
| Notifications, queue topology / Horizon, scheduled reports | **21N** | Requires 20D-W (Plan §80.1). |
| Search (incl. indexing the served-client surface) | **22** | — |
| Release-wide security / responsive / dark / a11y / threat-model audit | **23** | Phase 21S proves its own screen only. |
| Performance optimization | **24** | — |
| Deployment / production readiness; `REM-RE-002` closure | **25** | — |
| Referrer accounts, referral codes as system of record, campaigns, reward rules, reward calculation, reward ledger, referrer payouts, reward statements | **not Servana** — Citrus Refer & Earn (ADR-013) | — |
| Payment provider credentials; STK / C2B / Daraja; raw provider callbacks | **not Servana** — Wallet by Citrus (ADR-012) | — |

## Remaining risks

1. **The SMS provider contract is modelled, not verified (REM-SMS-002).** The result-code map, the
   transient-vs-permanent classification, the per-segment tariff and the receipt scheme are derived
   from the Plan rather than from a contracted provider. A mismatch surfaces in production as
   mis-classified retries, a wrong billed amount, or an unusable receipt channel — not at build
   time. Mitigations already in place: the classification is a stored column (so the policy is
   auditable), an unrecognised response maps to `unexpected`, which is retriable-with-cap and
   dead-letters visibly rather than silently dropping a message; and the tariff is configuration,
   so re-pricing is a config change.
2. **Campaigns settle on `sent`, not `delivered`, while `sms.delivery.receipts_enabled` is false.**
   That is the honest reading of what Servana knows without a receipt channel, and `delivered` is
   never claimed without evidence — but a merchant reading "Completed" is being told *the provider
   accepted every message*, not *every message arrived*. The screen copy says "Completed"; a future
   phase with receipts on will distinguish the two.
3. **The unit price is a placeholder.** `sms.pricing.unit_cost_minor` defaults to 100 minor units
   per segment per recipient. It is enforced consistently and cannot be tampered with by a client,
   but it is not a contracted rate.
4. **No load evidence for a maximum-size batch.** The batch cap defaults to 200 recipients and the
   eligibility path is deliberately query-batched (no N+1), but a 200-recipient confirm has not been
   profiled against Plan §72 p95 targets. Performance verification is Phase 24.
5. **The retention purge does not exist yet** (see the deferral table). Delivery snapshots therefore
   accumulate until 21N ships the scheduled job.

## Exact next action

**Product-owner authorization is required to open the Phase 21S pull request.** The branch
`phase-21s-personnel-bulk-sms` is pushed with a single completion commit; nothing else in this phase
is outstanding. On authorization: open the PR against `main`, observe all five required checks
(Backend, Frontend, Docker, Security, E2E — Playwright), post the governance evidence comment after
CI is green, merge, delete the remote branch, and reconcile Phase 21S to `verified_complete` on the
next phase's branch.

Phase 21R-B, 21N, 22, 23, 24, 25 and 20D-W remain **blocked** and were not started. External Gate W
is still CLOSED.
