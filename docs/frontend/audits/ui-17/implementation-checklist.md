# Phase UI-17 — Performance, security, production deployment and closeout — implementation checklist and resume register

Branch `phase-ui-17-performance-security-production-closeout`, base
`1e8bd5f744a65329ced22c0275b728929aae5245` (UI-16 PR #67 normal merge, live-verified 2026-10-13).
Claude Code implementation session 1 of 2: **no staging, commit, push, PR, CI dispatch or merge** —
Codex Prompt 2 owns the GitHub lifecycle. No production mutation of any kind.

## Resume pointer — read this first

| Source | Pointer |
|---|---|
| UI/UX plan | Phase UI-17 (line ~2568): budgets, bundle analysis, images, DNS, TLS, CSP, CORS, cookies, canary, host smoke, rollback, monitoring, proof, docs. Acceptance: 8 hosts healthy, required CI green, production screenshots, rollback proven, no open critical, owner package. Cross-cutting §4.6–§4.7, §6.5, §18, §22, §23, §24, §26 |
| Development Plan | §71 observability, §72 SLOs, §76 CI/CD, §77 infra, §78 DR, Phase 25 (line ~2712) — **not UI-17's** |
| Contract | 160 = 132 implemented + 28 disabled_by_gate + 0 + 0 (unchanged); OpenAPI 307/358; permissions 169/134/35 |
| References | six design files re-hashed 2026-10-13 = UI-16 record → UI-16 inspection reused (`ui-16/design-inspiration-notes.md`) |
| Images | N-1 = `servana-ui17-{nginx,php}:n-1-1e8bd5f` (built from `git archive 1e8bd5f`); candidate = `servana-ui17-{nginx,php}:cand-<fingerprint12>` built from an export of `git ls-files -co --exclude-standard` |
| Traps | `page.request` in Playwright bypasses Chromium's host-resolver map (use in-page `fetch`); nginx `gzip_min_length` leaves <1 KiB chunks raw by design; Pinia wraps actions so promise identity is not observable; Vite inlines <4 KiB assets (fonts) as `data:` unless told not to; `Remove-Item` with a comma list trips the tool guard — one absolute `-LiteralPath` per call; Resolve-DnsName shows a referral as a timeout — use the raw probe |

## Increments

| # | Increment | State | Evidence |
|---|---|---|---|
| A | Branch safety, PR #67 live verification, UI-16 reconciliation (PROGRESS, CHANGELOG, proof, defect register, traceability constants + CSV) | done | `phase-entry-and-deferred-register.json` → `predecessor` |
| B | Baseline before any change: build, bundle analysis, N-1 image pair, edge header/CORS/log probes, Web Vitals (8 hosts × 2 profiles × 5+1), layout-shift attribution, DNS/TLS | done | `*-before.json`, `dns-tls-matrix.json` |
| C | Performance fixes by root cause: edge gzip (PERF-001), metric fallbacks + hero font preload (PERF-002), shell modulepreload (PERF-003), single `/me` (PERF-004) | done | `defect-closure.json` |
| D | Edge/browser security: CSP (hash), Permissions-Policy, staged HSTS, credential-free access log (SEC-001), Referrer-Policy defer (SEC-002), strict CORS (SEC-003), Secure cookies (SEC-004), fail-closed prod image (SEC-005), no `data:` fonts (SEC-007) | done | `security-host-matrix.json`, `browser-security-negatives.json` |
| E | Gates: bundle budget + reviewed caps, edge contract, 17 negative controls, Pest (shell critical path, edge security), origin browser spec, CI wiring | done | `test-matrix.json` |
| F | UI14-RES-001 legacy `Earnings.vue` removal with retargeted regression | done | `defect-closure.json` |
| G | Candidate images, after measurements, origin gate, isolated rehearsal (canary / skew control / promote / rollback / log check), screenshots | see `test-matrix.json` | `staging-rehearsal.json`, `web-vitals-after.json`, `screenshots/local-origin/` |
| H | Monitoring readiness, runbook, receipt template, owner package | done | the four documents |
| I | Definitive acceptance (backend full suite, Vitest, Playwright full, audits, gitleaks), PROGRESS, proof, handoff manifest | see `test-matrix.json` | `claude-to-codex-handoff.{md,json}` |

## Codex code-release recovery checkpoint (2026-10-13)

The product owner explicitly authorized GitHub code publication independently of live production
readiness. **github_code_release_status: pending_commit_PR_CI**;
**production_deployment_status: blocked_external**; **full_phase_acceptance: not_verified_complete**.
This is a truthful pre-merge checkpoint; final CI, governance, merge and cleanup facts will be stored
in the PR comment and final report, without a direct-main evidence commit.

Codex independently revalidated the original 98-path handoff, manifest SHA-256
`abd468e41105365517986fe6d8ea63841be4e5f79170ae562396eda79e7467d3`, clean index and
base/head/main `1e8bd5f744a65329ced22c0275b728929aae5245`. All 122 active UI-16 visual baseline
bytes and six design-reference hashes match their recorded indices. No visual owner approval is
claimed. The original handoff remains unchanged historical Claude evidence; Codex-owned deltas are
recorded in `codex-release-addendum.json`.

Production preflight observed on 2026-10-13: 0/8 hosts resolve, both public resolvers return SERVFAIL,
all four delegated A2 nameservers return REFUSED; GitHub has 0 environments, 0 deployments and
0 Actions secrets, and CI builds without publishing/deploying. These block production, **not this
code release**. DNS/TLS belong to the DNS owner; runtime/registry/secrets/monitoring/rollback slots
belong to separately authorized backend Phase 25 and repository owners. Runbook P1–P10 remains
mandatory before any production traffic; this recovery authorizes no go-live.

Desktop/Fast-4G lab LCP meets 2.5 s; slow-4G 2.99–3.17 s does not. UI17-PERF-005 remains
owner_decision_pending (D8), never passed by changing the metric. D1–D8 and manual screen-reader
review remain owner decisions; logo, HSTS preload and required E2E changes are not inferred.
Gate W stays closed; REM-EXP-001 (21N), REM-PERM-002 (authorized remediation), UI07-ENV-001
(pre-25 timing owner), REM-SMS-002/REM-RE-002 (provider verification) keep their recorded owners.

Resume: use this checkpoint and the addendum; commit the authenticated allowlist, fingerprint only
the verified generated token-source digest at its actual implementation commit, open one PR to main,
require all five exact-head CI jobs and truthful governance, merge normally where protection permits,
then synchronize clean main. Preserve production blockers and do not mark UI-17 verified_complete.
