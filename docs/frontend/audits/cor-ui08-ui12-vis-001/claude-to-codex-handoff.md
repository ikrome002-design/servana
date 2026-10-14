# COR-UI08-UI12-VIS-001 — Claude-to-Codex handoff

**State:** `local_complete`, uncommitted. Branch `design/ui08-ui12-reference-grade-redesign`, HEAD =
base `562ffbbbbc10351a7185e60fda517d51a260f9db` (UI-17 PR #68 merge), 0 commits ahead, 0 staged paths.
Claude staged nothing, committed nothing, pushed nothing, opened no PR, ran no hosted CI and merged
nothing.

## Manifest

| Item | Value |
|---|---|
| Machine manifest | `claude-to-codex-handoff.json` (NUL-safe `git status --porcelain=v1 -z --untracked-files=all` inventory: per-path git status, bytes, SHA-256; deletions verified against the base blob) |
| Manifest bytes | 193040 |
| Manifest SHA-256 | `d5b9b99ae7fc9910cf31408ebadbdc046496222c482931f5dc5ae811191fe420` |
| Self-reference | The `.json` lists itself and this `.md` without hashes (this file records the `.json` digest; this file's own digest is in the final Claude report). |
| Release flag | `release_authorized_for_codex_after_verification: true` — code PR + CI only; **not** DNS/TLS, production rollout or product-owner visual acceptance |

## How Codex verifies (before staging)

1. `git branch --show-current` = `design/ui08-ui12-reference-grade-redesign`; `git rev-parse HEAD` =
   the base above; `git diff --cached --name-only` is empty.
2. Recompute the SHA-256 of `claude-to-codex-handoff.json` and compare with the table above.
3. Read `git status --porcelain=v1 -z --untracked-files=all` as bytes; the path set must equal the
   manifest's `files` (no extra, no missing).
4. For every non-deleted row, the on-disk byte length and SHA-256 equal the manifest. For any deleted
   row, the path is absent and `git rev-parse <base>:<path>` equals `base_blob`.
5. Stage exactly those paths (`git add --pathspec-from-file=<NUL list> --pathspec-file-nul`), commit,
   open the PR, run the five exact-head jobs.

## Known release notes

- **gitleaks:** `resources/spa/src/design-system/tokens.generated.ts` line 13 embeds the regenerated
  SHA-256 digest of `tokens.json` — the same verified false-positive class fingerprinted at UI-04 and
  UI-17. Fingerprint the new value; do not weaken a rule.
- **Visual baselines:** 20 new corrective frames and 38 refreshed UI-16 frames per platform (win32 +
  linux, `mcr.microsoft.com/playwright:v1.62.0-noble`). The 23 out-of-scope linux frames are
  byte-identical to `main`. CI's E2E job re-verifies them.
- **Tests changed:** `tests/e2e/ui-16-visual-regression.spec.ts` (negative control repointed to
  `.sv-r-feature`) and `tests/e2e/ui-16-responsive-accessibility-theme.spec.ts` (refined light/dark
  canvas expectations for the five accounts). New: `cor-ui08-ui12-visual-regression.spec.ts`,
  `cor-ui08-ui12-capture.spec.ts` (evidence writer, skipped unless `COR_CAPTURE` is set),
  `support/corPopulated.ts`.

## Evidence

Proof `docs/proof/cor-ui08-ui12-vis-001.md` · gates `test-matrix.json` · scope
`page-inventory-and-status.json` · parity `functional-security-parity.json` · design
`visual-direction.md`, `design-reference-translation.md` · per-page acceptance
`page-redesign-acceptance.json` · frames `visual-baseline-index.json` · owner review
`product-owner-visual-review.md` · defects `defect-closure.json`.

## Not done here (owners)

Product-owner visual acceptance (owner) · UI-17 production DNS/TLS/canary and Phase 25 (DNS owner →
Phase 25) · UI-17 decisions D1–D8 (owner) · Gate-W work (20D-W, 21R-B, 21N) — Gate W closed, not
started.
