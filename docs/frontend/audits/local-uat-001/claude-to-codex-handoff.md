# LOCAL-UAT-001 — Claude → Codex handoff

| Field | Value |
|---|---|
| Phase | `LOCAL-UAT-001` (local browser readiness; not UI-18, not Phase 25) |
| Branch | `phase-local-browser-uat-001` (uncommitted; Claude made **no** stage, commit, push, PR, CI, merge or branch operation after creating it) |
| Base HEAD | `c7e963c88cced493364705e9e25ab2206862e0c7` (PR #69 merge) — HEAD unchanged |
| Index | empty |
| Manifest | `docs/frontend/audits/local-uat-001/claude-to-codex-handoff.json` |
| Manifest SHA-256 | `a4ad05181bbfaf43ee20c004e568b222ad323f243e15f1e9d43dcc40076f59ae` |
| Self-reference rule | The manifest lists itself and this file with `sha256: null`. This file records the manifest's digest; Claude's final report records this file's digest. |
| Proof · runbook · register | `docs/proof/local-uat-001.md` · `docs/runbooks/local-browser-uat.md` · `docs/frontend/audits/local-uat-001/status-register.json` |

## What Codex should verify before any release action

1. `git rev-parse HEAD` = base above; branch name as above; `git diff --cached --name-only` empty.
2. Parse `git status --porcelain=v1 -z --untracked-files=all` NUL-safely; the path set must equal the manifest's
   `files[].path` exactly (no extra, no missing).
3. Every manifest entry with a non-null `sha256` must match the file's SHA-256 and byte length.
4. Re-run Codex's own gates on the exact head it commits (the hosted five-job CI). Expected local evidence is in
   the proof §8; a whole-tree gitleaks scan reports the known `tokens.generated.ts` digest false positive (file
   unchanged, already fingerprinted in CI).

## Scope of the change (why each group exists)

- **LUAT-D01** (owner decision "Narrow FO endpoint"): `FrontOfficeSelectionController`, two FormRequests, two
  resources, two routes in `routes/api.php`; `services/frontOfficeSelections.ts` + spec; five Front Office
  screens; regenerated `docs/api/openapi.json` and `resources/spa/src/types/generated/api.ts`;
  `FrontOfficeSelectionApiTest`. **No permission key; `service.view` unchanged.**
- **LUAT-D02**: `Requests/Concerns/NormalizesQueryBooleans` + five list requests; `QueryStringBooleanFilterTest`.
- **LUAT-D03**: `tests/bootstrap.php`; `tests/Unit/TestDatabaseIsolationTest.php`.
- **LUAT-D04**: `types/roles.ts`; `roleEntryRoutes.spec.ts`, `Verify.spec.ts`, `tests/e2e/auth-magic-link.spec.ts`.
- **LUAT-D05**: `utils/uuid.ts` + spec; 16 stores (import + call only).
- **Mocked e2e** updated only for the approved contracts: `tests/e2e/appointments.spec.ts`, `queue.spec.ts`,
  `support/releaseAudit.ts`.
- **Local tooling and evidence**: `scripts/dev/*` (hosts manager + self-test, lifecycle wrapper),
  `database/seeders/LocalUat/LocalUatDatasetSeeder.php`, `playwright.local-uat.config.ts`, `tests/e2e-local-uat/*`,
  runbook, proof, status register, PROGRESS (LOCAL-UAT-001 section + PR #69 merge reconciliation), CHANGELOG.

## Excluded on purpose (must not be committed)

`.env`, `storage/app/local-uat/*` (fixture manifest and automation TOTP secrets — git-ignored),
`test-results/*` (Playwright output, session states — git-ignored), `public/spa/*` (built bundle — git-ignored),
the Windows hosts file and its backup, Mailpit messages, database volumes, scratch scripts.

## Release status (these are different things)

| State | Value |
|---|---|
| `release_authorized_for_codex_after_verification` | `true` — **code PR eligibility only**, after Codex's own exact-head verification |
| `github_code_release_candidate` | yes (permanent, tested repository changes exist) |
| `agent_local_uat_complete` | `true` |
| `manual_product_owner_uat` | **pending** |
| `product_owner_visual_approval` | **pending** (also the 82 COR pages, UI-16 baselines, UI-17 D1–D8, NVDA/VoiceOver) |
| `production_deployment` | **not authorized** / `blocked_external` (DNS/TLS, Phase 25) |

Open items carried forward (not blockers for a code PR): LUAT-F01, LUAT-F03, LUAT-F04 (owner decisions); Gate W
(`20D-W`, `21R-B`, `21N`) and the 28 gated contracts; REM-EXP-001, REM-PERM-002, UI07-ENV-001, REM-SMS-002,
REM-RE-002 under their existing owners.
