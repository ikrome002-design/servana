# Servana — Build Progress

Tracks the Plan §27 roadmap. One phase = one reviewed PR. A phase is not
"Done" until its acceptance criteria are demonstrably met and the owner approves.

| Phase | Title | Status | Branch / PR | Proof |
|---|---|---|---|---|
| 1 | Project initialization | ✅ Complete (awaiting CI + approval) | `phase-1-initialization` | [phase-1.md](proof/phase-1.md) |
| 2 | Docker & environment setup | ⬜ Not started | — | — |
| 3 | Laravel backend foundation | ⬜ Not started | — | — |
| 4 | Frontend foundation | ⬜ Not started | — | — |
| 5 | Authentication (Magic Link + sessions) | ⬜ Not started | — | — |
| 6 | Account & tenant model | ⬜ Not started | — | — |
| 7 | Branches, memberships, invitations | ⬜ Not started | — | — |
| 8 | Roles & permissions | ⬜ Not started | — | — |
| 9 | Tenant-scoped data access hardening | ⬜ Not started | — | — |
| 10 | API foundation | ⬜ Not started | — | — |
| 11 | UI layout foundation | ⬜ Not started | — | — |
| 12 | Responsive design pass | ⬜ Not started | — | — |
| 13 | Dark mode | ⬜ Not started | — | — |
| 14 | Accessibility foundation | ⬜ Not started | — | — |
| 15 | HR, catalogue, clients | ⬜ Not started | — | — |
| 16 | Scheduling, queue, sessions, preferred personnel | ⬜ Not started | — | — |
| 17 | Invoicing | ⬜ Not started | — | — |
| 18 | Payments, receipts, refunds, disputes, cash-up, period locks | ⬜ Not started | — | — |
| 19 | Audit logging completion | ⬜ Not started | — | — |
| 20 | Citrus Billing Engine & commissions | ⬜ Not started | — | — |
| 21 | Queues, notifications, scheduled reports | ⬜ Not started | — | — |
| 22 | Search | ⬜ Not started | — | — |
| 23 | Security hardening & threat-model verification | ⬜ Not started | — | — |
| 24 | Performance optimization | ⬜ Not started | — | — |
| 25 | Deployment pipeline & final production readiness | ⬜ Not started | — | — |

## Phase 1 — completed work

- Laravel 11.54 (PHP `^8.3`) scaffold; existing `docs/` and `public/assets/`
  preserved untouched.
- Vue 3 + TypeScript + Vite 5 SPA under `resources/spa` (standalone, builds to
  gitignored `public/spa`).
- Tailwind with brand tokens (Plan §12.1) and exact breakpoints `md:768`,
  `lg:1025` (Plan §13); dark-mode class strategy + flash-prevention script.
- Quality tooling: Pest, Larastan level 8 (+ `NoWithoutTenancyOutsidePlatform`,
  `NoRawSqlConcat` rule placeholders for Phase 9), Pint, ESLint flat + vue-tsc,
  gitleaks pre-commit hook + `.gitleaks.toml`.
- `.github/workflows/ci.yml` — PR-stage pipeline with Postgres 16 + Redis 7
  service containers (Plan §26.2).
- `tests/Feature/SmokeTest` — `/health` 200 + app boot; all gates green.

## Open items carried forward

- `docs/brand/Logo.svg` does not exist (only `Logo.png`); needed for vector use.
- CI to be confirmed green on the first PR push.
- CVE-2026-48019 (Laravel 11 email-rule advisory) ignored with documented
  rationale — revisit at Laravel 12 upgrade / Phase 5.
