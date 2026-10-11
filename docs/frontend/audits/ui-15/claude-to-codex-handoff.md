# UI-15 Claude Code → Codex handoff

**Phase:** UI-15 — Audit experience · **Branch:** `phase-ui-15-audit-experience` ·
**Base / HEAD:** `20c27dcac7d0663d93a566d8a3ff42aa566171d7` (UI-14 PR #65 merge) · **Commits added:** 0 ·
**Staged:** nothing · **Push / PR / CI / merge:** not performed.

## What Codex must do before staging

1. Confirm the branch and `HEAD == base`, `origin/main` unchanged (or prove any newer `main` work is a
   compatible predecessor and integrate it normally).
2. Run `node <manifest verifier>` equivalent: re-hash every `files[].sha256` in
   `claude-to-codex-handoff.json`; refuse any unexpected path, missing path or byte drift. The
   manifest lists itself with `sha256: null` (no recursive hash). There is no cryptographic signature.
3. Re-run the staged secret scan (gitleaks on the index) — Claude Code scanned the same paths
   without staging (0 leaks).
4. Commit as `ui-15: implement audit experience`, open the PR, run exact-head CI (all five jobs),
   post truthful governance (no independent approval), normal squash merge, delete the branch, then
   reconcile UI-15 to `verified_complete` on the UI-16 branch — not on `main`.

## Result summary

- 15 pages: **14 implemented / 1 disabled_by_gate / 0 planned / 0 removed**; global planned = 0.
- OpenAPI 306/357 → 307/358 (`GET /api/v1/audit-integrity`); permissions 169/134/35 unchanged; no migration.
- Full PostgreSQL 3,025 / 14 skipped / 0 failed; Vitest 1,449/1,449; focused Playwright 50/50;
  whole-product 1,721/1,721; host proof 48/48; npm and Composer audits 0; gitleaks 0.
- If Codex re-runs whole-product Playwright, restore the 13 predecessor scopes exactly against
  `historical-evidence-baseline.json` (the run rewrites ~247 files and adds ~85 UI-01 captures
  stamped with the HEAD short SHA). Never use a broad reset.

## Known non-blocking items routed elsewhere

UI15-SEC-OBS-001 (REM-PERM-002 / product owner), UI15-INT-RES-001 (Phase 25 §71 / product owner),
unsupported sub-capabilities listed in `page-readiness-matrix.json`, UI-16 visual convergence list in
`visual-language-continuity.md`.

`release_authorized_for_codex_after_verification` is `true` only because every mandatory local gate
above passed; it authorizes verification-then-release, not an unverified commit.
