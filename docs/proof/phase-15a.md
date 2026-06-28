# Phase 15A — Services, Catalogue, Clients — Proof

**Branch:** `phase-15a-services-catalogue-clients` · **Base commit:** `d098f37`
(Phase 11 merge, PR #23). **Status:** `in_progress` — **data/security/tenancy
foundation built and verified against PostgreSQL 16**; the API authorization
surface, the canonical permission activation, and the frontend screens are
**not yet built** (itemized under *Pending* below). This phase is **not**
`local_complete`, `ci_passed`, `merged`, or `verified_complete`.

Frontend visibility is UX only; the API remains the security boundary. Money is
integer minor units via the `Money` value object. Tests run against the
PostgreSQL service container (never SQLite).

---

## Manifesto evidence — foundation slice

### Prove the problem
Plan §13.7 / §35 / §39 / §80 (Phase 15A) require five branch-owned tables
(`service_categories`, `services`, `service_personnel_eligibility`, `clients`,
`client_consents`) with tenant + branch isolation, integer-minor-unit money,
encrypted + masked client contact, and a plaintext-free phone search/duplicate
mechanism. The repository had **empty `Catalogue/` and `Clients/` domains**
(`.gitkeep` only) — no tables, models, or contact protection existed.

### Root cause / approach
Built the schema, tenancy ownership, and the novel contact-protection mechanism
first (spec-first per Plan §13.2), mirroring the established conventions
(composite-FK tenant consistency `(branch_id, merchant_id) → merchant_branches`,
partial-unique indexes, CHECK constraints via raw DDL, `encrypted` cast +
`$hidden`, ULID route keys).

### Fix with precision — what was built (this slice)
- **Data dictionary (spec-first, predates migrations):**
  `docs/architecture/data-dictionary/services-clients-scheduling.md` — full
  §13.2 coverage for all five tables incl. the HMAC blind-index design.
- **Migrations (5)** `database/migrations/2026_06_28_0000{01..05}_*`:
  - `service_categories` — branch-owned; partial-unique `(branch_id, name) WHERE
    archived_at IS NULL`; `UNIQUE (id, merchant_id)`; composite consistency FK.
  - `services` — branch-owned; `price_minor` (≥0 CHECK), `currency` char(3) KES
    default, `duration_minutes` (>0 CHECK), `status IN ('active','archived')`;
    **legacy** `preferred_personnel_fee_minor` (nullable, non-editable seam);
    index `(branch_id, status)`; composite FKs to branch + category (same tenant).
  - `service_personnel_eligibility` — branch-owned junction; `UNIQUE
    (service_id, staff_profile_id)`; composite FKs to branch, service, staff.
  - `clients` — branch-owned; `phone_encrypted`, `phone_index char(64)`,
    `phone_last_four`, `email_encrypted`; **partial-unique `(branch_id,
    phone_index) WHERE status='active'`** (same-branch duplicate prevention with
    **no plaintext phone index**); `status IN ('active','archived')`.
  - `client_consents` — branch-owned; `UNIQUE (client_id, channel)`;
    `channel IN ('sms')`, `state IN ('opted_in','opted_out')`.
- **Enums (5):** `ServiceStatus`, `ClientStatus`, `ConsentChannel`, `ConsentState`.
- **Models (5):** `ServiceCategory`, `Service`, `ServicePersonnelEligibility`,
  `Client`, `ClientConsent` — all `BelongsToMerchant` + `BelongsToBranch`, ULID
  route keys, `scopeActive`, money via `Money`, client contact `encrypted` +
  `$hidden`, masking accessors.
- **Contact protection (Plan §35; guardrail §6.4):**
  `App\Domain\Clients\Support\PhoneNumberNormalizer` (Kenyan-first E.164
  canonicalization) + `App\Domain\Clients\Support\ClientContactIndex` (keyed
  HMAC-SHA256 blind index; env `CLIENT_CONTACT_INDEX_KEY`, base64 32 bytes;
  non-prod APP_KEY-derived fallback; production-required). `config/servana.php`
  `clients.contact_index_key` + `.env.example` placeholder (documented, no real
  secret).
- **Registry wiring:** `TenantOwnership` BRANCH_OWNED + COMPOSITE_CONSISTENCY +
  MODELS updated for all five tables.
- **Factories (5):** scope-consistent (derive `merchant_id` from the branch;
  related parents share branch/merchant); `ClientFactory` mirrors the
  encrypt+index+last_four invariant.

### Demonstrate resolution — commands + results (PostgreSQL 16, in `app` container)

```
php artisan migrate                → 5 migrations DONE
php artisan migrate:fresh          → full schema rebuild OK (5 new tables DONE)
php artisan test  TenantColumnCoverageTest ModelTenancyTraitCoverageTest
                                   → 9 passed (110 assertions)
php artisan test  tests/Unit/ClientContactProtectionTest.php
                                   → 7 passed (17 assertions)
php artisan test  AuditEventCoverageTest ResourceCapabilityMapTest
                  FinancialRouteIdempotencyCoverageTest RouteBindingTenantSafetyTest
                                   → 14 passed (61 assertions)  (no regression)
composer pint -- --test            → PASS, 447 files
composer stan  (Larastan level 8)  → No errors (299 files)
```

**Tenant/branch isolation (verified):** `TenantColumnCoverageTest` confirms all
five tables are classified, carry non-null `merchant_id` + `branch_id`, a
`merchant_id`-leading index, and a composite consistency FK to
`merchant_branches(id, merchant_id)` — so a row's merchant can never disagree
with its branch.

**Contact protection (verified):** equivalent phone forms (`0712345678`,
`+254712345678`, `254712345678`, `712345678`) normalize to one canonical
`+254712345678`; the blind index is a deterministic one-way HMAC hex that matches
across forms and leaks no plaintext; the DB stores genuine ciphertext (not
plaintext); `phone_index`/`phone_encrypted`/`email_encrypted` never serialize;
masking yields `••• ••• 5678`; a same-branch active duplicate is rejected by the
partial-unique index; the same phone is allowed in another branch.

---

## Controlling decisions recorded

1. **Permission keys (material conflict).** The Phase 15A keys (`service.view/
   create/update/archive`, `personnel.eligibility.manage`, `client.view/create/
   update`, `front_office.search`) match the **canonical Plan §19.2/§19.3
   catalogue**, but the live system still runs the §10.3 **baseline** registry
   (`services.manage`, `clients.create/edit/view`, `eligibility.manage`) and
   `docs/auth/permission-matrix.yaml` does **not exist**. Per §19.1 (baseline
   reconciled to canonical *in owning phases*), Phase 15A will activate its
   canonical keys for their §19.3 default roles (Branch Manager `service.*`; HR
   `personnel.eligibility.manage`; Front Office `client.*` + `front_office.search`)
   in the PHP registry + DB seed + TS metadata. The full canonical YAML +
   schema/parity/per-key infrastructure **remains a Phase 19 / REM-PERM-001
   deliverable** — not claimed here. *(This activation is part of the Pending
   API-authorization slice below; the keys are not yet wired.)*
2. **Eligibility ownership.** §80's wording associating an eligibility screen with
   Branch Manager is overridden by §19.3: **HR** owns `personnel.eligibility.manage`;
   Branch Manager gets a read-only eligibility summary only.
3. **No platform fee control.** `services.preferred_personnel_fee_minor` is kept
   as an internal, non-editable legacy seam (not in `$fillable`, no API field);
   `preferred_personnel_fee_rules` is Phase 20A and is not created here.

---

## Pending (not built this session — precise owners within Phase 15A)

The following remain to complete Phase 15A and are **explicitly not done**:

- **API authorization layer:** domain actions (create/update/archive service +
  category; assign/revoke eligibility; create/update client; change consent),
  Policies (4), Form Requests, thin controllers, masked API Resources, `/api/v1`
  routes classified `branch_mutation`/read, `RouteClassification` registration,
  billing-status gate, idempotency where applicable, typed audit events.
- **Permission activation:** reconcile the nine canonical keys in
  `PermissionRegistry` + `PermissionSeeder` + generated TS, and update the
  affected §10.3 spec tests to canonical names without weakening assertions.
- **Client search:** branch/tenant-scoped name + normalized-phone search (masked
  only; named rate limiter; no cross-tenant existence leak).
- **Frontend (Phase 11 shell):** Branch Manager catalogue (list/create/edit/
  archive + read-only eligibility summary); HR eligibility; Front Office client
  create/search/detail/edit + masked contact + SMS consent; navigation flips
  (`planned → live`) + get-started deep links; §27.1 screen specs (spec-first)
  + inventory regen.
- **Contracts/gates (CI-authoritative for Linux/Docker/browser):** OpenAPI gen×2
  + TS parity; ESLint/vue-tsc/Vitest; SPA build; Playwright 15A flows;
  responsive/dark/axe; composer/npm audit; gitleaks; Docker image builds.
- **Backend feature tests:** role-boundary (BM owns catalogue; FO/Admin/HR cannot
  mutate; HR cannot mutate pricing), cross-tenant 404, cross-branch 403, billing
  read-only allow-read/block-mutate, audit emission, eligibility same-branch +
  duplicate-active, consent persistence, client search isolation, no
  Personnel contact-export route.

## Residual risk
The schema/security substrate is verified, but **Phase 15A is not usable yet**
(no routes/UI). The permission reconciliation must land together with the
consuming routes/policies so the §10.3→§19 transition is coherent and fully
tested before any completion claim. CI remains authoritative for the Linux
browser/Docker gates.
