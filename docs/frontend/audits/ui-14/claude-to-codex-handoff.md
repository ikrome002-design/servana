# UI-14 — Claude Code → Codex handoff

**Status: `blocked` for release — not `ready_for_codex_release`.** UI-14 implementation, evidence and
every local quality gate are green **except** the two dependency-audit gates (`npm audit
--audit-level=high`, `composer audit`), which fail on advisories published after UI-13's CI on
2026-08-20 against lockfiles UI-14 never touched. CI runs both steps, so the UI-14 PR's Frontend and
Backend jobs would fail until a product-owner-approved remediation lands (UI14-DEP-001/002).

The machine-checkable manifest is `claude-to-codex-handoff.json` (generated last; SHA-256 per path;
`release_authorized_for_codex_after_verification: false`).

## Identity

| Fact | Value |
|---|---|
| Repository | `ikrome002-design/servana` |
| Branch | `phase-ui-14-personnel-experience` |
| HEAD | `14cfadcb512d867d2aebefe0fa628fdee3563e02` (unchanged; = `origin/main`; divergence `0 0`) |
| Commits since baseline | 0 · staged paths 0 · no push · no PR · no GitHub write action |
| UI-13 predecessor | PR #63 merged, tree `9d91b415…` equal to reviewed head `126756b3…`, CI `32371896795` five jobs success, governance `5356633232`, 0 reviews / 0 approvals |

## What was delivered

- **20 pages: 19 implemented / 1 disabled_by_gate (Notifications — Phase 21N / External Gate W) /
  0 planned / 0 removed.** Canonical host-relative routes on `staff.servana.ke`; `/personnel/*`
  same-account redirects.
- 7 read-only own-scope GETs; OpenAPI 299/350 → 306/357; permissions 169/134/35 unchanged; no
  migration; audit event `personnel.served_clients.viewed`.
- Shared primitives `SvTonalPageHeader`, `SvStatTile`, `SvLeadRecord`, `SvMaskedIdentity`; every live
  page 27–30/30 on the visual rubric with light, dark, mobile, tablet and 200 % evidence.
- Security: own-scope (16 domains), contact protection 12/12, SMS safety 16/16, earnings models
  10/10; availability read-only by authority; no contact export anywhere.

## Local verification (final frozen source)

| Gate | Result |
|---|---|
| Pint · Larastan L8 · composer validate | clean · `[OK] No errors` · valid |
| Full PostgreSQL (`php artisan test --parallel`) | 3,009 passed / 14 skipped / 3 projection failures → fixed (UI14-API-001, UI14-REG-001); invalidated set 137 / 8,429; final doc-consumer set 144 / 8,889; screen-spec consumers 75 / 6,818 |
| vue-tsc · ESLint · build | 0 · 0 errors (0 warnings in UI-14 files) · ok |
| Vitest | 1,426 / 1,426 (149 files) |
| Focused UI-14 Playwright | 72 / 72 |
| Whole-product Playwright | 1,634 / 1,634, 0 flaky/skipped (58.0 min) |
| Historical evidence | 12 protected scopes byte-exact after precise restoration |
| Production images + `staff.servana.ke` | PHP `4dc7b5a9…` / Nginx `cb4e4744…`; in-network `nginx -t` ok; host proof 43 / 43; proof resources removed |
| Contracts | `ui14:check`, `ui13:check`, `nav:check`, `nav:negative-controls` 24/24, `api:contract:check`, tokens/content/assets/inventory checks — all exit 0 |
| `git diff --check` · `git fsck --full` | clean · exit 0 |
| **`npm audit --audit-level=high`** | **FAIL — 13 high / 4 moderate (UI14-DEP-001)** |
| **`composer audit`** | **FAIL — laravel/framework <12.69, league/commonmark (high), league/flysystem (UI14-DEP-002)** |

## The blocker and the decision needed

- npm: `npm audit fix` (non-breaking) clears 9 of 17, but the remaining 6 high require **Tailwind CSS
  3 → 4** (a pinned-stack change — CLAUDE.md §9 says ask first) and an exact-pin **sharp 0.35.3 →
  0.35.5** that also re-derives the landing-image derivatives. The trial fix was reverted; lockfiles
  are byte-identical to `main`.
- composer: minor/patch upgrades within Laravel 12 (`laravel/framework` ≥ 12.69, `league/commonmark`
  ≥ 2.10.2, `league/flysystem` ≥ 3.35.3).
- Recommended path (REM-DEP-002 precedent): a dedicated **REM-DEP-003** remediation branch from `main`,
  product-owner-authorized, merged first; then sync this branch with `main` (that will invalidate and
  require re-running the frontend/backend gates and image proof against the new dependencies) before
  Codex commits and opens the UI-14 PR.

## Codex-only release steps (pending, not started)

Verify this manifest against the tree → (after the blocker clears and the branch is synced) stage
exactly the listed paths → commit → push → open the UI-14 PR → exact-head five-job CI → governance
comment → merge → branch cleanup → reconcile UI-14 to `verified_complete` on the UI-15 branch.

## Residuals and owners

Notifications/preferences — Phase 21N (Gate W). Personnel self-status availability, server terms
acknowledgement, query reply/reopen — future authorized phases. Legacy unrouted `Earnings.vue`
(UI14-RES-001) — UI-17. UI-08…UI-12 visual remediation, primitive registry registration, `sm:`/`xl:`
sweep (45 files), `rounded-pill` cross-account confirmation — UI-16. UI-15, UI-17, Phase 25 not
started. REM-EXP-001, REM-PERM-002, UI07-ENV-001, REM-SMS-002 unchanged. Full table:
`docs/PROGRESS.md` § Phase UI-14 and `docs/proof/ui-14.md`.
