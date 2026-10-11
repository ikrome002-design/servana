# Phase UI-15 — Audit experience — implementation checklist and resume register

Branch `phase-ui-15-audit-experience`, base `20c27dcac7d0663d93a566d8a3ff42aa566171d7` (UI-14 PR #65
normal squash, live-verified 2026-10-11). Claude Code implementation session 1 of 2: **no staging,
commit, push, PR or merge** — Codex Prompt 2 owns the GitHub lifecycle.

## Resume pointer

Read this file first on resume. Sources are read once per unchanged hash; do not repeat discovery.

| Source | Pointer |
|---|---|
| UI/UX plan | Phase UI-15 §25 (line ~2539); Appendix A §12 Audit (line ~9152–9773); §18.4 |
| Development Plan | §70 audit chain + verifier (line ~2352); ADR-008; §71 alert transport → Phase 25 |
| Canonical contract | `docs/frontend/navigation/servana-user-account-navigation-map.yaml` `merchant_audit.*` (line ~5448) |
| Reference images | `C:\Users\nderu\Documents\Development\Product\Template\UI-UX Design Inspiration` — six hashes equal UI-14's record; all six viewed 2026-10-11 |

## Baseline facts (frozen 2026-10-11)

- Contract baseline: 7 implemented (get-started, audit, audit-event-detail, audit-flags,
  audit-finance, audit-compensation, exports), 7 planned (dashboard, audit-operations,
  audit-access, audit-privacy-preferred, exports-history, audit-integrity, account), 1
  disabled_by_gate (notifications, `external_gate_w`).
- Runtime baseline: `/audit/*` prefixed Phase 19 routes (`/audit/events`, `/audit/flagged`,
  `/audit/exports`, …) and a non-contract `/audit` role landing.
- Audit role holds 10 active keys: `audit.branch_events.view`, `audit.finance.view`,
  `audit.compensation.view`, `audit.export`, `audit.flagged_event.{create,update_status,resolve_metadata}`,
  `platform_fee.view`, `receipt.view`, `reports.view`.
- Flag lifecycle (backend truth): open → under_review (assigns the actor) → resolved/dismissed
  (review note required) → reopened → under_review. No assign/reassign, escalate or category
  endpoint exists; no export cancel/retry endpoint exists (revoke exists).
- Integrity: `audit:verify-chain` runs daily and persists **no** result; the failure signal goes
  to logs/event (Phase 25 §71 owns transport). `AuditLogResource` deliberately omits hash columns.
- Container vendor was stale (Laravel 12.62.0); refreshed in-container to the merged lock
  (12.69.3) with `composer install` per the named-volume procedure.

## Decisions

- **UI15-INT-001** Integrity Status: implement a bounded, read-only, on-demand *segment*
  verification of the caller's merchant chain using the existing `AuditChainHasher` (authoritative
  stored hashes, no persistence, no repair). Status vocabulary `verified` (whole chain covered and
  consistent) / `partial` (latest segment consistent, earlier history not covered) / `broken` /
  `empty`. Scheduled-run results stay unexposed (no persisted store) and are labelled as such.
- **UI15-API-001** Review views: server-side `view` filter (`operational`, `access`,
  `privacy_preferred`) on `GET /audit-logs` mapped by a backend enum; plus `flagged` and
  `correlation_id` filters and an eager-loaded review-status summary. No new permission key.

## Progress

- [x] Branch safety gate; PR #65 live verification; branch created.
- [x] UI-14 lifecycle reconciled once (PROGRESS, CHANGELOG, proof, checklist, 15 JSON artifacts,
      traceability rows + `Phase23TraceabilityTest` in-flight → UI-15); `ui14:check` green.
- [x] Backend seams: `AuditReviewView` enum; `view`/`flagged`/`correlation_id` filters; review summary
      on `AuditLogResource` (merchant eager load only); `GET /audit-integrity` (segment verifier +
      branch-safe resource). `Ui15AuditExperienceApiTest` 13/13 (198 assertions) on PostgreSQL;
      Pint + Larastan L8 clean on touched files. OpenAPI 306/357 → 307/358; TS regenerated.
- [x] Route tree (`routes/audit.ts`, host-relative, ULID-constrained params, 8 legacy redirects);
      ROLE_ENTRY landing → `audit.dashboard`; Get Started → Appendix A §12.3 verbatim.
- [x] 14 pages + supporting flag/export records; shared `components/audit/*`; `utils/auditPresentation`;
      `composables/useAuditRouteFilters`; `stores/auditWorkspaceStore`.
- [x] Navigation contract: 14 implemented / 1 gated (`phase_21n_blocked_by_external_gate_w`) / 0
      planned; `nav:generate`, `nav:check`, `nav:negative-controls` 24/24 (UI15-NAV-001 fixed:
      control assumed a planned page exists). role-navigation fixture regenerated.
- [x] vue-tsc 0; ESLint 0 on touched files; new Vitest specs 23/23; affected suites green.
- [x] Readiness/gate/route/security/flag/export/integrity matrices written.
- [x] E2E reconciliation of Phase 19 paths (audit.spec, releaseAudit, roleBootstrap, role-entry, ui03 proof script).
- [x] Full PostgreSQL 3,025/14/0 (after one traceability wording fix); Pint, Larastan L8, Composer clean.
- [x] Vitest 1,449/1,449; focused UI-15 Playwright 50/50; whole-product 1,721/1,721; evidence restored (13/13 MATCH).
- [x] Visual acceptance 14/14 (min 28/30); browser/responsive/a11y/theme/restoration records.
- [x] Production image pair + `audit.servana.ke` host proof 48/48; owned resources removed.
- [x] PROGRESS final section; npm audit 0, gitleaks 0, diff --check and fsck clean.
- [x] Final document-test rerun (Docs 96, Traceability 15) and handoff manifest written + verified.

Lifecycle: `local_complete` — uncommitted handoff; PR/CI/merge pending (Codex Prompt 2).

UI15 defects so far: UI15-NAV-001 (negative control + two Vitest vacuity guards assumed ≥1 planned
page; UI-15 removed the last one) — fixed with non-vacuous fixtures. UI15-CSS-001 (`text-sv-text-primary`
is not a generated utility; replaced with `text-sv-text` in all UI-15 files; no other use exists in
the codebase). UI15-SEC-OBS-001 was initially deferred in the Claude baseline; the product owner subsequently
authorized conjunctive export-domain authorization in this Codex session. Targeted closure verification
is BLOCKED by Docker/WSL; the original handoff remains immutable. Broader REM-PERM-002 remains separately pending.

## Codex security closeout — product-owner authorization (2026-10-11)

The same-session continuation authorizes UI15-SEC-OBS-001 only: live `audit.export` plus every
selected domain read key at creation, queued generation and both signed file access paths.
Original 228-path Claude handoff matched before amendments; its hashes remain historical.
Pre-fix PostgreSQL cases reproduced Finance and Compensation request acceptance (201 instead of
403). Initial fixture run failed before authorization because the permission catalogue was not
seeded; corrected without changing an assertion. Post-fix verification is blocked by Docker/WSL unresponsiveness (`Wsl/Service/0x8007274c`);
no passing result is claimed. Release lifecycle remains pending. `codex-security-closeout.json` will record exact amendment hashes and accepted results.
No UI/route/resource schema, permission key, migration, financial calculation or source-audit write
capability changed. UI-16 owns cross-account convergence including the mandatory UI-08–UI-12 visual
revision after UI-15 merges; UI-17, Phase 25 and Gate-W-dependent work remain unstarted.

**Codex stop (2026-10-11):** `STOP — UI-15 NOT RELEASED`. Docker exec commands stopped
responding during affected PostgreSQL and Larastan runs; direct `wsl -d docker-desktop` diagnostics
failed with `Wsl/Service/0x8007274c`. The repair remains unverified. No source was staged,
committed, pushed or merged. Restore Docker/WSL, determine whether the owned test processes
remain running, then rerun the affected security and quality gates against the dedicated
`servana_ui15_codex_test` database. Current production PHP image verification remains pending
because PHP authorization source changed; unchanged frontend visual evidence is retained.

**Codex resume checkpoint (2026-10-11):** The preceding stop is historical. Bounded Docker server probes and normal Desktop restart/start timed out. Owner-authorized CI-first validation now permits a feature commit and non-draft PR; local post-fix PostgreSQL/full static checks remain unverified. A named PostgreSQL Audit/private-file CI step retains JUnit evidence alongside the full suite. Merge remains blocked until security proof and all five exact-head jobs pass. The prior 48 host checks remain valid only for unchanged shell/routing/assets; current authorization requires independent backend CI. No UI-16 work started.
