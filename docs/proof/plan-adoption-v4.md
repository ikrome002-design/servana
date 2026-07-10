# Servana v4 Plan Adoption — Proof (Plan §1.3)

Architecture-only adoption between Phase 19 and Phase 20A. **No Phase 20 runtime code**
(migrations, routes, Wallet/R&E clients, jobs, or screens) was added.

## Phase 19 merge evidence (PR #32)

| Field | Value |
|---|---|
| PR | #32 — Phase 19: Complete audit logging and flagged events |
| State | MERGED |
| mergedAt | `2026-07-05T11:48:45Z` |
| Merge commit | `7ef259e28f51fc9bba24a16ef3945ff61ddef4ce` |
| Head branch | `phase-19-audit-flagged-events` (deleted local + remote) |
| Base branch | `main` |
| reviewDecision | `` (blank — solo-maintainer governance exception; **not** independent approval) |
| CI run | `28736716360` — Backend, Frontend, Docker, Security, E2E — Playwright: all **SUCCESS** |

## Adoption branch

| Field | Value |
|---|---|
| Branch | `docs/update-servana-development-plan` |
| Branch original head (pre-completion) | `4afdab3de2cc7d09500c65b877c9f0c995f27586` |
| `origin/main` head at adoption start | `ca27eb6a39e09fc9cb0048329626bf23ed66bc89` |
| Rebase required | **No** — Phase 19 merge `7ef259e` is ancestor of branch head |
| Local safety branch | Not created (rebase not required) |

## v4 plan file integrity

| Field | SHA-256 |
|---|---|
| Imported v4 plan (committed + post-corrections working tree) | `AED08DE964B50CD65B59E5BCC25A932570EC528FB91F4D785E2C6DCA365E3018` |
| Pre-rebase hash | Same (no rebase) |
| Post-rebase hash | Same (no rebase) |
| Final adopted plan hash | `AED08DE964B50CD65B59E5BCC25A932570EC528FB91F4D785E2C6DCA365E3018` |

Canonical filename: **`Servana Software Development Plan.md`** (retired name `SERVANA_DEVELOPMENT_PLAN.md`).

## Source documents

### Present in repository

- `CLAUDE.md`
- `Servana Software Development Plan.md` (v4 standalone)
- `Servana Project Scope.md`
- `AGENTS.md`

### Missing (recorded; deferred contract pins)

| Document | Mapping / deferral |
|---|---|
| `Wallet_by_Citrus_Platform_Project_Scope.md` | Cited in Plan §2; **External Gate W** (§80.2) before 20D-W runtime |
| `Refer_and_Earn_Project_Scope.md` | Cited in Plan §2; **Phase 21R-A entry** before R&E runtime |
| `Citrus_Refer_and_Earn_Production_Software_Development_Plan.md` | Same as R&E scope |
| `SERVANA_DEVELOPMENT_PLAN_CORRECTIONS.md` | Content folded into v4 plan (§1.1) |
| `SERVANA COMBINED.txt` | Cited as product authority in Plan §2; not in repo |

## ADR files created

| ADR | Path | Status |
|---|---|---|
| ADR-012 | `docs/architecture/adr/0012-wallet-by-citrus-payment-orchestration-boundary.md` | Created |
| ADR-013 | `docs/architecture/adr/0013-citrus-refer-and-earn-integration-authority.md` | Created |
| ADR-014 | `docs/architecture/adr/0014-structured-payment-reference-and-invoice-registration.md` | Created |
| ADR-015 | `docs/architecture/adr/0015-cross-platform-machine-identity-and-webhook-signing.md` | Created |

ADR-006 remains historical inline in Plan §8 only; superseded by ADR-012/015 for current architecture.

## Data dictionary

| Action | Path |
|---|---|
| Created | `docs/architecture/data-dictionary/billing-and-wallet.md` |
| Created | `docs/architecture/data-dictionary/refer-earn-integration.md` |
| Renamed | `billing-and-mpesa.md` — **never existed** in repo; no git rename required |

## Permission matrix (SUP-06)

### Renamed (planned keys)

- `platform.mpesa_configuration.manage` → `platform.wallet_configuration.manage`
- `platform.mpesa_exception.view` → `platform.billing_reconciliation.view`
- `platform.mpesa_exception.resolve` → `platform.billing_reconciliation.resolve`

### Added (planned keys)

- `platform.integrations.wallet.manage`
- `platform.integrations.refer_earn.manage`
- `platform.integrations.health.view`
- `platform.referral.qualification.view`
- `platform.referral.qualification.correct`

### Parity totals

| Projection | Count |
|---|---|
| Canonical §19.2 keys | **156** |
| Active (YAML == PHP == DB == TS) | **87** |
| Planned (isolated from runtime) | **86** |
| Legacy active rows | **17** |
| Total YAML rows | **173** |

Navigation/inventory updated: `roleNavigation.ts`, `role-navigation.yaml`, `inventory.json`, `inventory.yaml`.

## NoDirectProviderIntegrationTest

File: `tests/Feature/Security/NoDirectProviderIntegrationTest.php`

Coverage:

- No `*/mpesa/*` callback routes
- No `services.mpesa` in `config/services.php`
- No Daraja/Safaricom composer packages
- Executable scan: forbidden provider symbols (with `mpesa_offline` and negated-Daraja comment allowlists)
- Regression fixture proves detection of forbidden `mpesa_consumer_key`

Initial failure: comment in `PaymentMethodReferenceValidator.php` contained `Daraja` in "No Daraja/STK" — fixed via allowlist for negated references.

## Plan internal contradictions corrected (§14)

| Correction | Applied |
|---|---|
| §14.1 Canonical filename | `Servana Software Development Plan.md` throughout normative text |
| §14.2 Pre-feature gate | Historical Phase V finding preserved; current gate **closed-satisfied** |
| §14.3 REM-MPESA-001 | Normative → `REM-WALLET-001`; historical REM-MPESA marked superseded in register |
| §14.4 Phase 17 / 20A | Phase 17 legacy seam documented; no false dependency on 20A |
| §14.5 Phase 20B registration | No outbox intent; nullable columns only; 20D-W registration sequence |
| §14.6 submission_unknown | Documented in plan + billing-and-wallet.md |
| §14.7 Webhook verification | Canonical verified inbox; no unverified event-ID squatting |
| §14.8 Resource version ordering | Gate W pin documented |
| §14.9 C2B attempt model | Direct C2B without fabricated user attempt |
| §14.10 Partial payment uniqueness | Aggregate + receipt child model |
| §14.11 Signing agility | Algorithm-aware ADR-015; no hardcoded Wallet HMAC |

## Lifecycle records updated

- `docs/PROGRESS.md` — Phase 19 verified; v4 adoption row; 20A blocked until adoption merges
- `docs/CHANGELOG.md` — Phase 19 merge + v4 adoption entry
- `docs/proof/phase-19.md` — PR #32 merge reconciliation
- `docs/remediation/register.yaml` — REM-WALLET-001, REM-RE-001, REM-MPESA superseded, REM-AUDEXP verified_complete
- `docs/traceability/servana-requirements.csv` — SRV-WAL-001, SRV-RE-001 (`architecture_adopted`)

## Tests run (local, Docker PG16, 2026-07-07)

| Suite | Result |
|---|---|
| Targeted auth + NoDirectProvider | **198 passed** / 1713 assertions |
| MigrationManifest + PermissionMatrixParity | **5 passed** / 110 assertions |
| Backend serial (`php artisan test`) | **1068 passed**, 7 skipped / 5896 assertions (583s) |
| Backend parallel (`php artisan test --parallel`) | **1068 passed**, 7 skipped / 5896 assertions (491s) |
| Pint (`composer pint --test`) | **954 files PASS** |
| Larastan L8 | **No errors** |
| Frontend typecheck (`vue-tsc`) | **PASS** |
| Frontend Vitest (60 files) | **248 passed** (includes screenInventory 8/8) |
| Frontend ESLint | **0 errors** (138 pre-existing warnings) |
| Frontend build (`npm run build`) | **PASS** |
| `composer validate` | **valid** |
| `composer audit` | **No advisories** |
| `npm audit --audit-level=high` | **PASS** (2 moderate in dev deps only) |
| gitleaks (local) | **no leaks found** |
| Docker `servana-app:dev` build | **PASS** |
| Docker `servana-nginx:prod` build | **PASS** |
| `git diff --check` | **PASS** (CRLF warning on traceability CSV only) |

## Explicit exclusions verified

No additions of: `platform_billing_settings` migrations, subscription plan migrations, Wallet clients, webhook routes, STK/PayBill processing, R&E runtime jobs, Phase 20 frontend screens, or provider credentials.

## Deferred contract pins

- **Wallet:** External Gate W — OpenAPI hash, event names, signing algorithm/headers, `resource_version` field name
- **R&E:** Phase 21R-A entry — event schemas, product code, confirm window, campaign sync

## Remaining risks

- Wallet/R&E authoritative scope files absent from repo — runtime work blocked until Gate W / 21R-A pins
- Local Docker port conflict with `citrus-refer-and-earn-*` containers required temporary stop for PG16 tests
