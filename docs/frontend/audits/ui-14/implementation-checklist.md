# UI-14 Personnel implementation checklist

Branch `phase-ui-14-personnel-experience`, base `14cfadcb512d867d2aebefe0fa628fdee3563e02` (UI-13 PR #63
squash merge, live-verified). Work began in a session on 2026-08-20 and was completed by a
continuation session on 2026-10-08 that verified the inherited tree before building on it.
Release actions (stage, commit, push, PR, CI, merge) are delegated to Codex Prompt 2.

## Current Codex recovery acceptance — 2026-10-10

Current Codex release checkpoint (2026-10-10): REM-DEP-003 PR #64 is verified merged as `43b623c0e484c0ead76cca8c9d02ad41694c700e`, exact-head CI `37863919256` all five jobs success. The complete PostgreSQL result is **3,578 passed / five exact authorized skips / zero failed / 52,419 assertions**; six collector negative controls reject. Accepted Vitest 1,426, Personnel browser 73, Tailwind compatibility 17 and whole-product Playwright 1,653 remain attributable to the frozen source; 69 captures and 535 predecessor hashes are preserved. The identical Tailwind key removal has ordered-config and **652-file byte parity**, including the actual new Nginx image. Current PHP and Nginx production builds pass; PostgreSQL 16.14 connects, all 132 migrations pass, `nginx -t` passes and **43/43 staff.servana.ke checks** pass. Exact owned proof resources were removed; development services and volumes were untouched. Final documentation/security/contract/scope checks precede the first implementation commit. UI14 PR CI/governance/merge are still pending; UI15/UI16/UI17/Phase25 and Gate-W-dependent phases were not started.

- [x] Current post-REM backend XML and six collector negative controls accepted; no full-suite replay.
- [x] Final 652-file config/build/image parity; current PHP/Nginx and PostgreSQL host acceptance.
- [x] Current security/generated/scope/Git gates; focused changed-document 111/8,756 acceptance.
- [ ] First implementation commit; clean REM ancestry; push; one PR; exact-head five-job CI; truthful governance; ordinary merge; synchronized main.

## Authority and readiness

- [x] UI-13 PR #63 live-verified once: merged 2026-08-20T13:36:11Z; head `126756b3…`; squash
      `14cfadcb…`; reviewed tree == merge tree `9d91b415…`; CI `32371896795` five jobs success;
      governance comment `5356633232`; 0 reviews / 0 approvals / blank decision; branch absent.
- [x] UI-13 lifecycle reconciled exactly once on this branch (PROGRESS, CHANGELOG, proof, checklist,
      traceability, defect projection) without rewriting implementation evidence.
- [x] Authorities read: Development Plan, UI/UX plan UI-14 + cross-cutting sections, canonical
      navigation map, Brand Identity, CLAUDE.md, permission matrix, merged code.
- [x] Six design references inspected and hashed (`design-inspiration-notes.md`); hashes re-verified
      unchanged on 2026-10-08.
- [x] 20-page readiness matrix written; target 19 implemented / 1 disabled_by_gate / 0 planned.
- [x] Gate W, 20D-W, 21R-B, 21N verified closed/blocked; no later-phase branch exists.

## Implementation

- [x] Canonical host-relative Personnel tree (`/dashboard` … `/account`) with `/personnel/*`
      same-account compatibility redirects; Notifications has no route.
- [x] Seven own-scope read seams (`/personnel/me/workspace`, `service-history`,
      `preferred-requests`, `served-clients`, `commissions`, `salary`, `availability`); no new
      permission, policy namespace, migration, mutation or financial rule. OpenAPI 299/350 → 306/357.
- [x] HR availability controller read path extracted into `PersonnelAvailabilityReadModel`
      (behaviour-preserving; HR/Branch suites green).
- [x] Served-client view audited (`personnel.served_clients.viewed`, scope own, export false).
- [x] Exact SMS billing notice wording from the server preview resource.
- [x] Shared primitives `SvTonalPageHeader`, `SvStatTile`, `SvLeadRecord`, `SvMaskedIdentity`;
      `utils/personnelStatus.ts` (unknown status → neutral).
- [x] All 19 live pages recomposed to the UI-13/reference bar (UI14-VIS-001…006).
- [x] Earnings overview shows only server figures (UI14-FIN-001); composer sent-status handoff
      (UI14-SMS-001); Terms plain-language sentence (UI14-COPY-001); query prefill from records.
- [x] `rounded-pill` token mapping (UI14-DS-001); dead `sm:`/`xl:` utilities removed from Personnel
      pages (UI14-CSS-001).
- [x] Fixture identity aligned with the signed-in user (UI14-FIX-001).

## Verification and release

- [x] Focused backend: UI-14 API 24/118; owning own-scope suites (see proof).
- [x] Pint, Larastan L8, composer validate.
- [x] vue-tsc, ESLint (0 errors; 0 warnings in UI-14 files), Vitest, production build.
- [x] Focused UI-14 Playwright, responsive 7 widths, 200%, axe light/dark, theme, motion, focus.
- [x] Full PostgreSQL backend suite (see proof for exact result).
- [x] Whole-product Playwright and precise historical-evidence restoration (see proof).
- [x] Production image pair + isolated `staff.servana.ke` host proof; proof resources removed.
- [x] `npm run ui14:check`, `nav:check`, `api:contract:check`, `git diff --check`, `git fsck --full`.
- [x] **Dependency audits** — UI14-DEP-001/002 closed locally by verified merged REM-DEP-003 PR #64 and reconciled zero-advisory audits; original failing reports retained. Fresh current-image locked Composer audit/strict validation pass; final npm audit is recorded in the release supplement.
- [ ] Commit / push / PR / exact-head CI / governance / merge — Codex Prompt 2 only.
- [ ] `verified_complete` — reconciled on the UI-15 branch after a normal merge.
