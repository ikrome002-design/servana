# UI-13 Front Office implementation checklist

## Authority and readiness

- [x] Clean synchronized `main`, safe branch and UI-12 live reconciliation proved.
- [x] Development Plan, Scope, UI/UX plan, CLAUDE §3A, brand and canonical 19-page map read.
- [x] Six required design references visually inspected; adoption/rejection notes persisted.
- [x] Exact target frozen: 17 implemented, 2 disabled_by_gate, 0 planned, 0 removed.
- [x] Permission delta frozen at zero; Front Office remains maker, Finance remains checker.
- [x] Readiness, route, gate, visual, responsive, accessibility and theme matrices pass checker.

## Implementation

- [x] Canonical host-relative routes and exact role navigation ship on `office.servana.ke`.
- [x] Dashboard, Daily Activity and maker-safe payment/receipt status use server-owned projections.
- [x] Queue Transfer uses the existing policy/Form Request/state-machine route.
- [x] Existing clients, appointments, walk-ins, queue, sessions and invoice workflows are polished.
- [x] Account composes own Magic Link identity, session, preference and branch context controls only.
- [x] Subscription Payment/Recovery and Notifications have no route, component or network runtime.
- [x] Forbidden Finance/HR/Branch/Audit controls are absent.

## Verification and release

- [x] Focused backend, store/component/router and UI-13 browser proof is green.
- [x] Seven widths, 200%, light/dark, reduced motion, keyboard, 44px and axe 0/0 are proven.
- [x] Deterministic contracts, Pint, Larastan, PostgreSQL suite and frontend gates are green under the
  documented invalidation rule: runtime 2,984 passed / 14 skipped; the four deterministic current-
  consumer failures were corrected and the complete invalidated consumer set passes 54/54.
- [x] One authoritative whole-product Playwright run and historical-evidence restoration complete.
- [x] Production images and no-volume `office.servana.ke` host proof pass.
- [x] PROGRESS, CHANGELOG, traceability, proof, defect ledger and screenshot index are final.
- [x] One commit `ui-13: implement front office experience` is the prepared local-completion commit.
- [ ] Non-draft PR, exact-head five-job CI, truthful governance, normal squash, equal-tree proof and
  local/remote branch cleanup complete.
- [x] Stop without starting UI-14.
