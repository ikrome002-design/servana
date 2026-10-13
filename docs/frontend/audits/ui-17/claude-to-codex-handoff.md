# UI-17 — Claude → Codex handoff (Prompt 2: release only)

**State:** `local_complete`, uncommitted, unstaged, unpushed on
`phase-ui-17-performance-security-production-closeout`. HEAD = base = `1e8bd5f744a65329ced22c0275b728929aae5245`
(UI-16 PR #67 normal merge, verified live 2026-10-13). No commit, PR, CI dispatch, merge or production
change was made by Claude.

## 1. Verify the manifest first

`claude-to-codex-handoff.json` lists every authorized path with git status, size and SHA-256. The
manifest's own entry carries `sha256: null` (self-reference). Method used to build it: NUL-safe
`git status --porcelain=v1 -z --untracked-files=all`, every path hashed from disk, then independently
re-hashed and compared (path set, status, size, content). Re-run that comparison; any difference means
the tree moved after handoff — stop and ask.

Expected git state: 0 staged, 0 commits ahead of `origin/main`, branch current.

## 2. What Codex must still run (CI_VERIFICATION_PENDING)

The five exact-head jobs. New in this PR, first hosted execution:

| Job | New step | Local evidence |
|---|---|---|
| Frontend | `Contract — UI-17 edge security policy` | exit 0 |
| Frontend | `Budget — UI-17 bundle analysis and compressed budgets` (+ artifact) | exit 0 |
| Frontend | `Budget — UI-17 negative controls` | 18/18 |
| Docker | throwaway `postgres:16-alpine` + `php artisan migrate` in `Start the built images` | rehearsal + matrix topologies |
| Docker | `Smoke — UI-17 edge security matrix on the built image` | 300/300 |
| Docker | `Security — the edge access log holds no credential (UI-17)` | 0 token lines (N-1: 8) |
| Docker | UI-17 origin spec (runs inside the existing origin gate) | 40/40 |

Local results for everything else: `test-matrix.json` (backend 3,074/14/0; Vitest 1,465; Playwright
2,107/0/0; Pint, Larastan, audits clean).

## 3. Scanner exception (narrow, precedented)

gitleaks flags exactly one line: `resources/spa/src/design-system/tokens.generated.ts:13`
(`generic-api-key`) — it is `DESIGN_TOKEN_SOURCE_SHA256`, the SHA-256 of `tokens.json`, which changed
because UI-17 added the metric-compatible font fallbacks to the font tokens. Verify with
`Get-FileHash resources/spa/src/design-system/tokens.json -Algorithm SHA256` (expected
`7a8d99f9f7404f6dc773a5eaf954dc9ee83def5caed7842d33c5f055c6f86c5b`). Add only the per-commit
fingerprint `<implementation-commit>:resources/spa/src/design-system/tokens.generated.ts:generic-api-key:13`
to `.gitleaksignore`, exactly as for UI-04 (`d7c64c3…`, `e6afe832…`) and UI-16 (`45d518f5…`). No rule,
path or entropy relaxation. A squash merge mints a new commit → a new fingerprint.

## 4. Publish strategy

Stage exactly the manifest paths (including the two deletions), commit
(`ui-17: performance, browser security, release rehearsal and closeout`), push the branch, open the PR to
`main` citing UI/UX plan §25 UI-17 and the defect IDs, add the fingerprint commit, run the five jobs,
record governance truthfully (0 reviews unless one happens), merge only with all five green on the
final head.

## 5. What is NOT done and must not be claimed

| Item | Why | Owner / entry condition |
|---|---|---|
| Live DNS/TLS, production deploy, canary, rollback, screenshots, alert delivery | `servana.ke` lame delegation (0/8 resolve); no pipeline/environments/registry/secrets/alerting | DNS owner + Phase 25; runbook §2 P1–P10 |
| Slow-4G landing LCP ≤ 2.5 s | UI17-PERF-005 — needs the owner's agreed-profile decision (D8) or an architecture change | product owner |
| UI-16 baselines/redesign acceptance, screen-reader pass, logo derivative, HSTS preload, E2E as required check | owner decisions D1, D3, D6, D7 | product owner |
| Client RUM telemetry | would add an API route (contract frozen) or credentials | Phase 25 + authorization |
| Gate-W pages, REM-EXP-001, REM-PERM-002, UI07-ENV-001, REM-SMS-002, REM-RE-002 | other owners | unchanged |

`release_authorized_for_codex_after_verification` in the JSON means **the PR may be released after the
five jobs pass**. It does **not** authorize any production deployment.

## 6. Local artifacts Codex may reuse (not committed)

Docker images `servana-ui17-{nginx,php}:n-1-1e8bd5f` (previous release, from `git archive 1e8bd5f`) and
`:cand-226d50f1d73d` (final candidate). `:cand-a3c45d2fb773` is superseded (it had the `data:` font
defect) and `:cand-81d296d08c45` was the measurement build. Rehearsal:
`node scripts/ui17-release-rehearsal.mjs --previous n-1-1e8bd5f --candidate cand-226d50f1d73d`.
